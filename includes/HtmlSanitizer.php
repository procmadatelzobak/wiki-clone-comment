<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use MediaWiki\Parser\Sanitizer;

/**
 * Turns upstream article HTML into something safe to store and emit.
 *
 * Two rules govern this class:
 *
 *  1. **Allow-list, never deny-list.** Anything not named below is dropped. A
 *     deny-list is a promise to have thought of every future attack; an
 *     allow-list only promises we thought of the tags Wikipedia articles use.
 *  2. **Sanitise on the way in, not only on the way out.** The cleaned HTML is
 *     what gets stored, so a later change to the renderer cannot resurrect
 *     markup that was dangerous when it arrived.
 *
 * Links are rewritten in the same pass: an upstream article link like
 * /wiki/Praxeologie must point back at the upstream wiki, otherwise every blue
 * link on a cloned page would silently become a link to a local page that does
 * not exist — or worse, to a different local page that happens to share a name.
 */
class HtmlSanitizer {

	/** Elements kept. Everything else is unwrapped or dropped. */
	private const ALLOWED_TAGS = [
		'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption',
		'cite', 'code', 'col', 'colgroup', 'data', 'dd', 'del', 'details', 'dfn',
		'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4',
		'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p',
		'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strong',
		'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead',
		'time', 'tr', 'u', 'ul', 'var', 'wbr',
	];

	/** Elements removed together with everything inside them. */
	private const STRIP_WITH_CONTENT = [
		'script', 'style', 'iframe', 'object', 'embed', 'applet', 'form',
		'input', 'button', 'select', 'textarea', 'link', 'meta', 'base',
		'noscript', 'svg', 'math', 'audio', 'video', 'source', 'track',
		'canvas', 'template', 'slot', 'frame', 'frameset',
	];

	/** Attributes allowed on any element. */
	private const GLOBAL_ATTRS = [
		'class', 'id', 'title', 'lang', 'dir', 'style',
	];

	/** Additional attributes allowed per element. */
	private const TAG_ATTRS = [
		'a' => [ 'href', 'rel', 'hreflang' ],
		'img' => [ 'src', 'alt', 'width', 'height', 'srcset', 'sizes', 'decoding', 'loading' ],
		'td' => [ 'colspan', 'rowspan', 'headers', 'abbr', 'scope' ],
		'th' => [ 'colspan', 'rowspan', 'headers', 'abbr', 'scope' ],
		'col' => [ 'span' ],
		'colgroup' => [ 'span' ],
		'ol' => [ 'start', 'reversed', 'type' ],
		'li' => [ 'value' ],
		'time' => [ 'datetime' ],
		'data' => [ 'value' ],
		'del' => [ 'datetime', 'cite' ],
		'ins' => [ 'datetime', 'cite' ],
		'blockquote' => [ 'cite' ],
		'q' => [ 'cite' ],
		'details' => [ 'open' ],
	];

	/** Wrappers that carry no meaning for a read-only copy. */
	private const STRIP_BY_CLASS = [
		'mw-editsection',
		'mw-editsection-bracket',
		'mw-editsection-divider',
		'noprint',
		'navbox',
		'metadata',
		'ambox',
	];

	/** @var string */
	private $host;

	/**
	 * @param string $host Upstream host, used to absolutise links.
	 */
	public function __construct( string $host ) {
		$this->host = $host;
	}

	/**
	 * @param string $html Raw HTML from the upstream API.
	 * @return string Sanitised HTML, safe to store and to output unescaped.
	 */
	public function sanitize( string $html ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );

		// LIBXML_NONET keeps the parser from resolving external entities over
		// the network. The meta charset makes libxml treat the input as UTF-8
		// instead of guessing Latin-1 and mangling every Czech diacritic.
		$doc->loadHTML(
			'<!DOCTYPE html><html><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
			. '</head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( !$body ) {
			return '';
		}

