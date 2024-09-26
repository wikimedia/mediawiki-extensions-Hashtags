<?php

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParser;
use MediaWiki\Extension\Hashtags\HashtagCommentParser;
use MediaWiki\Linker\LinkRenderer;

/**
 * @covers \MediaWiki\Extension\Hashtags\HashtagCommentParser
 */
class HashtagCommentParserTest extends MediaWikiUnitTestCase {

	private function getHashtagCommentParser( $requireActivation = false, $pre = null, $final = null ) {
		$commentParser = $this->createMock( CommentParser::class );
		$commentParser->method( 'preprocessUnsafe' )->willReturnCallback( static function ( $i ) use ( $pre ) {
			return $pre ?: $i;
		} );
		$commentParser->method( 'finalize' )->willReturnCallback( static function ( $i ) use ( $final ) {
			return $final ?: $i;
		} );

		$linkRenderer = $this->createMock( LinkRenderer::class );
		$linkRenderer->method( 'makeLink' )->willReturnCallback( static function ( $target, $text ) {
			return '<a href="' . $target->getDBKey() . '">' . htmlspecialchars( $text ) . '</a>';
		} );
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$changeTagsStore->method( 'listExplicitlyDefinedTags' )->willReturn( [
			'hashtag-bar',
			'hashtag-foo',
			'hashtag-xyzzy'
		] );
		$invalidList = [ 'foo' => true, 'something' => true, 'fred' => true ];
		$linkTarget = new TitleValue( NS_SPECIAL, 'Recentchanges' );
		return new HashtagCommentParser(
			$commentParser,
			$linkRenderer,
			$changeTagsStore,
			$requireActivation,
			$invalidList,
			$linkTarget
		);
	}

	public function testPreprocessEscapes() {
		$parser = $this->getHashtagCommentParser();
		$res = $parser->preprocess( '<script>alert(1)</script> &lt; &rarr; #foo #bar' );
		$res = $parser->finalize( $res );
		$this->assertEquals( '&lt;script&gt;alert(1)&lt;/script&gt; &lt; → #foo' .
			' <a href="Recentchanges">#bar</a>', $res, 'preprocess esc' );

		$parser = $this->getHashtagCommentParser();
		$res = $parser->preprocessUnsafe( '<script>alert(1)</script> &lt; &rarr; #foo #bar' );
		$res = $parser->finalize( $res );
		$this->assertEquals( '<script>alert(1)</script> &lt; &rarr; #foo ' .
			'<a href="Recentchanges">#bar</a>', $res, 'preprocessUnsafe esc' );
	}

	public function testGetAllTagsSeen() {
		$parser = $this->getHashtagCommentParser();
		$res = $parser->preprocess( '#foo #bar #baz nothashtag #something' );
		$res = $parser->finalize( $res );
		$this->assertEquals( '#foo <a href="Recentchanges">#bar</a> ' .
			'<a href="Recentchanges">#baz</a> nothashtag #something', $res );
		$tags = $parser->getAllTagsSeen();
		$this->assertEqualsCanonicalizing( $tags, [ 'hashtag-bar', 'hashtag-baz' ] );

		$parser = $this->getHashtagCommentParser( true );
		$res = $parser->preprocess( '#foo #bar #baz nothashtag #something' );
		$res = $parser->finalize( $res );
		$this->assertEquals( '#foo <a href="Recentchanges">#bar</a> #baz nothashtag' .
			' #something', $res );
		$tags = $parser->getAllTagsSeen();
		$this->assertEqualsCanonicalizing( $tags, [ 'hashtag-bar' ] );
	}

	public function testLinkInsideLink() {
		$parser = $this->getHashtagCommentParser( false, "foo \x0F'\"0000000", "\x0F'\"0000000<a>\x0F'\"0000001</a>" );
		$pre = $parser->preprocess( '#foo #bar #baz nothashtag #something' );
		$res = $parser->finalize( "$pre" );
		$this->assertEquals( '<a href="Recentchanges">#bar</a><a>#baz</a>', $res );
	}

	public function testFinalize() {
		$parser = $this->getHashtagCommentParser();
		$pre = $parser->preprocess( '#foo #bar #baz nothashtag #something' );
		$res = $parser->finalize( $pre );
		$this->assertEquals( '#foo <a href="Recentchanges">#bar</a> <a href="' .
			'Recentchanges">#baz</a> nothashtag #something',
		$res );

		// Make sure we cannot break out of tags.
		$res2 = $parser->finalize( '<span title="' . htmlspecialchars( $pre ) . '">' );
		$this->assertEquals( '<span title="#foo #bar #baz nothashtag #something">', $res2, 'escaped case' );

		$this->expectPHPError(
			E_USER_NOTICE,
			function () use ( $parser, $pre ) {
				$res3 = $parser->finalize( $pre . ' ' . "\x0F'\"1234567" );
				$this->assertEquals(
					'#foo <a href="Recentchanges">#bar</a> <a href="Recent'
					. 'changes">#baz</a> nothashtag #something ' .
					"\x0F'\"1234567",
					$res3, 'Pass through missing'
				);
			},
			"Marker '1234567' is missing"
		);
		$this->expectPHPError(
			E_USER_NOTICE,
			function () use ( $parser, $pre ) {
				$res3 = $parser->finalize( $pre . ' ' . "\x0F'&quot;1234568" );
				$this->assertEquals(
					'#foo <a href="Recentchanges">#bar</a> <a href="Recent'
					. 'changes">#baz</a> nothashtag #something ' .
					"\x0F'&quot;1234568",
					$res3, 'Pass through missing escaped',
				);
			},
			"Marker '1234568' is missing"
		);
	}
}
