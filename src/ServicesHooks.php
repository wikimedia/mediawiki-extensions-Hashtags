<?php

namespace MediaWiki\Extension\Hashtags;

use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
use RequestContext;

class ServicesHooks implements MediaWikiServicesHook {

	/**
	 * @inheritDoc
	 */
	public function onMediaWikiServices( $services ) {
		// We don't use redefineService, because we want this to
		// be reasonably stable. Constructors tend to be very unstable
		// between MediaWiki versions. If we simply extended CommentParserFactory
		// we need to pass all the right arguments to the parent constructor
		// which may change. Instead we just add a wrapper object over an
		// already constructed factory
		// Not in 1.40
		//$services->addServiceManipulator(
		//	'CommentParserFactory',
		//	__CLASS__ . '::wrapCommentParserFactory'
		//);
		$services->redefineService(
			'CommentFormatter',
			static function ( $services ) {
				return new CommentFormatter(
					self::getCommentParser( $services )
				);
			}
		);
		$services->redefineService(
			'RowCommentFormatter',
			static function ( $services ) {
				return new RowCommentFormatter(
					self::getCommentParser( $services ),
					$services->getCommentStore()
				);
			}
		);
	}

	/**
	 * hack for 1.40
	 */
	public static function getCommentParser( $services ) {
		$linkRenderer = $services->getLinkRendererFactory()->create( [ 'renderForComment' => true ] );
		return self::wrapCommentParserFactory( new CommentParserFactory(
			$linkRenderer,
			$services->getLinkBatchFactory(),
			$services->getLinkCache(),
			$services->getRepoGroup(),
			RequestContext::getMain()->getLanguage(),
			$services->getContentLanguage(),
			$services->getTitleParser(),
			$services->getNamespaceInfo(),
			$services->getHookContainer()
		), $services );
	}

	/**
	 * Convert an existing CommentParserFactory to one that returns our version of CommentParser
	 *
	 * Implemented as a separate static function because called in RCSaveHooks
	 *
	 * @param CommentParserFactory $factory
	 * @param MediaWikiServices $services
	 * @return HashtagCommentParserFactory
	 */
	public static function wrapCommentParserFactory( CommentParserFactory $factory, MediaWikiServices $services ) {
		// Not sure if renderForComment should be on. Its some weird
		// hack for wikibase.
		$linkRenderer = $services->getLinkRendererFactory()->create( [ 'renderForComment' => true ] );
		$options = new ServiceOptions(
			HashtagCommentParserFactory::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig()
		);
		return new HashtagCommentParserFactory(
			$factory,
			$linkRenderer,
			$services->getSpecialPageFactory(),
			$options
		);
	}
}
