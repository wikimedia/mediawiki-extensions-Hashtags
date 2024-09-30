<?php
namespace MediaWiki\Extension\Hashtags;

use IContextSource;
use MediaWiki\CommentFormatter\CommentParserFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\SpecialPage\SpecialPageFactory;
use RequestContext;
use TitleValue;

class HashtagCommentParserFactory extends CommentParserFactory {

	private CommentParserFactory $commentParserFactory;
	private LinkRenderer $linkRenderer;
	private bool $requireActivation;
	private IContextSource $context;
	private SpecialPageFactory $specialPageFactory;
	private array $invalidList;

	public const CONSTRUCTOR_OPTIONS = [
		"HashtagsRequireActiveTag"
	];

	public function __construct(
		CommentParserFactory $commentParserFactory,
		LinkRenderer $linkRenderer,
		SpecialPageFactory $specialPageFactory,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->commentParserFactory = $commentParserFactory;
		$this->linkRenderer = $linkRenderer;
		$this->requireActivation = $options->get( 'HashtagsRequireActiveTag' );
		$this->specialPageFactory = $specialPageFactory;
	}

	public function setContext( IContextSource $context ) {
		$this->context = $context;
	}

	/**
	 * @inheritDoc
	 * Make a HashtagCommentParser guessing the tag target based on current page
	 */
	public function create() {
		$target = $this->getDefaultTagTarget();
		return $this->createWithTagTarget( $target );
	}

	/**
	 * Make a HashtagCommentParser for a specific tag link target
	 * @return CommentParser
	 */
	public function createWithTagTarget( LinkTarget $target ) {
		$originalObj = $this->commentParserFactory->create();
		return new HashtagCommentParser(
			$originalObj,
			$this->linkRenderer,
			$this->requireActivation,
			$this->getInvalidList(),
			$target
		);
	}

	/**
	 * Get page to link tags to
	 */
	private function getDefaultTagTarget(): LinkTarget {
		if ( !isset( $this->context ) ) {
			$this->context = RequestContext::getMain();
		}
		// I'm unsure how specific to go here. We could
		// potentially try and make it go on the same page -
		// e.g. Clicking on a tag on a history page could show
		// just edits to that page with the tag. I think its
		// better to go broad, just do all of RC for most
		// things and Special:Log if we are already on Special:Log.
		$curTitle = $this->context->getTitle();
		// curTitle might be null during unit tests but otherwise should not be.
		if ( $curTitle && $curTitle->isSpecial( 'Log' ) ) {
			return new TitleValue( NS_SPECIAL, $this->specialPageFactory->getLocalNameFor( 'Log' ) );
		}
		return new TitleValue( NS_SPECIAL, $this->specialPageFactory->getLocalNameFor( 'Recentchanges' ) );
	}

	/**
	 * Get a list of tags not to use from the i18n message
	 *
	 * @return array [ tagname => true, ... ]
	 */
	private function getInvalidList(): array {
		if ( isset( $this->invalidList ) ) {
			return $this->invalidList;
		}
		if ( !isset( $this->context ) ) {
			$this->context = RequestContext::getMain();
		}
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
		$this->invalidList = $list;
		return $this->invalidList;
	}
}
