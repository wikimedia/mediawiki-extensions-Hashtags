<?php

namespace MediaWiki\Extension\Hashtags;

use MediaWiki\Cache\Hook\MessagesPreLoadHook;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\Config\Config;
use MediaWiki\ChangeTags\Hook\ChangeTagCanCreateHook;

class TagHooks implements ListDefinedTagsHook, ChangeTagsListActiveHook, MessagesPreLoadHook, ChangeTagCanCreateHook {

	private ChangeTagsStore $tagStore;
	private bool $useSystemManagedTags;
	private bool $requireUserActivate;

	public function __construct( ChangeTagsStore $store, Config $config ) {
		$this->tagStore = $store;
		$this->useSystemManagedTags = $config->get( 'HashtagsMakeTagsSystemManaged' );
		$this->requireUserActivate = $config->get( 'HashtagsRequireActiveTag' );
	}

	/**
	 * @inheritDoc
	 */
	public function onChangeTagsListActive( &$tags ) {
		if ( $this->useSystemManagedTags || !$this->requireUserActivate ) {
			$tags = array_merge( $tags, $this->getUsedHashtagTags() );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onListDefinedTags( &$tags ) {
		if ( $this->useSystemManagedTags ) {
			$tags = array_merge( $tags, $this->getUsedHashtagTags() );
		}
	}

	/**
	 * Get all the hashtags that have been used at least once
	 *
	 * This is so Special:Tags displays them as "defined by software"
	 * @return array List of tags controlled by this extension.
	 */
	private function getUsedHashtagTags() {
		$tags = [];
		$stats = $this->tagStore->tagUsageStatistics();
		foreach ( $stats as $tag => $hitcount ) {
			if (
				$hitcount > 0 &&
				substr( $tag, 0, strlen( HashtagCommentParser::HASHTAG_PREFIX ) )
					=== HashtagCommentParser::HASHTAG_PREFIX
			) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/**
	 * @inheritDoc
	 */
	public function onMessagesPreLoad( $title, &$message, $code ) {
		$prefix = 'Tag-' . HashtagCommentParser::HASHTAG_PREFIX;
		if ( substr( $title, 0, strlen( $prefix ) ) === $prefix ) {
			if (
				substr( $title, -12 ) !== '-description' &&
				substr( $title, -9 ) !== '-helppage' &&
				// MW seems to do put msg/langcode for title sometimes.
				strpos( $title, '/' ) === false
			) {
				$message = '-';
				return false; // We have replaced message, stop further processing
			}
		}
	}

	public function onChangeTagCanCreate( $tag, $user, &$status ) {
		if (
			$this->useSystemManagedTags
			&& !$this->requireUserActivate
			&& substr( $tag, 0, strlen( HashtagCommentParser::HASHTAG_PREFIX ) )
				=== HashtagCommentParser::HASHTAG_PREFIX
		) {
			// We only ban creating hashtag names if user activation is uneeded
			// and we manage hashtags as system tags.
			$status->fatal( 'hashtags-tag-reserved' );
		}
	}
}
