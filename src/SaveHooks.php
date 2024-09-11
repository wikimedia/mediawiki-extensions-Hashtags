<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Hook\ManualLogEntryBeforePublishHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;

class SaveHooks implements RevisionFromEditCompleteHook, ManualLogEntryBeforePublishHook {

	private HashtagCommentParserFactory $cpFactory;

	public function __construct( CommentParserFactory $commentParserFactory ) {
		if ( !( $commentParserFactory instanceof HashtagCommentParserFactory ) ) {
			// Maybe something else wrapped our wrapper?
			// This is hacky, but should work.
			$commentParserFactory = ServicesHooks::wrapCommentParserFactory(
				$commentParserFactory,
				MediaWikiServices::getInstance()
			);
		}
		$this->cpFactory = $commentParserFactory;
	}

	// Previously we used onRecentChange_save, however onRevisionFromEditComplete
	// combined with onManualLogEntryBeforePublish seems to cover more cases.

	/**
	 * @note In certain cases, this hook ignores the $tags parameter. Most of those
	 *  cases are covered onManualLogEntryBeforePublish.
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		// FIXME, this hasn't been tested with i18n-ized edit summaries, and it is a bit
		// unclear how they work.
		$comment = $rev->getComment()->text;
		$newTags = $this->getTagsFromEditSummary( $comment );
		$tags = array_merge( $tags, $newTags );
	}

	/**
	 * @inheritDoc
	 * @note This does not cover restricted logs or log entries
	 *  not published to normal RC feed or irc RC feed
	 */
	public function onManualLogEntryBeforePublish( $logEntry ): void {
		$comment = $logEntry->getComment();
		$newTags = $this->getTagsFromEditSummary( $comment );
		// This will also add tags to the associated revision,
		// including some cases that onRevisionFromEditComplete
		// does not cover.
		$logEntry->addTags( $newTags );
	}

	private function getTagsFromEditSummary( string $summary ): array {
		$commentParser = $this->cpFactory->create();
		if ( !$commentParser instanceof HashtagCommentParser ) {
			// This should be impossible, however we are doing tricky
			// things here, so be defensive
			throw new \UnexpectedValueException( "Must be a HashtagCommentParser" );
		}

		$commentParser->preprocess( $summary );
		return $commentParser->getAllTagsSeen();
	}
}
