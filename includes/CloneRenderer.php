<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use DOMDocument;
use DOMElement;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Utils\MWTimestamp;

/**
 * Assembles what the reader sees: attribution banner, the stored upstream
 * article, and this wiki's commentary placed at the headings it belongs to.
 *
 * Comments are positioned in the DOM rather than by splitting the HTML on
 * string boundaries. Heading markup varies between MediaWiki versions — some
 * wrap <h2> in <div class="mw-heading">, older ones do not — and a DOM walk
 * copes with both without the renderer having to care which one it was handed.
 */
class CloneRenderer {

	/** @var Language */
	private $lang;

	/**
	 * @param Language $lang
	 */
	public function __construct( Language $lang ) {
		$this->lang = $lang;
	}

	/**
	 * @param string $host
	 * @param string $upstreamTitle
	 * @param array[] $comments
	 * @return RenderResult
	 */
	public function render( string $host, string $upstreamTitle, array $comments ): RenderResult {
		$payload = ShadowStore::load( $upstreamTitle );

		if ( !$payload || empty( $payload['html'] ) ) {
			return new RenderResult(
				$this->banner( 'wikiclone-notice', wfMessage(
					'wikiclonecomment-not-synced',
					$upstreamTitle
				)->inLanguage( $this->lang )->parse() )
				. $this->commentSection( $comments, 'wikiclonecomment-comments-heading' ),
				false,
				true
			);
		}

		if ( ( $payload['source'] ?? $host ) !== $host ) {
			return new RenderResult(
				$this->banner( 'wikiclone-error', wfMessage(
					'wikiclonecomment-source-conflict',
					$upstreamTitle,
					(string)$payload['source'],
					$host
				)->inLanguage( $this->lang )->parse() ),
				false,
				true
			);
		}

		[ $anchored, $lead ] = $this->splitComments( $comments );

		$doc = $this->loadDocument( (string)$payload['html'] );
		$slots = [];
		$orphans = [];

		if ( $doc ) {
			$headings = $this->headingsById( $doc );

			foreach ( $anchored as $comment ) {
				$anchor = $comment['anchor'];
				if ( !isset( $headings[$anchor] ) ) {
					$orphans[] = $comment;
					continue;
				}

				$token = 'wcc-' . count( $slots );
				$slots[$token] = $this->commentBox( $comment );
				$this->insertAfterHeading( $doc, $headings[$anchor], $token );
			}

			$body = $this->serialize( $doc );
		} else {
			$body = '';
			$orphans = $anchored;
		}

		$body = preg_replace_callback(
			'/<div data-wcc-slot="([a-z0-9\-]+)"><\/div>/',
			static function ( array $m ) use ( $slots ): string {
				return $slots[$m[1]] ?? '';
			},
			$body
		);

		$html = $this->attribution( $host, $upstreamTitle, $payload );

		foreach ( $lead as $comment ) {
			$html .= $this->commentBox( $comment );
		}

		$html .= Html::rawElement( 'div', [ 'class' => 'wikiclone-body' ], $body );

		if ( $orphans ) {
			$html .= $this->banner( 'wikiclone-notice wikiclone-orphan-notice', wfMessage(
				'wikiclonecomment-orphaned-intro',
				count( $orphans )
			)->inLanguage( $this->lang )->parse() );

			foreach ( $orphans as $comment ) {
				$html .= $this->commentBox( $comment, true );
			}
		}

		return new RenderResult(
			Html::rawElement( 'div', [ 'class' => 'wikiclone' ], $html ),
			(bool)$orphans,
			false
		);
	}

	/**
	 * @param array[] $comments
	 * @return array{0:array[],1:array[]} [ anchored, unanchored ]
	 */
	private function splitComments( array $comments ): array {
		$anchored = [];
		$lead = [];

		foreach ( $comments as $comment ) {
			if ( ( $comment['anchor'] ?? '' ) === '' ) {
				$lead[] = $comment;
			} else {
				$anchored[] = $comment;
			}
		}

		return [ $anchored, $lead ];
	}

	/**
	 * @param string $html
	 * @return DOMDocument|null
	 */
	private function loadDocument( string $html ): ?DOMDocument {
		if ( trim( $html ) === '' ) {
			return null;
		}

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );

		$ok = $doc->loadHTML(
			'<!DOCTYPE html><html><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
			. '</head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $doc : null;
	}

