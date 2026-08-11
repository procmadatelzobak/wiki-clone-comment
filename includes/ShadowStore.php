<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RuntimeException;
use StatusValue;

/**
 * Persists the fetched upstream article.
 *
 * The copy lives in an ordinary wiki page in the shadow namespace, holding a
 * JSON envelope. Storing it as a page rather than in a bespoke table buys, for
 * free, everything a bespoke table would have had to reimplement: it is in the
 * database dump the wiki is already backing up, it has a revision history that
 * doubles as a log of what changed upstream, it can be inspected in a browser
 * when something looks wrong, and it needs no schema migration.
 */
class ShadowStore {

	public const CURRENT_FORMAT = 1;

	/**
	 * Title of the shadow page for an upstream article.
	 *
	 * The host is deliberately not part of the title — a wiki normally clones
	 * from one upstream, and "Wikipedie:Ludwig von Mises" reads better than
	 * "Wikipedie:cs.wikipedia.org/Ludwig von Mises". The host is recorded
	 * inside the payload instead, and the sync script refuses to overwrite a
	 * page belonging to a different source rather than clobbering it.
	 *
	 * @param string $upstreamTitle
	 * @return Title
	 * @throws RuntimeException
	 */
	public static function titleFor( string $upstreamTitle ): Title {
		$title = Title::makeTitleSafe( NS_WIKICLONE, $upstreamTitle );

		if ( !$title ) {
			throw new RuntimeException( 'cannot build a shadow title for "' . $upstreamTitle . '"' );
		}

		return $title;
	}

	/**
	 * @param string $upstreamTitle
	 * @return array|null Decoded payload, or null when nothing is stored yet.
	 */
	public static function load( string $upstreamTitle ): ?array {
		try {
			$title = self::titleFor( $upstreamTitle );
		} catch ( RuntimeException $e ) {
			return null;
		}

		if ( !$title->exists() ) {
			return null;
		}

		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$content = $page->getContent();
		if ( !$content instanceof TextContent ) {
			return null;
		}

		$data = json_decode( $content->getText(), true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $upstreamTitle
	 * @param array $payload
	 * @param string $summary
	 * @return StatusValue
	 */
	public static function save( string $upstreamTitle, array $payload, string $summary ): StatusValue {
		$title = self::titleFor( $upstreamTitle );
		$services = MediaWikiServices::getInstance();

		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
		if ( $json === false ) {
			return StatusValue::newFatal( 'wikiclonecomment-error-encode' );
		}

		$content = ContentHandler::makeContent( $json, $title, CONTENT_MODEL_JSON );
		$page = $services->getWikiPageFactory()->newFromTitle( $title );

		$updater = $page->newPageUpdater( self::syncUser() );
		$updater->setContent( SlotRecord::MAIN, $content );
		// Shadow edits are machinery, not editorial activity — keeping them out
		// of Recent changes stops a nightly sync from burying human edits. The
		// shadow page's own history still records every upstream change.
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_FORCE_BOT | EDIT_SUPPRESS_RC | EDIT_INTERNAL
		);

		return $updater->getStatus() ?? StatusValue::newGood();
	}

	/**
	 * Every local page carrying a clone directive.
	 *
	 * Reads page_props, which the parser fills in as a side effect of rendering
	 * the directive — so the work list maintains itself and there is no
	 * separate registry to drift out of date.
	 *
	 * @return array[] Each entry: [ 'pageId', 'localTitle', 'source', 'upstreamTitle' ]
	 */
	public static function listClones(): array {
		$dbr = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'pp_page', 'pp_value', 'page_namespace', 'page_title' ] )
			->from( 'page_props' )
			->join( 'page', null, 'page_id = pp_page' )
			->where( [ 'pp_propname' => 'wikiclone-source' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$clones = [];
		foreach ( $rows as $row ) {
			$value = (string)$row->pp_value;
			$split = strpos( $value, '|' );
			if ( $split === false ) {
				continue;
			}

			$clones[] = [
				'pageId' => (int)$row->pp_page,
				'localTitle' => Title::makeTitle( (int)$row->page_namespace, $row->page_title ),
				'source' => substr( $value, 0, $split ),
				'upstreamTitle' => substr( $value, $split + 1 ),
			];
		}

		return $clones;
	}

	/**
	 * Drops the parser cache for a local page.
	 *
	 * Without this the article keeps serving the previous copy until something
	 * else happens to touch it: the sync writes a different page (the shadow),
	 * so nothing invalidates the article on its own.
	 *
	 * @param Title $title
	 */
	public static function purge( Title $title ): void {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$page->doPurge();
	}

	/**
	 * @return User
	 */
	public static function syncUser(): User {
		$name = (string)Hooks::config()->get( 'WikiCloneSyncUser' );

		return User::newSystemUser( $name, [ 'steal' => true ] );
	}
}
