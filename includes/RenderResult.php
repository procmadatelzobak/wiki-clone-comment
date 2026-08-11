<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

/**
 * What the renderer produced, plus the two facts the hook needs in order to
 * decide which tracking categories the page belongs in.
 */
class RenderResult {

	/** @var string */
	private $html;

	/** @var bool */
	private $hasOrphans;

	/** @var bool */
	private $missing;

	/**
	 * @param string $html
	 * @param bool $hasOrphans
	 * @param bool $missing
	 */
	public function __construct( string $html, bool $hasOrphans = false, bool $missing = false ) {
		$this->html = $html;
		$this->hasOrphans = $hasOrphans;
		$this->missing = $missing;
	}

	public function getHtml(): string {
		return $this->html;
	}

	/** At least one comment lost the heading it was anchored to. */
	public function hasOrphans(): bool {
		return $this->hasOrphans;
	}

	/** Nothing has been synced for this page yet. */
	public function isMissing(): bool {
		return $this->missing;
	}
}
