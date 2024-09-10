<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\MediaWikiServices;

class RCSaveHooks implements RecentChange_saveHook {

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

	/**
	 * @inheritDoc
	 */
	public function onRecentChange_save( $recentChange ) {
		$editSummary = $recentChange->getAttribute( 'rc_comment' );
		$tags = $this->getTagsFromEditSummary( $editSummary );
		$recentChange->addTags( $tags );
		// FIXME what about log entries that do not go to RC
		// or page moves that create empty edits in page history?
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
