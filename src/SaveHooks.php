<?php
namespace MediaWiki\Extension\Hashtags;

use ChangeTags;
use IDBAccessObject;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\ManualLogEntryBeforePublishHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;

class SaveHooks implements
	RevisionFromEditCompleteHook,
	ManualLogEntryBeforePublishHook,
	ArticleRevisionVisibilitySetHook
{

	private HashtagCommentParserFactory $cpFactory;
	private RevisionLookup $revisionLookup;

	public function __construct(
		RevisionLookup $revisionLookup
	) {
		// Hack for 1.40
		$this->cpFactory = ServicesHooks::getCommentParser( MediaWikiServices::getInstance() );
		$this->revisionLookup = $revisionLookup;
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

	// FIXME: We need a solution for log entries being revdeleted/undeleted.

	/**
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ) {
		foreach ( $visibilityChangeMap as $id => $change ) {
			if (
				( $change['oldBits'] & RevisionRecord::DELETED_COMMENT ) === 0 &&
				( $change['newBits'] & RevisionRecord::DELETED_COMMENT ) !== 0
			) {
				// We are deleting this comment
				$rcId = null;
				$existingTags = ChangeTags::getTagsWithData(
					wfGetDB( DB_PRIMARY ),
					$rcId, /* rc id */
					$id /* rev id */
				);
				$tagsToRemove = array_filter(
					$existingTags,
					static function ( $key ) {
						return substr( $key, 0,
							strlen( HashtagCommentParser::HASHTAG_PREFIX )
						) === HashtagCommentParser::HASHTAG_PREFIX;
					},
					ARRAY_FILTER_USE_KEY
				);
				ChangeTags::updateTags( [], array_keys( $tagsToRemove ), $rcId, $id );
			} elseif (
				( $change['oldBits'] & RevisionRecord::DELETED_COMMENT ) !== 0 &&
				( $change['newBits'] & RevisionRecord::DELETED_COMMENT ) === 0
			) {
				// We are undeleting this comment.
				$rev = $this->revisionLookup->getRevisionById( $id, IDBAccessObject::READ_LATEST );
				if ( $rev === null ) {
					// Maybe we should just log this and silently ignore (?)
					throw new \RuntimeException( "un-revdel on revision $id that does not exist" );
				}
				$comment = $rev->getComment();
				if ( $comment === null ) {
					// It returns null if access control fails.
					// This generally should not happen.
					wfWarn( "Recently unrevdeleted comment for $id cannot be accessed" );
					return;
				}
				$newTags = $this->getTagsFromEditSummary( $comment->text );
				$rcId = null;
				ChangeTags::updateTags( $newTags, [], $rcId, $id );
			}
		}
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
