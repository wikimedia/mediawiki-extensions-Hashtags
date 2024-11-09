<?php
use MediaWiki\Extension\Hashtags\HashtagCommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParserFactory;
use MediaWiki\Extension\Hashtags\TagCollector;

/**
 * @covers \MediaWiki\Extension\Hashtags\TagCollector
 */
class TagCollectorTest extends MediaWikiUnitTestCase {

	public function testDoubleStart() {
		$a = new TagCollector;
		$hashtagCommentParserF = $this->createMock( HashtagCommentParserFactory::class );
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParserF->method( 'create' )->willReturn( $hashtagCommentParser );
		$hashtagCommentParser->method( 'preprocess' )->willReturnCallback(
			static function ( $str ) use ( $a, $hashtagCommentParser ) {
				$a->startParse( $hashtagCommentParser );
				$a->startParse( $hashtagCommentParser );
			}
		);
		$this->expectException( LogicException::class );
		$a->getTagsSeen( $hashtagCommentParserF, 'foo' );
	}

	public function testIgnoreUnlocked() {
		$a = new TagCollector;
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$a->startParse( $hashtagCommentParser );
		// Make sure no exception thrown.
		$a->startParse( $hashtagCommentParser );
		$this->assertTrue( true );
	}

	public function testReenterantSubmit() {
		$a = new TagCollector;
		$hashtagCommentParserF = $this->createMock( HashtagCommentParserFactory::class );
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParserF->method( 'create' )->willReturn( $hashtagCommentParser );
		$hashtagCommentParser2 = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParser->method( 'preprocess' )->willReturnCallback(
			static function ( $str ) use ( $a, $hashtagCommentParser, $hashtagCommentParser2 ) {
				$a->startParse( $hashtagCommentParser );
				$a->submitTag( $hashtagCommentParser2, 'foo' );
			}
		);
		$this->expectException( LogicException::class );
		$a->getTagsSeen( $hashtagCommentParserF, 'foo' );
	}

	public function testReenterant() {
		$a = new TagCollector;
		$hashtagCommentParserF = $this->createMock( HashtagCommentParserFactory::class );
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParserF->method( 'create' )->willReturn( $hashtagCommentParser );
		$hashtagCommentParser->method( 'preprocess' )->willReturnCallback(
			static function ( $str ) use ( $a, $hashtagCommentParserF ) {
				$a->getTagsSeen( $hashtagCommentParserF, 'baz' );
			}
		);
		$this->expectException( LogicException::class );
		$a->getTagsSeen( $hashtagCommentParserF, 'foo' );
	}

	public function testDisconnected() {
		$a = new TagCollector;
		$hashtagCommentParserF = $this->createMock( HashtagCommentParserFactory::class );
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParserF->method( 'create' )->willReturn( $hashtagCommentParser );
		$hashtagCommentParser->method( 'preprocess' )->willReturn( '' );
		$this->expectException( LogicException::class );
		$a->getTagsSeen( $hashtagCommentParserF, 'foo' );
	}

	public function testGetTagsSeen() {
		$a = new TagCollector;
		$hashtagCommentParserF = $this->createMock( HashtagCommentParserFactory::class );
		$hashtagCommentParser = $this->createMock( HashtagCommentParser::class );
		$hashtagCommentParserF->method( 'create' )->willReturn( $hashtagCommentParser );
		$hashtagCommentParser->method( 'preprocess' )->willReturnCallback(
			static function ( $str ) use ( $a, $hashtagCommentParser ) {
				$a->startParse( $hashtagCommentParser );
				$a->submitTag( $hashtagCommentParser, 'foo' );
				$a->submitTag( $hashtagCommentParser, 'bar' );
			}
		);
		$res = $a->getTagsSeen( $hashtagCommentParserF, 'foo' );
		$this->assertEqualsCanonicalizing( $res, [ 'foo', 'bar' ] );
	}

}
