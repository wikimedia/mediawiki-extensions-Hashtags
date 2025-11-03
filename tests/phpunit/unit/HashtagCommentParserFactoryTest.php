<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParser;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Hashtags\HashtagCommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\TagCollector;
use MediaWiki\Language\RawMessage;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Hashtags\HashtagCommentParserFactory
 */
class HashtagCommentParserFactoryTest extends MediaWikiUnitTestCase {

	private function getFactory() {
		$commentParserFactory = $this->createMock( CommentParserFactory::class );
		$commentParserFactory->method( 'create' )->willReturn( $this->createMock( CommentParser::class ) );
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$options = new ServiceOptions(
			HashtagCommentParserFactory::CONSTRUCTOR_OPTIONS,
			new HashConfig( [
				'HashtagsRequireActiveTag' => false
			] )
		);
		$specialPageFactory = $this->createMock( SpecialPageFactory::class );
		$specialPageFactory->method( 'getLocalNameFor' )->willReturnCallback( static function ( $i ) {
			return $i;
		} );
		$tagCollector = new TagCollector;
		return TestingAccessWrapper::newFromObject(
			new HashtagCommentParserFactory(
				$commentParserFactory, $linkRenderer, $changeTagsStore, $specialPageFactory, $options, $tagCollector
			)
		);
	}

	public function testGetInvalidList() {
		$context = $this->createMock( RequestContext::class );
		$msg = $this->createMock( RawMessage::class );
		$msg->method( 'inContentLanguage' )->willReturn( $msg );
		$msg->method( 'plain' )->willReturn( "Some intro\n#foo\n#bar baz\n\nRandomText" );
		$context->method( 'msg' )->willReturn( $msg );

		$factory = $this->getFactory();
		$factory->setContext( $context );
		$res = $factory->getInvalidList();
		$this->assertEquals( [ 'foo' => true ], $res );
	}

	public static function provideGetDefaultTagTarget() {
		yield [ 'Foo', 'Recentchanges' ];
		yield [ 'Special:Recentchanges', 'Recentchanges' ];
		yield [ 'Project:Baz', 'Recentchanges' ];
		yield [ 'Special:Log', 'Log' ];
	}

	/**
	 * @dataProvider provideGetDefaultTagTarget
	 */
	public function testGetDefaultTagTarget( $target, $expected ) {
		$context = $this->createMock( RequestContext::class );
		$title = $this->createMock( Title::class );
		$title->method( 'isSpecial' )->willReturnCallback( static function ( $arg ) use ( $target ) {
			return "Special:$arg" === $target;
		} );
		$context->method( 'getTitle' )->willReturn( $title );
		$factory = $this->getFactory();
		$factory->setContext( $context );
		$res = $factory->getDefaultTagTarget();
		$this->assertEquals( NS_SPECIAL, $res->getNamespace(), "wrong ns for $target" );
		$this->assertEquals( $expected, $res->getDBkey(), "wrong dbkey for $target" );
	}

	public function testCreate() {
		$context = $this->createMock( RequestContext::class );
		$title = $this->createMock( Title::class );
		$target = 'Main_Page';
		$title->method( 'isSpecial' )->willReturnCallback( static function ( $arg ) use ( $target ) {
			return "Special:$arg" === $target;
		} );
		$context->method( 'getTitle' )->willReturn( $title );
		$factory = $this->getFactory();
		$msg = $this->createMock( RawMessage::class );
		$msg->method( 'inContentLanguage' )->willReturn( $msg );
		$msg->method( 'plain' )->willReturn( "Some intro\n#foo\n#bar baz\n\nRandomText" );
		$context->method( 'msg' )->willReturn( $msg );
		$factory->setContext( $context );

		$res = $factory->create();
		$this->assertInstanceOf( HashtagCommentParser::class, $res );
		$res = $factory->createWithTagTarget( $title );
		$this->assertInstanceOf( HashtagCommentParser::class, $res );
		// Not sure what else to test here? Verify that right title was used?
	}
}
