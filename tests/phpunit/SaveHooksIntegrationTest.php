<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\SaveHooks;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Hashtags\SaveHooks
 */
class SaveHooksIntegrationTest extends MediaWikiIntegrationTestCase {

	public function testWrapCommentParserFactory() {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		// Note, we are sending a CommentParserFactory not a HashtagCommentParserFactory
		$commentParserFactory = $this->createMock( CommentParserFactory::class );

		$hook = TestingAccessWrapper::newFromObject(
			new SaveHooks( $commentParserFactory, $changeTagsStore, $dbProvider, $revisionLookup )
		);
		$this->assertInstanceOf( CommentParserFactory::class, $hook->cpFactory );
		$this->assertInstanceOf( HashtagCommentParserFactory::class, $hook->cpFactory );
	}
}
