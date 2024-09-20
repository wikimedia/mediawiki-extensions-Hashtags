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
		$config = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => true,
			'HashtagsRequireActiveTag' => false
		] );
		$hookObj = new TagHooks( $this->tagStore, $config );
		$tags = [ 'bar', 'fred' ];
		$hookObj->$method( $tags );
		$this->assertContains( 'bar', $tags, 'bar from other extension is kept' );
		$this->assertContains( 'fred', $tags, 'fred from other extension is kept' );
		$this->assertNotContains( 'baz', $tags, 'no baz' );
		$this->assertNotContains( 'hashtag-empty', $tags, 'no empty tags added' );
		$this->assertContains( 'hashtag-mytag', $tags, 'mytag' );
		$this->assertContains( 'hashtag-française', $tags, 'unicode chars' );
	}

	public static function provideOnChangeTagCanCreate() {
		yield [ 'foo', true ];
		yield [ 'bar', true ];
		yield [ 'hashta-nope', true ];
		yield [ 'shashtag-nope', true ];
		yield [ 'hashtag-fred', false ];
		yield [ 'hashtag-française', false ];
		yield [ 'hashTag-fred', true ];
		yield [ 'hashtag', true ];
		yield [ 'hashtagfoo', true ];
	}

	/**
	 * @dataProvider provideOnChangeTagCanCreate
	 */
	public function testOnChangeTagCanCreate( $tag, $isGood ) {
		$config1 = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => true,
			'HashtagsRequireActiveTag' => false
		] );
		$hookObj1 = new TagHooks( $this->tagStore, $config1 );
		$user = $this->createMock( User::class );

		$status = Status::newGood();
		$hookObj1->onChangeTagCanCreate( $tag, $user, $status );
		$this->assertEquals( $status->isGood(), $isGood, "$tag systemManaged=true active=false" );

		$config2 = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => false,
			'HashtagsRequireActiveTag' => false
		] );
		$hookObj2 = new TagHooks( $this->tagStore, $config2 );
		$status = Status::newGood();
		$hookObj2->onChangeTagCanCreate( $tag, $user, $status );
		$this->assertStatusGood( $status, "$tag system=false;active=false" );

		$config3 = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => false,
			'HashtagsRequireActiveTag' => true
		] );
		$hookObj3 = new TagHooks( $this->tagStore, $config2 );
		$status = Status::newGood();
		$hookObj3->onChangeTagCanCreate( $tag, $user, $status );
		$this->assertStatusGood( $status, "$tag system=false;active=true" );

		$config4 = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => true,
			'HashtagsRequireActiveTag' => true
		] );
		$hookObj4 = new TagHooks( $this->tagStore, $config2 );
		$status = Status::newGood();
		$hookObj4->onChangeTagCanCreate( $tag, $user, $status );
		$this->assertStatusGood( $status, "$tag system=true;active=true" );
	}

	public static function provideOnMessagesPreLoad() {
		yield [ 'Foo', false ];
		yield [ 'hashtag-nope', false ];
		yield [ 'Hashtag-nope', false ];
		yield [ 'Tag-hashtag-foo', true ];
		yield [ 'Tag-hashtag-foo/fr', false ];
		yield [ 'Tag-hashtag-foo/en-ca', false ];
		yield [ 'Tag-hashtag-foo-helppage', false ];
		yield [ 'Tag-hashtag-foo-description', false ];
		yield [ 'Tag-hashtag-foo-helppage/de', false ];
		yield [ 'Tag-hashtag-foo-description/zh', false ];
		yield [ 'Tag-hashtag-bar12', true ];
		yield [ 'Tag-hashtag-française', true ];
	}

	/**
	 * @dataProvider provideOnMessagesPreLoad
	 */
	public function testOnMessagesPreLoad( $msg, $replace ) {
		$config = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => true,
			'HashtagsRequireActiveTag' => false
		] );
		$hookObj = new TagHooks( $this->tagStore, $config );

		$out = false;
		$code = 'en';
		$hookObj->onMessagesPreLoad( $msg, $out, $code );
		if ( $replace ) {
			$this->assertEquals( '-', $out, $msg );
		} else {
			$this->assertFalse( $out, $msg );
		}
	}
}
