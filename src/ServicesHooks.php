<?php

namespace MediaWiki\Extension\Hashtags;

use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;

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
		$services->addServiceManipulator(
			'CommentParserFactory',
			__CLASS__ . '::wrapCommentParserFactory'
		);
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
		$changeTagsStore = $services->getChangeTagsStore();
		$options = new ServiceOptions(
			HashtagCommentParserFactory::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig()
		);
		return new HashtagCommentParserFactory(
			$factory,
			$linkRenderer,
			$changeTagsStore,
			$services->getSpecialPageFactory(),
			$options
		);
	}
}
