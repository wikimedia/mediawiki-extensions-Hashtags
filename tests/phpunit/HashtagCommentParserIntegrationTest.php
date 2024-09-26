<?php
use MediaWiki\Extension\Hashtags\ServicesHooks;

/**
 * @covers \MediaWiki\Extension\Hashtags\HashtagCommentParser
 */
class HashtagCommentParserIntegrationTest extends MediaWikiIntegrationTestCase {

	public static function provideHashtagCommentParser() {
		yield [
			'Foo #bar',
			'Foo <a href="/index.php?title=Special:Version&amp;tagfilter=hashtag-bar"' .
				' class="mw-hashtag" title="Special:Version">#bar</a>',
			'Foo <a href="/index.php?title=Special:RecentChanges&amp;tagfilter=' .
				'hashtag-bar" class="mw-hashtag" title="Special:RecentChanges">#bar</a>',
			'Foo <a href="/index.php?title=Special:Log&amp;tagfilter=hashtag-bar"' .
				' class="mw-hashtag" title="Special:Log">#bar</a>'
		];
		yield [
			'Foo [[#bar]]',
			'Foo <a href="#bar">#bar</a>',
			'Foo <a href="#bar">#bar</a>',
			'Foo <a href="#bar">#bar</a>'
		];
		yield [
			'Foo [[#f|#bar]]',
			'Foo <a href="#f">#bar</a>',
			'Foo <a href="#f">#bar</a>',
			'Foo <a href="#f">#bar</a>',
		];
		yield [
			'Foo [[Special:NonExistentSpecial|#bar]]',
			'Foo <a href="/wiki/Special:NonExistentSpecial" class="new" title="' .
				'Special:NonExistentSpecial (page does not exist)">#bar</a>',
			'Foo <a href="/wiki/Special:NonExistentSpecial" class="new" title="' .
				'Special:NonExistentSpecial (page does not exist)">#bar</a>',
			'Foo <a href="/wiki/Special:NonExistentSpecial" class="new" title="' .
				'Special:NonExistentSpecial (page does not exist)">#bar</a>',
		];
	}

	/**
	 * @dataProvider provideHashtagCommentParser
	 */
	public function testHashtagCommentParser( $comment, $version, $rc, $log ) {
		$factory = $this->getServiceContainer()->getCommentParserFactory();
		$wrappedFactory = ServicesHooks::wrapCommentParserFactory( $factory, $this->getServiceContainer() );
		$target = new TitleValue( NS_SPECIAL, 'Version' );
		$parser = $factory->createWithTagTarget( $target );

		$pre = $parser->preprocess( $comment );
		$res = $parser->finalize( $pre );
		$this->assertEquals( $res, $version, 'version' );

		$parserWrapped = $wrappedFactory->createWithTagTarget( $target );
		$pre = $parser->preprocess( $comment );
		$res = $parser->finalize( $pre );
		$this->assertEquals( $res, $version, 'wrapped' );

		$context = new RequestContext;
		$context->setTitle( Title::newMainPage() );
		$factory->setContext( $context );
		$parser = $factory->create();

		$pre = $parser->preprocess( $comment );
		$res = $parser->finalize( $pre );
		$this->assertEquals( $res, $rc, 'rc' );

		$context = new RequestContext;
		$context->setTitle( Title::newFromText( 'Special:Log' ) );
		$factory->setContext( $context );
		$parser = $factory->create();

		$pre = $parser->preprocess( $comment );
		$res = $parser->finalize( $pre );
		$this->assertEquals( $res, $log, 'log' );

		$context = new RequestContext;
		$context->setTitle( Title::newFromText( 'Special:Log/delete' ) );
		$factory->setContext( $context );
		$parser = $factory->create();

		$pre = $parser->preprocess( $comment );
		$res = $parser->finalize( $pre );
		$this->assertEquals( $res, $log, 'log/delete' );
	}
}
