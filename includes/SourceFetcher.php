<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use RuntimeException;

/**
 * Talks to an upstream MediaWiki API.
 *
 * Two calls matter:
 *
 *   fetchRevisionIds()  cheap, batched — "has anything changed?"
 *   fetchArticle()      expensive — only run for pages whose revision moved
 *
 * That split is what keeps a daily sync of a few hundred articles down to a
 * handful of requests on a normal day.
 */
class SourceFetcher {

	/** Titles per query. The API caps anonymous callers at 50. */
	public const BATCH = 50;

	/** @var string */
	private $host;

	/** @var array */
	private $source;

	/**
	 * @param string $host
	 * @throws SourceException
	 */
	public function __construct( string $host ) {
		$this->host = $host;
		$this->source = SourceResolver::source( $host );
	}

	/**
	 * Current revision id of each title.
	 *
	 * @param string[] $titles
	 * @return array<string,int|null> Requested title => revision id (null when missing upstream)
	 */
	public function fetchRevisionIds( array $titles ): array {
		$result = array_fill_keys( $titles, null );

		foreach ( array_chunk( $titles, self::BATCH ) as $chunk ) {
			$data = $this->request( [
				'action' => 'query',
				'prop' => 'revisions',
				'rvprop' => 'ids',
				'titles' => implode( '|', $chunk ),
				'redirects' => '1',
			] );

			$alias = $this->titleAliases( $data['query'] ?? [] );

			foreach ( $data['query']['pages'] ?? [] as $page ) {
				if ( !empty( $page['missing'] ) ) {
					continue;
				}
				$revid = $page['revisions'][0]['revid'] ?? null;
				if ( $revid === null ) {
					continue;
				}
				foreach ( $this->requestedNamesFor( (string)$page['title'], $chunk, $alias ) as $name ) {
					$result[$name] = (int)$revid;
				}
			}
		}

		return $result;
	}

	/**
	 * Rendered article plus the canonical anchor list.
	 *
	 * @param string $title
	 * @return array{revid:int,html:string,sections:array,displaytitle:string,title:string}
	 * @throws RuntimeException when the page does not exist upstream
	 */
	public function fetchArticle( string $title ): array {
		$data = $this->request( [
			'action' => 'parse',
			'page' => $title,
			'prop' => 'text|revid|sections|displaytitle',
			'redirects' => '1',
		] );

		if ( isset( $data['error'] ) ) {
			throw new RuntimeException(
				'upstream API error for "' . $title . '": ' . ( $data['error']['code'] ?? '?' )
			);
		}

		$parse = $data['parse'] ?? null;
		if ( !$parse || !isset( $parse['text'] ) ) {
			throw new RuntimeException( 'no parse result for "' . $title . '"' );
		}

		$sections = [];
		foreach ( $parse['sections'] ?? [] as $section ) {
			if ( ( $section['anchor'] ?? '' ) === '' ) {
				continue;
			}
			$sections[] = [
				'anchor' => (string)$section['anchor'],
				'level' => (int)( $section['level'] ?? 2 ),
				'line' => html_entity_decode(
					strip_tags( (string)( $section['line'] ?? '' ) ),
					ENT_QUOTES,
					'UTF-8'
				),
			];
		}

		return [
			'revid' => (int)( $parse['revid'] ?? 0 ),
			'title' => (string)( $parse['title'] ?? $title ),
			'displaytitle' => (string)( $parse['displaytitle'] ?? $parse['title'] ?? $title ),
			'sections' => $sections,
			'html' => (string)$parse['text'],
		];
	}

	/**
	 * @param array $params
	 * @return array
	 * @throws RuntimeException
	 */
	private function request( array $params ): array {
		$config = Hooks::config();
		$params += [
			'format' => 'json',
			'formatversion' => '2',
			'maxlag' => (string)$config->get( 'WikiCloneMaxLag' ),
		];

		$url = $this->source['api'] . '?' . http_build_query( $params );
		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();

		// One retry is enough: maxlag rejections clear in seconds, and a second
		// failure means the upstream is genuinely unwell — better to fail the
		// sync loudly than to hammer someone else's servers.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$request = $http->create( $url, [
				'method' => 'GET',
				'timeout' => (int)$config->get( 'WikiCloneRequestTimeout' ),
				'followRedirects' => true,
				'userAgent' => self::userAgent(),
			], __METHOD__ );

			$status = $request->execute();
			$body = $request->getContent();

			if ( $status->isOK() ) {
				$data = json_decode( $body, true );
				if ( !is_array( $data ) ) {
					throw new RuntimeException( 'upstream returned invalid JSON' );
				}
				if ( ( $data['error']['code'] ?? '' ) === 'maxlag' && $attempt === 1 ) {
					sleep( min( 10, (int)$request->getResponseHeader( 'Retry-After' ) ?: 5 ) );
					continue;
				}

				return $data;
			}

			if ( $attempt === 2 ) {
				throw new RuntimeException(
					'HTTP request to ' . $this->host . ' failed: '
					. Status::wrap( $status )->getWikiText( false, false, 'en' )
				);
			}
			sleep( 3 );
		}

		throw new RuntimeException( 'unreachable' );
	}

	/**
	 * Wikimedia's User-Agent policy asks for an identifiable agent with a way
	 * to reach the operator; anonymous or browser-spoofing agents get blocked.
	 * See https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy
	 *
	 * @return string
	 */
	public static function userAgent(): string {
		$contact = trim( (string)Hooks::config()->get( 'WikiCloneContact' ) );
		$server = (string)Hooks::config()->get( 'Server' );

		$who = $contact !== '' ? $contact : $server;

		return 'WikiCloneComment/0.1.0 (' . $who . ') MediaWiki/' . MW_VERSION;
	}

	/**
	 * The extension refuses to sync without a contact address rather than
	 * quietly sending traffic that upstream operators cannot trace back.
	 *
	 * @return bool
	 */
	public static function hasContact(): bool {
		return trim( (string)Hooks::config()->get( 'WikiCloneContact' ) ) !== '';
	}

	/**
	 * Maps the titles we asked for onto the titles the API answered with, after
	 * normalisation and redirect resolution.
	 *
	 * @param array $query
	 * @return array<string,string> requested => resolved
	 */
	private function titleAliases( array $query ): array {
		$alias = [];

		foreach ( $query['normalized'] ?? [] as $entry ) {
			$alias[$entry['from']] = $entry['to'];
		}
		foreach ( $query['redirects'] ?? [] as $entry ) {
			$from = $entry['from'];
			$to = $entry['to'];
			$alias[$from] = $to;
			// A normalised title may in turn have been a redirect.
			foreach ( $alias as $orig => $mapped ) {
				if ( $mapped === $from ) {
					$alias[$orig] = $to;
				}
			}
		}

		return $alias;
	}

	/**
	 * @param string $resolved
	 * @param string[] $requested
	 * @param array<string,string> $alias
	 * @return string[]
	 */
	private function requestedNamesFor( string $resolved, array $requested, array $alias ): array {
		$names = [];

		foreach ( $requested as $name ) {
			if ( $name === $resolved || ( $alias[$name] ?? null ) === $resolved ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
