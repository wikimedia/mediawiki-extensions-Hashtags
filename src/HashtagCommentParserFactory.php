<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Linker\LinkRenderer;

class HashtagCommentParserFactory extends CommentParserFactory {

	private CommentParserFactory $commentParserFactory;
	private LinkRenderer $linkRenderer;

	public function __construct( CommentParserFactory $commentParserFactory, LinkRenderer $linkRenderer ) {
		$this->commentParserFactory = $commentParserFactory;
		$this->linkRenderer = $linkRenderer;
	}

	public function create() {
		$originalObj = $this->commentParserFactory->create();
		return new HashtagCommentParser( $originalObj, $this->linkRenderer );
	}
}
