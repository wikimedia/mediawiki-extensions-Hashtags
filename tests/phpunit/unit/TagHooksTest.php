<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\Hashtags\TagHooks;

/**
 * @covers \MediaWiki\Extension\Hashtags\TagHooks
 */
class TagHooksTest extends MediaWikiUnitTestCase {

	private ChangeTagsStore $tagStore;

	protected function setUp(): void {
		$tagStore = $this->createMock( ChangeTagsStore::class );
		$tagStore->method( 'tagUsageStatistics' )
			->willReturn( [
				'foo' => 0,
				'bar' => 200,
				'baz' => 50000,
				'hashtag-empty' => 0,
				'hashtag-mytag' => 23,
				'hashtag-française' => 14, // unicode
			] );
		$this->tagStore = $tagStore;
		parent::setUp();
	}

	public static function provideHooks() {
		yield [ 'onListDefinedTags' ];
		yield [ 'onChangeTagsListActive' ];
	}

	/**
	 * @dataProvider provideHooks
	 */
	public function testHooks( $method ) {
		$hookObj = new TagHooks( $this->tagStore );
		$tags = [ 'bar', 'fred' ];
		$hookObj->$method( $tags );
		$this->assertContains( 'bar', $tags, 'bar from other extension is kept' );
		$this->assertContains( 'fred', $tags, 'fred from other extension is kept' );
		$this->assertNotContains( 'baz', $tags, 'no baz' );
		$this->assertNotContains( 'hashtag-empty', $tags, 'no empty tags added' );
		$this->assertContains( 'hashtag-mytag', $tags, 'mytag' );
		$this->assertContains( 'hashtag-française', $tags, 'unicode chars' );
	}

}