		$this->clean( $body );

		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return trim( $out );
	}

	/**
	 * Depth-first walk. Children are collected up front because the loop
	 * mutates the tree underneath itself.
	 *
	 * @param DOMNode $node
	 */
	private function clean( DOMNode $node ): void {
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( $child->nodeType === XML_COMMENT_NODE ) {
				$child->parentNode->removeChild( $child );
				continue;
			}

			if ( !$child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, self::STRIP_WITH_CONTENT, true )
				|| $this->hasStrippedClass( $child )
			) {
				$child->parentNode->removeChild( $child );
				continue;
			}

			if ( !in_array( $tag, self::ALLOWED_TAGS, true ) ) {
				// Unknown but harmless-looking element: keep the text, lose the
				// element. Dropping the subtree would silently eat article prose.
				$this->clean( $child );
				$this->unwrap( $child );
				continue;
			}

			$this->cleanAttributes( $child, $tag );
			$this->clean( $child );
		}
	}

	/**
	 * @param DOMElement $el
	 * @param string $tag
	 */
	private function cleanAttributes( DOMElement $el, string $tag ): void {
		$allowed = array_merge( self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? [] );

		/** @var DOMAttr[] $attributes */
		$attributes = [];
		foreach ( $el->attributes as $attr ) {
			$attributes[] = $attr;
		}

		foreach ( $attributes as $attr ) {
			$name = strtolower( $attr->nodeName );

			if ( !in_array( $name, $allowed, true ) ) {
				$el->removeAttribute( $attr->nodeName );
				continue;
			}

			$value = $attr->nodeValue;

			if ( $name === 'style' ) {
				// checkCss strips url(), expression() and friends.
				$safe = Sanitizer::checkCss( $value );
				if ( trim( $safe ) === '' ) {
					$el->removeAttribute( 'style' );
				} else {
					$el->setAttribute( 'style', $safe );
				}
				continue;
			}

			if ( $name === 'href' ) {
				$rewritten = $this->rewriteHref( $value );
				if ( $rewritten === null ) {
					$el->removeAttribute( 'href' );
				} else {
					$el->setAttribute( 'href', $rewritten );
				}
				continue;
			}

			if ( $name === 'src' || $name === 'srcset' ) {
				$rewritten = $name === 'src'
					? $this->rewriteResource( $value )
					: $this->rewriteSrcset( $value );
				if ( $rewritten === null ) {
					$el->removeAttribute( $name );
				} else {
					$el->setAttribute( $name, $rewritten );
				}
			}
		}
	}

	/**
	 * @param string $href
	 * @return string|null Null means: drop the attribute.
	 */
	private function rewriteHref( string $href ): ?string {
		$href = trim( $href );

		if ( $href === '' ) {
			return null;
		}

		// In-page anchors (footnotes, back-references) must stay relative so
		// they keep jumping inside the cloned article.
		if ( $href[0] === '#' ) {
			return $href;
		}

		if ( preg_match( '#^(https?:)?//#i', $href ) ) {
			return preg_replace( '#^//#', 'https://', $href );
		}

		// Anything with a scheme we did not just allow (javascript:, data:,
		// vbscript:, …) is refused outright.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			return null;
		}

		if ( $href[0] === '/' ) {
			return 'https://' . $this->host . $href;
		}

		// Parsoid-style "./Page_title".
		if ( str_starts_with( $href, './' ) ) {
			return 'https://' . $this->host . '/wiki/' . substr( $href, 2 );
		}

		return 'https://' . $this->host . '/wiki/' . $href;
	}

	/**
	 * @param string $src
	 * @return string|null
	 */
	private function rewriteResource( string $src ): ?string {
		$src = trim( $src );

		if ( $src === '' ) {
			return null;
		}
		if ( str_starts_with( $src, '//' ) ) {
			return 'https:' . $src;
		}
		if ( preg_match( '#^https?://#i', $src ) ) {
			return $src;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $src ) ) {
			// Includes data: URIs — an image payload we did not fetch and
			// cannot vouch for has no business in stored content.
			return null;
		}
		if ( $src[0] === '/' ) {
			return 'https://' . $this->host . $src;
		}

		return null;
	}

	/**
	 * @param string $srcset
	 * @return string|null
	 */
	private function rewriteSrcset( string $srcset ): ?string {
		$parts = [];

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( $candidate === '' ) {
				continue;
			}

			$bits = preg_split( '/\s+/', $candidate, 2 );
			$url = $this->rewriteResource( $bits[0] );
			if ( $url === null ) {
				continue;
			}

			$parts[] = isset( $bits[1] ) ? $url . ' ' . $bits[1] : $url;
		}

		return $parts ? implode( ', ', $parts ) : null;
	}

	/**
	 * @param DOMElement $el
	 * @return bool
	 */
	private function hasStrippedClass( DOMElement $el ): bool {
		$class = $el->getAttribute( 'class' );
		if ( $class === '' ) {
			return false;
		}

		$classes = preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY );

		return (bool)array_intersect( $classes, self::STRIP_BY_CLASS );
	}

	/**
	 * Replaces an element with its children.
	 *
	 * @param DOMElement $el
	 */
	private function unwrap( DOMElement $el ): void {
		$parent = $el->parentNode;
		if ( !$parent ) {
			return;
		}

		while ( $el->firstChild ) {
			$parent->insertBefore( $el->firstChild, $el );
		}
		$parent->removeChild( $el );
	}
}
