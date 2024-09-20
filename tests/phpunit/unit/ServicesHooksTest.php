<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\ServicesHooks;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;
use MediaWiki\MediaWikiServices;

/**
 * @covers \MediaWiki\Extension\Hashtags\ServicesHooks
 */
class ServicesHooksTest extends MediaWikiUnitTestCase {

	public function testOnMediaWikiServices() {
		$services = $this->createMock( MediaWikiServices::class );

		$services->expects( $this->once() )->method( 'addServiceManipulator' );

		$hook = new ServicesHooks;
		$hook->onMediaWikiServices( $services );
	}

	public function testWrapCommentParserFactory() {
		// This probably doesn't make sense as a unit test.
		$services = $this->createMock( MediaWikiServices::class );

		$linkRendererMock = $this->createMock( LinkRenderer::class );
		$linkRendererFactoryMock = $this->createMock( LinkRendererFactory::class );
		$linkRendererFactoryMock->method( 'create' )->willReturn( $linkRendererMock );
		$changeTagsStoreMock = $this->createMock( ChangeTagsStore::class );
		$services->method( 'getLinkRendererFactory' )->willReturn( $linkRendererFactoryMock );
		$services->method( 'getChangeTagsStore' )->willReturn( $changeTagsStoreMock );
		$config = new HashConfig( [
			'HashtagsMakeTagsSystemManaged' => true,
			'HashtagsRequireActiveTag' => false
		] );
		$services->method( 'getMainConfig' )->willReturn( $config );

		$commentParserFactory = $this->createMock( CommentParserFactory::class );

		$res = ServicesHooks::wrapCommentParserFactory( $commentParserFactory, $services );
		$this->assertInstanceOf( CommentParserFactory::class, $res );
		$this->assertInstanceOf( HashtagCommentParserFactory::class, $res );
	}
}
