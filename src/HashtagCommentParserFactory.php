<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkRenderer;

class HashtagCommentParserFactory extends CommentParserFactory {

	private CommentParserFactory $commentParserFactory;
	private LinkRenderer $linkRenderer;
	private ChangeTagsStore $changeTagsStore;
	private bool $requireActivation;

	public const CONSTRUCTOR_OPTIONS = [
		"HashtagsRequireActiveTag"
	];

	public function __construct(
		CommentParserFactory $commentParserFactory,
		LinkRenderer $linkRenderer,
		ChangeTagsStore $changeTagsStore,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->commentParserFactory = $commentParserFactory;
		$this->linkRenderer = $linkRenderer;
		$this->changeTagsStore = $changeTagsStore;
		$this->requireActivation = $options->get( 'HashtagsRequireActiveTag' );
	}

	public function create() {
		$originalObj = $this->commentParserFactory->create();
		return new HashtagCommentParser(
			$originalObj,
			$this->linkRenderer,
			$this->changeTagsStore,
			$this->requireActivation
		);
	}
}
