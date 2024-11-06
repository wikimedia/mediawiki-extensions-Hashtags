<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\SaveHooks;
use MediaWiki\Extension\Hashtags\TagCollector;
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
		$tagCollector = $this->createMock( TagCollector::class );
		$tagCollector->method( 'getTagsSeen' )->willReturn( $tags );
		return new SaveHooks( $commentParserFactory, $changeTagsStore, $dbProvider, $revisionLookup, $tagCollector );
	}

	public static function provideOnArticleRevisionVisibilitySet() {
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 0, 'newBits' => 2 ] ] ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 0, 'newBits' => 6 ] ] ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 0, 'newBits' => 3 ] ] ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 1, 'newBits' => 10 ] ] ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 0, 'newBits' => 7 ] ] ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 2, 'newBits' => 2 ] ], false ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 3, 'newBits' => 6 ] ], false ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 7, 'newBits' => 3 ] ], false ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 2, 'newBits' => 10 ] ], false ];
		yield [ [], [ 'hashtag-foo' ], [ 'redirect' ], [ '123' => [ 'oldBits' => 10, 'newBits' => 7 ] ], false ];
		yield [ [], [], [ 'redirect', 'hashtag-foo' ], [ '123' => [ 'oldBits' => 0, 'newBits' => 1 ] ], false ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 2, 'newBits' => 1 ] ] ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 2, 'newBits' => 0 ] ] ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 3, 'newBits' => 0 ] ] ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 7, 'newBits' => 4 ] ] ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 6, 'newBits' => 0 ] ] ];
		yield [ [ 'hashtag-foo', 'hashtag-bar' ], [], [], [ '125' => [ 'oldBits' => 6, 'newBits' => 2 ] ], false ];
	}

	/**
	 * @dataProvider provideOnArticleRevisionVisibilitySet
	 */
	public function testOnArticleRevisionVisibilitySet(
		$tagsToAdd, $tagsToRemove, $tagsToKeep, $visibilityMap, $doesSomething = true
	) {
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$changeTagsStore->method( 'getTagsWithData' )->willReturn(
			array_flip( array_merge( $tagsToRemove, $tagsToKeep ) )
		);
		// This test assumes we are only doing 1 change at a time.
		$id = array_keys( $visibilityMap )[0];
		$changeTagsStore->method( 'updateTags' )->willReturnCallback(
			function ( $add, $remove, $rcId, $revId, $logId ) use ( $tagsToAdd, $tagsToRemove, $id ) {
				$this->assertNull( $rcId, 'rc id is null' );
				$this->assertNull( $logId, 'log id is null' );
				$this->assertEquals( $revId, $id, 'rev id is correct' );
				$this->assertEqualsCanonicalizing( $add, $tagsToAdd, 'added tags' );
				$this->assertEqualsCanonicalizing( $remove, $tagsToRemove, 'deleted tags' );
			}
		);
		$title = $this->createMock( Title::class );
		$saveHook = $this->getHook( $tagsToAdd, $changeTagsStore );
		$saveHook->onArticleRevisionVisibilitySet( $title, [ $id ], $visibilityMap );
		// For some datasets it is expected that there will be no assertions.
		if ( !$doesSomething ) {
			$this->expectNotToPerformAssertions();
		}
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

	public function testArticleRevisionVisibilitySetThrows() {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$comment = $this->createMock( CommentStoreComment::class );
		$comment->text = 'edit summary';
		$revisionRecord->method( 'getComment' )->willReturn( $comment );
		$revisionLookup->method( 'getRevisionById' )->willReturn( null );
		$commentParserFactory = $this->createMock( HashtagCommentParserFactory::class );
		$commentParser = $this->createMock( CommentParser::class );
		$commentParserFactory->method( 'create' )->willReturn( $commentParser );
		$commentParser->method( 'preprocess' );
		$tagCollector = new TagCollector;
		$hook = new SaveHooks( $commentParserFactory, $changeTagsStore, $dbProvider, $revisionLookup, $tagCollector );

		$title = $this->createMock( Title::class );
		$ids = [ 123 ];
		$visibility = [ 123 => [ 'oldBits' => 2, 'newBits' => 0 ] ];

		$this->expectException( RuntimeException::class );
		$hook->onArticleRevisionVisibilitySet( $title, $ids, $visibility );
	}

	public function testArticleRevisionVisibilityNoComment() {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->method( 'getComment' )->willReturn( null );
		$revisionLookup->method( 'getRevisionById' )->willReturn( $revisionRecord );
		$commentParserFactory = $this->createMock( HashtagCommentParserFactory::class );
		$commentParser = $this->createMock( CommentParser::class );
		$commentParserFactory->method( 'create' )->willReturn( $commentParser );
		$commentParser->method( 'preprocess' );
		$tagCollector = new TagCollector;
		$hook = new SaveHooks( $commentParserFactory, $changeTagsStore, $dbProvider, $revisionLookup, $tagCollector );

		$title = $this->createMock( Title::class );
		$ids = [ 123 ];
		$visibility = [ 123 => [ 'oldBits' => 2, 'newBits' => 0 ] ];
		$this->expectPHPError(
			E_USER_NOTICE,
			static function () use ( $title, $ids, $visibility, $hook ) {
				$hook->onArticleRevisionVisibilitySet( $title, $ids, $visibility );
			},
			'Recently unrevdeleted comment'
		);
	}
}
