<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\Hashtags\HashtagCommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\SaveHooks;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\Extension\Hashtags\SaveHooks
 */
class SaveHooksTest extends MediaWikiUnitTestCase {
	private function getHook( $tags = [], $changeTagsStore = false ) {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		if ( !$changeTagsStore ) {
			$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		}
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$comment = $this->createMock( CommentStoreComment::class );
		$comment->text = 'edit summary';
		$revisionRecord->method( 'getComment' )->willReturn( $comment );
		$revisionLookup->method( 'getRevisionById' )->willReturn( $revisionRecord );
		$commentParserFactory = $this->createMock( HashtagCommentParserFactory::class );
		$commentParser = $this->createMock( HashtagCommentParser::class );
		$commentParserFactory->method( 'create' )->willReturn( $commentParser );
		$commentParser->method( 'preprocess' );
		$commentParser->method( 'getAllTagsSeen' )->willReturn( $tags );
		return new SaveHooks( $commentParserFactory, $changeTagsStore, $dbProvider, $revisionLookup );
	}

	public function testOnRevisionFromEditComplete() {
		$wikiPage = $this->createMock( WikiPage::class );
		$comment = $this->createMock( CommentStoreComment::class );
		$comment->text = 'edit summary';
		$rev = $this->createMock( RevisionRecord::class );
		$rev->method( 'getComment' )->willReturn( $comment );
		$user = $this->createMock( User::class );
		$tags = [ 'preexisting' ];
		$saveHook = $this->getHook( [ 'hashtag-foo', 'hashtag-bár' ] );

		$saveHook->onRevisionFromEditComplete( $wikiPage, $rev, 1, $user, $tags );

		$this->assertContains( 'preexisting', $tags );
		$this->assertContains( 'hashtag-foo', $tags );
		$this->assertContains( 'hashtag-bár', $tags );
	}

	public function testOnManualLogEntryBeforePublish() {
		$logEntry = new ManualLogEntry( 'delete', 'delete' );
		$logEntry->setComment( 'edit summary' );
		$tags = [ 'hashtag-foo', 'hashtag-bár' ];
		$saveHook = $this->getHook( $tags );

		$saveHook->onManualLogEntryBeforePublish( $logEntry );

		$this->assertEqualsCanonicalizing( $logEntry->getTags(), $tags );
	}

}
