<?php
/**
 * Refreshes every cloned article from its upstream wiki.
 *
 * Run it from cron or a systemd timer, once a day is plenty:
 *
 *   php extensions/WikiCloneComment/maintenance/syncWikiClone.php --all
 *
 * @file
 * @ingroup Maintenance
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment\Maintenance;

use MediaWiki\Extension\WikiCloneComment\HtmlSanitizer;
use MediaWiki\Extension\WikiCloneComment\ShadowStore;
use MediaWiki\Extension\WikiCloneComment\SourceFetcher;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Utils\MWTimestamp;
use Throwable;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class SyncWikiClone extends Maintenance {

	/** @var int */
	private $fetched = 0;

	/** @var int */
	private $unchanged = 0;

	/** @var int */
	private $failed = 0;

	/** @var int */
	private $missing = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Refresh cloned articles from their upstream wiki. Only articles whose '
			. 'upstream revision actually moved are downloaded.'
		);
		$this->addOption( 'all', 'Process every cloned page (default when --page is absent).' );
		$this->addOption( 'page', 'Upstream title of a single article to sync.', false, true );
		$this->addOption( 'force', 'Download even when the upstream revision is unchanged.' );
		$this->addOption( 'max', 'Stop after this many downloads.', false, true );
		$this->addOption( 'dry-run', 'Report what would happen, write nothing.' );
		$this->requireExtension( 'WikiCloneComment' );
	}

	public function execute() {
		$dry = $this->hasOption( 'dry-run' );
		$force = $this->hasOption( 'force' );
		$max = (int)$this->getOption( 'max', 0 );

		if ( !SourceFetcher::hasContact() ) {
			// Wikimedia's User-Agent policy wants a way to reach whoever is
			// making the requests. Sending unattributable traffic to someone
			// else's servers is not a thing this script will do quietly.
			$this->fatalError(
				"\$wgWikiCloneContact is empty.\n"
				. "Set it to an e-mail address or a page URL where the operator of this\n"
				. "wiki can be reached, then run this again."
			);
		}

		$clones = ShadowStore::listClones();
		if ( !$clones ) {
			$this->output( "No cloned pages found. Nothing to do.\n" );
			return;
		}

		$single = $this->getOption( 'page' );
		if ( $single !== null ) {
			$wanted = strtr( trim( $single ), '_', ' ' );
			$clones = array_values( array_filter(
				$clones,
				static fn ( array $c ) => $c['upstreamTitle'] === $wanted
			) );

			if ( !$clones ) {
				$this->fatalError( "No page clones \"$wanted\"." );
			}
		}

		// One upstream at a time, so revision lookups batch cleanly.
		$bySource = [];
		foreach ( $clones as $clone ) {
			$bySource[$clone['source']][] = $clone;
		}

		foreach ( $bySource as $host => $group ) {
			$this->syncSource( (string)$host, $group, $force, $dry, $max );
		}

		$this->output( sprintf(
			"\n%s: %d downloaded, %d unchanged, %d missing upstream, %d failed.\n",
			$dry ? 'Dry run' : 'Done',
			$this->fetched,
			$this->unchanged,
			$this->missing,
			$this->failed
		) );

		if ( $this->failed ) {
			// A non-zero exit is what makes a silent failure visible to the
			// timer that runs this.
			$this->fatalError( 'Some articles could not be synced; see above.', 1 );
		}
	}

	/**
	 * @param string $host
	 * @param array[] $clones
	 * @param bool $force
	 * @param bool $dry
	 * @param int $max
	 */
	private function syncSource( string $host, array $clones, bool $force, bool $dry, int $max ): void {
		try {
			$fetcher = new SourceFetcher( $host );
		} catch ( Throwable $e ) {
			$this->error( "  ! $host is not an allowed source: " . $e->getMessage() );
			$this->failed += count( $clones );
			return;
		}

		// Several local pages may clone the same upstream article.
		$byTitle = [];
		foreach ( $clones as $clone ) {
			$byTitle[$clone['upstreamTitle']][] = $clone;
		}

		$this->output( "$host: " . count( $byTitle ) . " article(s)\n" );

		try {
			$revisions = $fetcher->fetchRevisionIds( array_keys( $byTitle ) );
		} catch ( Throwable $e ) {
			$this->error( '  ! revision lookup failed: ' . $e->getMessage() );
			$this->failed += count( $byTitle );
			return;
		}

		$sanitizer = new HtmlSanitizer( $host );

		foreach ( $byTitle as $title => $pages ) {
			if ( $max > 0 && $this->fetched >= $max ) {
				$this->output( "  (stopping, --max reached)\n" );
				return;
			}

			$upstreamRev = $revisions[$title] ?? null;
			if ( $upstreamRev === null ) {
				$this->error( "  ? $title — no such page upstream" );
				$this->missing++;
				continue;
			}

			$stored = ShadowStore::load( $title );
			$storedRev = (int)( $stored['revid'] ?? 0 );

			if ( !$force && $storedRev === $upstreamRev ) {
				$this->unchanged++;
				continue;
			}

			if ( $stored && ( $stored['source'] ?? $host ) !== $host ) {
				$this->error(
					"  ! $title — shadow page belongs to {$stored['source']}, refusing to overwrite"
				);
				$this->failed++;
				continue;
			}

			if ( $dry ) {
				$this->output( "  → $title (rev $storedRev → $upstreamRev)\n" );
				$this->fetched++;
				continue;
			}

			try {
				$this->store( $fetcher, $sanitizer, $host, (string)$title, $stored, $pages );
			} catch ( Throwable $e ) {
				$this->error( "  ! $title — " . $e->getMessage() );
				$this->failed++;
			}
		}
	}

	/**
	 * @param SourceFetcher $fetcher
	 * @param HtmlSanitizer $sanitizer
	 * @param string $host
	 * @param string $title
	 * @param array|null $stored
	 * @param array[] $pages
	 */
	private function store(
		SourceFetcher $fetcher,
		HtmlSanitizer $sanitizer,
		string $host,
		string $title,
		?array $stored,
		array $pages
	): void {
		$article = $fetcher->fetchArticle( $title );

		$payload = [
			'format' => ShadowStore::CURRENT_FORMAT,
			'source' => $host,
			'title' => $article['title'],
			'displaytitle' => $article['displaytitle'],
			'revid' => $article['revid'],
			'fetched' => MWTimestamp::now( TS_ISO_8601 ),
			'sections' => $article['sections'],
			'html' => $sanitizer->sanitize( $article['html'] ),
		];

		$status = ShadowStore::save(
			$title,
			$payload,
			'Synced from ' . $host . ' revision ' . $article['revid']
		);

		if ( !$status->isOK() ) {
			throw new \RuntimeException( $status->getWikiText( false, false, 'en' ) );
		}

		$lost = $this->lostAnchors( $stored, $article['sections'] );
		$note = $lost ? '  (headings gone: ' . implode( ', ', $lost ) . ')' : '';

		$this->output( "  ✓ $title → rev {$article['revid']}$note\n" );
		$this->fetched++;

		// The sync writes the shadow page; nothing would otherwise tell the
		// article using it that its parser cache is stale.
		foreach ( $pages as $page ) {
			ShadowStore::purge( $page['localTitle'] );
		}
	}

	/**
	 * Anchors that existed in the previous copy and no longer do.
	 *
	 * Comments pinned to them will still render — the renderer moves them to
	 * the bottom of the page and flags them — but naming the headings here is
	 * what turns "a comment went astray" into something an operator reading
	 * the sync log can act on.
	 *
	 * @param array|null $stored
	 * @param array[] $sections
	 * @return string[]
	 */
	private function lostAnchors( ?array $stored, array $sections ): array {
		if ( !$stored || empty( $stored['sections'] ) ) {
			return [];
		}

		$before = array_column( $stored['sections'], 'anchor' );
		$after = array_column( $sections, 'anchor' );

		return array_slice( array_values( array_diff( $before, $after ) ), 0, 8 );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SyncWikiClone::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
