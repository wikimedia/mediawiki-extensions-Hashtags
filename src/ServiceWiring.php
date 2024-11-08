<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart

return [
	'Hashtags:TagCollector' => static function ( MediaWikiServices $services ): TagCollector {
		return new TagCollector;
	}
];

// @codeCoverageIgnoreEnd
