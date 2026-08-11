<?php
/**
 * Hook handlers for the WikiCloneComment extension.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use MediaWiki\Content\Content;
use MediaWiki\Content\TextContent;
use MediaWiki\Context\IContextSource;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Hook\ParserAfterTidyHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Status\Status;
use MediaWiki\User\User;

class Hooks implements
	ParserFirstCallInitHook,
	ParserAfterTidyHook,
	EditFilterMergedContentHook
{
	/** Key under which the pending render job is stashed on the ParserOutput. */
	private const DATA_KEY = 'wikiclonecomment';

	/**
	 * Applied while the extension registry is being processed, which happens
	 * *after* LocalSettings.php has run.
	 *
	 * Both settings below have to live here rather than in a wiki's
	 * LocalSettings.php, because NS_WIKICLONE is defined by this very
	 * registration step — referring to the constant any earlier is a fatal.
	 */
	public static function onRegistration(): void {
		global $wgWikiCloneNamespaceName, $wgWikiCloneSearchByDefault,
			$wgExtraNamespaces, $wgNamespacesToBeSearchedDefault;

		$name = $wgWikiCloneNamespaceName ?? null;
		if ( is_string( $name ) && $name !== '' ) {
			$name = strtr( trim( $name ), ' ', '_' );
			$wgExtraNamespaces[3000] = $name;
			$wgExtraNamespaces[3001] = $name . '_talk';
		}

		// The namespace is non-content on purpose, so cloned pages cannot
		// inflate {{NUMBEROFARTICLES}} — but non-content also means "not
		// searched by default", which would hide every clone from the wiki's
		// own search while leaving it visible to Google.
		if ( $wgWikiCloneSearchByDefault ?? true ) {
			$wgNamespacesToBeSearchedDefault[3000] = true;
		}
	}

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook(
			'wikiclone',
			[ $this, 'renderDirective' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setHook( 'wikiclone-comment', [ $this, 'renderComment' ] );
	}

	/**
	 * Handles {{#wikiclone: <page title or upstream URL> }}.
	 *
	 * Nothing is rendered here. The directive only records what the page wants
	 * and leaves a strip marker behind; the assembled article is spliced in at
	 * ParserAfterTidy, once every <wikiclone-comment> on the page has been seen.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return string|array
	 */
	public function renderDirective( Parser $parser, PPFrame $frame, array $args ) {
		$out = $parser->getOutput();
		$raw = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : '';

		$out->addModuleStyles( [ 'ext.wikiCloneComment.styles' ] );

		if ( $raw === '' ) {
			return $this->fatal( $parser, 'wikiclonecomment-error-no-target' );
		}

		try {
			[ $sourceHost, $title ] = SourceResolver::resolve( $raw );
		} catch ( SourceException $e ) {
			return $this->fatal( $parser, $e->getMessageKey(), $e->getMessageParams() );
		}

		$data = $out->getExtensionData( self::DATA_KEY ) ?? [];
		if ( isset( $data['source'] ) ) {
			// Two directives on one page would each want to own the body.
			return $this->fatal( $parser, 'wikiclonecomment-error-duplicate' );
		}

		$token = 'WIKICLONESLOT-' . wfRandomString( 16 );

		$data['source'] = $sourceHost;
		$data['title'] = $title;
		$data['token'] = $token;
		$out->setExtensionData( self::DATA_KEY, $data );

		// page_props: this is what the sync script queries to find its work.
		$out->setPageProperty( 'wikiclone-source', $sourceHost . '|' . $title );
		$parser->addTrackingCategory( 'wikiclonecomment-tracking-category' );

		return [ $parser->insertStripItem( $token ), 'noparse' => true, 'isHTML' => false ];
	}

	/**
	 * Handles <wikiclone-comment anchor="…">…</wikiclone-comment>.
	 *
	 * The body is parsed straight away (so templates and links inside a comment
	 * behave normally) and parked; placement happens in the renderer.
	 *
	 * @param string|null $text
	 * @param array $attribs
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public function renderComment( ?string $text, array $attribs, Parser $parser, PPFrame $frame ): string {
		$out = $parser->getOutput();
		$out->addModuleStyles( [ 'ext.wikiCloneComment.styles' ] );

		$data = $out->getExtensionData( self::DATA_KEY ) ?? [];
		$data['comments'] ??= [];

		$anchor = isset( $attribs['anchor'] ) ? trim( (string)$attribs['anchor'] ) : '';
		// Wikipedia anchors use underscores; accept the spaced form people copy
		// out of a heading and normalise it rather than orphaning the comment.
		$anchor = strtr( $anchor, ' ', '_' );

		$data['comments'][] = [
			'anchor' => $anchor,
			'title' => isset( $attribs['title'] ) ? trim( (string)$attribs['title'] ) : '',
			'html' => $parser->recursiveTagParse( (string)$text, $frame ),
		];
		$out->setExtensionData( self::DATA_KEY, $data );

		return '';
	}

	/**
	 * Splices the stored upstream article, the attribution banner and the
	 * comments into the finished page.
	 *
	 * @param Parser $parser
	 * @param string &$text
	 */
	public function onParserAfterTidy( $parser, &$text ) {
		$out = $parser->getOutput();
		$data = $out->getExtensionData( self::DATA_KEY );

		if ( !is_array( $data ) || !isset( $data['token'] ) ) {
			return;
		}
		if ( !str_contains( $text, $data['token'] ) ) {
			// The directive was transcluded somewhere its output got dropped.
			return;
		}

		$renderer = new CloneRenderer( $parser->getTargetLanguage() );
		$result = $renderer->render(
			$data['source'],
			$data['title'],
			$data['comments'] ?? []
		);

		if ( $result->hasOrphans() ) {
			$parser->addTrackingCategory( 'wikiclonecomment-orphaned-category' );
		}
		if ( $result->isMissing() ) {
			$parser->addTrackingCategory( 'wikiclonecomment-error-category' );
		}

		$text = str_replace( $data['token'], $result->getHtml(), $text );
	}

	/**
	 * A page carrying the directive may hold nothing but the directive and its
	 * comments. This is the whole of the "the body cannot be edited" rule: the
	 * upstream text never lives in the page source, so there is nothing to
	 * protect — we only have to stop free text being mixed in beside it.
	 *
	 * Pre-existing content is never migrated automatically. The save is refused,
	 * the old revision stays in the history, and the author moves what they want
	 * to keep into a comment by hand.
	 *
	 * @param IContextSource $context
	 * @param Content $content
	 * @param Status $status
	 * @param string $summary
	 * @param User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	public function onEditFilterMergedContent(
		IContextSource $context, Content $content, Status $status, $summary, User $user, $minoredit
	) {
		if ( !$content instanceof TextContent
			|| $content->getModel() !== CONTENT_MODEL_WIKITEXT
		) {
			return true;
		}

		$wikitext = $content->getText();
		if ( !preg_match( '/\{\{\s*#(wikiclone|wikiklon)\s*:/i', $wikitext ) ) {
			return true;
		}

		$leftovers = ContentGuard::findForeignContent( $wikitext );
		if ( $leftovers === '' ) {
			return true;
		}

		$status->fatal(
			'wikiclonecomment-error-foreign-content',
			$context->getLanguage()->truncateForVisual( $leftovers, 120 )
		);
		$status->value = EditPage::AS_HOOK_ERROR_EXPECTED;

		return false;
	}

	/**
	 * @param Parser $parser
	 * @param string $key
	 * @param array $params
	 * @return array
	 */
	private function fatal( Parser $parser, string $key, array $params = [] ): array {
		$parser->addTrackingCategory( 'wikiclonecomment-error-category' );

		$html = Html::rawElement(
			'div',
			[ 'class' => 'wikiclone-error' ],
			$parser->msg( $key, ...$params )->parse()
		);

		return [ $html, 'isHTML' => true ];
	}

	/**
	 * Convenience accessor used across the extension.
	 *
	 * @return \MediaWiki\Config\Config
	 */
	public static function config() {
		return MediaWikiServices::getInstance()->getMainConfig();
	}
}
