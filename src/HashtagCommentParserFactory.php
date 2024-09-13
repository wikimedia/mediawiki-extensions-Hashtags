<?php
namespace MediaWiki\Extension\Hashtags;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Linker\LinkRenderer;

class HashtagCommentParserFactory extends CommentParserFactory {

	private CommentParserFactory $commentParserFactory;
	private LinkRenderer $linkRenderer;
	private ChangeTagsStore $changeTagsStore;
	private bool $requireActivation;
	private IContextSource $context;
	private array $invalidList;

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
		$this->context = RequestContext::getMain();
		$this->invalidList = $this->getInvalidList();
	}

	public function setContext( IContextSource $context ) {
		$this->context = $context;
	}

	public function create() {
		$originalObj = $this->commentParserFactory->create();
		return new HashtagCommentParser(
			$originalObj,
			$this->linkRenderer,
			$this->changeTagsStore,
			$this->requireActivation,
			$this->invalidList
		);
	}

	/**
	 * Get a list of tags not to use from the i18n message
	 *
	 * @return array [ tagname => true, ... ]
	 */
	private function getInvalidList() {
		$list = [];
		$msg = $this->context->msg(
			'hashtags-invalid-tags'
		)->inContentLanguage()->plain();
		$items = explode( "\n", $msg );
		foreach ( $items as $i ) {
			$item = trim( $i );
			if ( substr( $item, 0, 1 ) === '#' && strpos( $item, ' ' ) === false ) {
				$list[substr( $item, 1 )] = true;
			}
		}
		return $list;
	}
}
