<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

/**
 * Decides whether a clone page's wikitext contains anything besides the
 * directive and its comments.
 *
 * A cloned page's body comes from upstream and is never part of the page
 * source, so "the body cannot be edited" needs no lock — it only needs this:
 * a rule that free-standing prose may not be mixed in beside the comments,
 * because such prose would render *outside* the clearly-marked commentary and
 * blur the line between our words and Wikipedia's.
 */
class ContentGuard {

	/**
	 * Things that carry no visible body text and are therefore allowed to sit
	 * next to the directive: categorisation, language links, behaviour
	 * switches, and wikitext comments.
	 */
	private const HARMLESS = [
		// <!-- … -->
		'/<!--.*?-->/s',
		// <wikiclone-comment …>…</wikiclone-comment>, including the empty form
		'#<wikiclone-comment(\s[^>]*)?>.*?</wikiclone-comment\s*>#is',
		'#<wikiclone-comment(\s[^>]*)?/>#i',
		// {{#wikiclone: … }} / {{#wikiklon: … }}
		'/\{\{\s*#(?:wikiclone|wikiklon)\s*:[^{}]*\}\}/i',
		// __NOTOC__, __NOINDEX__, …
		'/__[A-Z_]+__/',
		// [[Category:X]] / [[Kategorie:X]] and interlanguage links
		'/\[\[[^\]\[|]*:[^\]\[]*\]\]/u',
	];

	/**
	 * @param string $wikitext
	 * @return string The offending text, or '' when the page is clean.
	 */
	public static function findForeignContent( string $wikitext ): string {
		$rest = $wikitext;

		foreach ( self::HARMLESS as $pattern ) {
			$rest = preg_replace( $pattern, '', $rest );
			if ( $rest === null ) {
				// A pathological page blew the PCRE backtrack limit. Fail open:
				// refusing every save would be worse than allowing this one.
				return '';
			}
		}

		return trim( $rest );
	}
}
