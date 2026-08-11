<?php
/**
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WikiCloneComment;

use Exception;

/**
 * Raised when a clone directive names something the wiki is not allowed to
 * fetch. Carries an i18n key so the parser can show the reader a real message
 * instead of a stack trace.
 */
class SourceException extends Exception {

	/** @var string */
	private $messageKey;

	/** @var array */
	private $messageParams;

	/**
	 * @param string $messageKey
	 * @param array $messageParams
	 */
	public function __construct( string $messageKey, array $messageParams = [] ) {
		parent::__construct( $messageKey );
		$this->messageKey = $messageKey;
		$this->messageParams = $messageParams;
	}

	public function getMessageKey(): string {
		return $this->messageKey;
	}

	public function getMessageParams(): array {
		return $this->messageParams;
	}
}
