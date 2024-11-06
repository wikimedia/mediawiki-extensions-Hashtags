<?php
namespace MediaWiki\Extension\Hashtags;

use IDBAccessObject;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\ManualLogEntryBeforePublishHook;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\IConnectionProvider;

class SaveHooks implements
	RevisionFromEditCompleteHook,
	ManualLogEntryBeforePublishHook,
	ArticleRevisionVisibilitySetHook
{

	private HashtagCommentParserFactory $cpFactory;
	private ChangeTagsStore $changeTagsStore;
	private IConnectionProvider $dbProvider;
	private RevisionLookup $revisionLookup;
	private TagCollector $tagCollector;

	public function __construct(
		CommentParserFactory $commentParserFactory,
		ChangeTagsStore $changeTagsStore,
		IConnectionProvider $dbProvider,
		RevisionLookup $revisionLookup,
		TagCollector $tagCollector
	) {
		$this->cpFactory = $commentParserFactory;
		$this->changeTagsStore = $changeTagsStore;
		$this->dbProvider = $dbProvider;
		$this->revisionLookup = $revisionLookup;
		$this->tagCollector = $tagCollector;
	}

	// Previously we used onRecentChange_save, however onRevisionFromEditComplete
	// combined with onManualLogEntryBeforePublish seems to cover more cases.

	/**
	 * @note In certain cases, this hook ignores the $tags parameter. Most of those
	 *  cases are covered by onManualLogEntryBeforePublish.
	 * @inheritDoc
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		// FIXME, this hasn't been tested with i18n-ized edit summaries, and it is a bit
		// unclear how they work.
		$comment = $rev->getComment()->text;
		$newTags = $this->tagCollector->getTagsSeen( $this->cpFactory, $comment );
		$tags = array_merge( $tags, $newTags );
	}

	/**
	 * @inheritDoc
	 * @note This does not cover restricted logs or log entries
	 *  not published to normal RC feed or irc RC feed
	 */
	public function onManualLogEntryBeforePublish( $logEntry ): void {
		$comment = $logEntry->getComment();
		$newTags = $this->tagCollector->getTagsSeen( $this->cpFactory, $comment );
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
				$existingTags = $this->changeTagsStore->getTagsWithData(
					$this->dbProvider->getPrimaryDatabase(),
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
				$this->changeTagsStore->updateTags( [], array_keys( $tagsToRemove ), $rcId, $id );
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
				$newTags = $this->tagCollector->getTagsSeen( $this->cpFactory, $comment->text );
				$rcId = null;
				$this->changeTagsStore->updateTags( $newTags, [], $rcId, $id );
			}
		}
	}
}