	/**
	 * Headings that can carry an anchor, keyed by their id.
	 *
	 * @param DOMDocument $doc
	 * @return array<string,DOMElement>
	 */
	private function headingsById( DOMDocument $doc ): array {
		$found = [];

		foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $heading ) {
				$id = $heading->getAttribute( 'id' );
				if ( $id !== '' && !isset( $found[$id] ) ) {
					$found[$id] = $heading;
				}
			}
		}

		return $found;
	}

	/**
	 * Places the slot marker after the whole heading block, so the comment
	 * lands above the section's first paragraph rather than between the
	 * heading text and its own wrapper.
	 *
	 * @param DOMDocument $doc
	 * @param DOMElement $heading
	 * @param string $token
	 */
	private function insertAfterHeading( DOMDocument $doc, DOMElement $heading, string $token ): void {
		$anchorNode = $heading;
		$parent = $heading->parentNode;

		if ( $parent instanceof DOMElement
			&& str_contains( ' ' . $parent->getAttribute( 'class' ) . ' ', ' mw-heading' )
		) {
			$anchorNode = $parent;
		}

		$slot = $doc->createElement( 'div' );
		$slot->setAttribute( 'data-wcc-slot', $token );

		if ( $anchorNode->nextSibling ) {
			$anchorNode->parentNode->insertBefore( $slot, $anchorNode->nextSibling );
		} else {
			$anchorNode->parentNode->appendChild( $slot );
		}
	}

	/**
	 * @param DOMDocument $doc
	 * @return string
	 */
	private function serialize( DOMDocument $doc ): string {
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( !$body ) {
			return '';
		}

		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * @param array $comment
	 * @param bool $orphaned
	 * @return string
	 */
	private function commentBox( array $comment, bool $orphaned = false ): string {
		$label = $comment['title'] !== ''
			? $comment['title']
			: wfMessage( 'wikiclonecomment-comment-label' )->inLanguage( $this->lang )->text();

		$head = Html::element( 'div', [ 'class' => 'wikiclone-comment-label' ], $label );

		if ( $orphaned && ( $comment['anchor'] ?? '' ) !== '' ) {
			$head .= Html::element(
				'div',
				[ 'class' => 'wikiclone-comment-orphan' ],
				wfMessage( 'wikiclonecomment-orphaned-anchor' )
					->params( strtr( $comment['anchor'], '_', ' ' ) )
					->inLanguage( $this->lang )
					->text()
			);
		}

		return Html::rawElement(
			'aside',
			[
				'class' => 'wikiclone-comment' . ( $orphaned ? ' wikiclone-comment-orphaned' : '' ),
				'role' => 'note',
			],
			$head . Html::rawElement(
				'div',
				[ 'class' => 'wikiclone-comment-body' ],
				$comment['html']
			)
		);
	}

	/**
	 * @param array[] $comments
	 * @param string $headingKey
	 * @return string
	 */
	private function commentSection( array $comments, string $headingKey ): string {
		if ( !$comments ) {
			return '';
		}

		$html = '';
		foreach ( $comments as $comment ) {
			$html .= $this->commentBox( $comment );
		}

		return $html;
	}

	/**
	 * The CC BY-SA attribution block.
	 *
	 * Licence compliance needs three things and this provides all of them:
	 * the source is named, the authors are credited (the history link is the
	 * accepted shorthand for the full author list), and the reader is told
	 * plainly which part of the page is not ours.
	 *
	 * @param string $host
	 * @param string $upstreamTitle
	 * @param array $payload
	 * @return string
	 */
	private function attribution( string $host, string $upstreamTitle, array $payload ): string {
		$revid = (int)( $payload['revid'] ?? 0 );
		$source = SourceResolver::sources()[$host] ?? [];
		$siteName = $source['siteName'] ?? $host;

		$articleLink = Html::element(
			'a',
			[ 'href' => SourceResolver::articleUrl( $host, $upstreamTitle ), 'class' => 'external' ],
			$upstreamTitle
		);
		$historyLink = Html::element(
			'a',
			[ 'href' => SourceResolver::historyUrl( $host, $upstreamTitle ), 'class' => 'external' ],
			wfMessage( 'wikiclonecomment-authors' )->inLanguage( $this->lang )->text()
		);
		$revisionLink = $revid
			? Html::element(
				'a',
				[ 'href' => SourceResolver::articleUrl( $host, $upstreamTitle, $revid ), 'class' => 'external' ],
				(string)$revid
			)
			: '';

		$licence = !empty( $source['licenceUrl'] )
			? Html::element(
				'a',
				[ 'href' => $source['licenceUrl'], 'class' => 'external' ],
				$source['licence'] ?? 'CC BY-SA'
			)
			: ( $source['licence'] ?? 'CC BY-SA' );

		$text = wfMessage( 'wikiclonecomment-attribution' )
			->rawParams( $articleLink, $historyLink, $revisionLink, $licence )
			->params( $siteName, $this->formatSyncTime( $payload ) )
			->inLanguage( $this->lang )
			->parse();

		return $this->banner( 'wikiclone-attribution', $text );
	}

	/**
	 * @param array $payload
	 * @return string
	 */
	private function formatSyncTime( array $payload ): string {
		$fetched = (string)( $payload['fetched'] ?? '' );
		if ( $fetched === '' ) {
			return '';
		}

		try {
			$ts = new MWTimestamp( $fetched );
		} catch ( \Exception $e ) {
			return $fetched;
		}

		return $this->lang->timeanddate( $ts->getTimestamp( TS_MW ), false );
	}

	/**
	 * @param string $class
	 * @param string $html
	 * @return string
	 */
	private function banner( string $class, string $html ): string {
		return Html::rawElement( 'div', [ 'class' => $class ], $html );
	}
}
