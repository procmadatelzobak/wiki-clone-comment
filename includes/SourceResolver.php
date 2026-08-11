<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

/**
 * Turns whatever an editor typed into a (host, page title) pair, refusing
 * anything that is not on the configured allow-list.
 *
 * This is the extension's SSRF boundary. Editors can paste a URL — which is the
 * natural thing to do — but the host is checked against $wgWikiCloneSources
 * before it is ever handed to the fetcher, so no edit can steer a request at an
 * arbitrary address, internal ones included.
 */
class SourceResolver {

	/**
	 * @param string $raw Page title, or a full upstream article URL.
	 * @return array{0:string,1:string} [ host, page title ]
	 * @throws SourceException
	 */
	public static function resolve( string $raw ): array {
		$raw = trim( $raw );
		$sources = self::sources();

		if ( !$sources ) {
			throw new SourceException( 'wikiclonecomment-error-no-sources' );
		}

		if ( preg_match( '#^https?://#i', $raw ) ) {
			[ $host, $title ] = self::fromUrl( $raw );
		} else {
			$host = self::defaultSource();
			$title = $raw;
		}

		$host = strtolower( $host );
		if ( !isset( $sources[$host] ) ) {
			throw new SourceException(
				'wikiclonecomment-error-source-not-allowed',
				[ $host, implode( ', ', array_keys( $sources ) ) ]
			);
		}

		$title = self::normaliseTitle( $title );
		if ( $title === '' ) {
			throw new SourceException( 'wikiclonecomment-error-no-target' );
		}

		return [ $host, $title ];
	}

	/**
	 * @param string $url
	 * @return array{0:string,1:string}
	 * @throws SourceException
	 */
	private static function fromUrl( string $url ): array {
		$parts = parse_url( $url );
		if ( !$parts || empty( $parts['host'] ) ) {
			throw new SourceException( 'wikiclonecomment-error-bad-url', [ $url ] );
		}

		$host = $parts['host'];
		$path = $parts['path'] ?? '';
		$title = '';

		// …/wiki/Page_title
		if ( preg_match( '#/wiki/(.+)$#', $path, $m ) ) {
			$title = rawurldecode( $m[1] );
		} elseif ( !empty( $parts['query'] ) ) {
			// …/w/index.php?title=Page_title
			parse_str( $parts['query'], $query );
			if ( isset( $query['title'] ) ) {
				$title = (string)$query['title'];
			}
		}

		if ( $title === '' ) {
			throw new SourceException( 'wikiclonecomment-error-bad-url', [ $url ] );
		}

		// A fragment addresses a section of the upstream page; we always clone
		// the whole article, so drop it rather than baking it into the title.
		$title = explode( '#', $title )[0];

		return [ $host, $title ];
	}

	/**
	 * Underscores and spaces are interchangeable in MediaWiki titles; the API
	 * normalises them anyway, this just keeps stored keys stable.
	 *
	 * @param string $title
	 * @return string
	 */
	private static function normaliseTitle( string $title ): string {
		$title = rawurldecode( $title );
		$title = strtr( $title, '_', ' ' );
		$title = preg_replace( '/\s+/u', ' ', $title );

		return trim( $title );
	}

	/**
	 * @return array<string,array>
	 */
	public static function sources(): array {
		$sources = Hooks::config()->get( 'WikiCloneSources' );

		return is_array( $sources ) ? $sources : [];
	}

	/**
	 * @param string $host
	 * @return array
	 * @throws SourceException
	 */
	public static function source( string $host ): array {
		$sources = self::sources();
		if ( !isset( $sources[$host] ) ) {
			throw new SourceException( 'wikiclonecomment-error-source-not-allowed', [ $host, '' ] );
		}

		return $sources[$host];
	}

	/**
	 * @return string
	 * @throws SourceException
	 */
	public static function defaultSource(): string {
		$configured = Hooks::config()->get( 'WikiCloneDefaultSource' );
		if ( is_string( $configured ) && $configured !== '' ) {
			return $configured;
		}

		$sources = self::sources();
		if ( count( $sources ) === 1 ) {
			return (string)array_key_first( $sources );
		}

		// Several sources and no default: the directive has to say which one.
		throw new SourceException( 'wikiclonecomment-error-ambiguous-source' );
	}

	/**
	 * Human-facing URL of the upstream article.
	 *
	 * @param string $host
	 * @param string $title
	 * @param int|null $revid Link a specific revision when known.
	 * @return string
	 */
	public static function articleUrl( string $host, string $title, ?int $revid = null ): string {
		try {
			$source = self::source( $host );
		} catch ( SourceException $e ) {
			return '';
		}

		if ( $revid !== null && !empty( $source['api'] ) ) {
			return preg_replace( '#/api\.php$#', '/index.php', $source['api'] )
				. '?oldid=' . $revid;
		}

		$pattern = $source['articleUrl'] ?? ( 'https://' . $host . '/wiki/$1' );

		return str_replace( '$1', wfUrlencode( strtr( $title, ' ', '_' ) ), $pattern );
	}

	/**
	 * URL of the upstream page history — the attribution link that CC BY-SA
	 * requires, since it names every author of the text we are reusing.
	 *
	 * @param string $host
	 * @param string $title
	 * @return string
	 */
	public static function historyUrl( string $host, string $title ): string {
		try {
			$source = self::source( $host );
		} catch ( SourceException $e ) {
			return '';
		}

		$index = preg_replace( '#/api\.php$#', '/index.php', $source['api'] ?? '' );
		if ( !$index ) {
			return '';
		}

		return $index . '?title=' . wfUrlencode( strtr( $title, ' ', '_' ) ) . '&action=history';
	}
}
