<?php
namespace MediaWiki\Extension\Hashtags;

use LogicException;
use MediaWiki\CommentFormatter\CommentParserFactory;

/**
 * @class
 *
 * This is a bit of a hack.
 *
 * We want to be able to get all the tags seen in an edit summary.
 * It is unclear how best to implement this. We have the following
 * restrictions:
 *
 *  * We want our HashtagCommentParser to essentially decorate the
 *  core class by wrapping and forwarding methods
 *  * It should be possible for other extensions to decorate in
 *  a similar way
 *  * We have no idea if the service class we get is an instance
 *  of our class, it could be an instance from a different extension
 *  extending it in the same way (Maybe an alternative would be to
 *  use class_alis to dynamically specify that we extend whatever
 *  class we get from Wikimedia Services, and then we have instaneof
 *  and a class hierarchy that makes sense. However that kind of requires
 *  us to know about the constructor of the unknown class)
 *  ** Hence we cannot add any methods, because we don't know if the
 *  class we get will have them.
 *  * We do not want to manually construct the base class at any time
 *  since constructors are very unstable in MW. This means we cannot
 *  just make our own instance since we always want it to wrap an
 *  existing base instance that someone else made.
 *  * We want to make sure that the tags we return are actually the
 *  ones we would actually detect (including in the event that a
 *  different decorator modifies the input).
 *
 * So in short, we want to add a method to HashtagCommentParser to
 * extract some specific data during parsing, but we never know if
 * the instance we have will actually expose that method.
 *
 * One solution might be to simply wrap an additional time. If things
 * are indempotent, then that will probably work, we just decorate it
 * multiple times. This seems hacky though.
 *
 * The solution we go with here is to have another class which acts sort
 * of like a log collector. When HashtagCommentParser is running, it sends
 * the list of tags to our collector class. If the particular instanace has
 * been pre-registered, then the collector saves this data, and returns it
 * on a later method call. A bit hacky, and a bit icky in terms of data flow
 * control, but it seems like the best of bad options.
 */
class TagCollector {

	private array $tags = [];
	private bool $lock = false;
	private ?HashtagCommentParser $mostRecentParser = null;

	/**
	 * This is very much not reenterant. Should only be called by
	 * HashtagCommentParser.
	 *
	 * @param HashtagCommentParser $parser To verify we are collecting tags from
	 *  the instance we think we are.
	 * @param string $tag Name of tag, including prefix.
	 * @private
	 */
	public function submitTag( HashtagCommentParser $parser, string $tag ) {
		if ( !$this->lock ) {
			// We aren't collecting tags
			return;
		}
		if ( $parser !== $this->mostRecentParser ) {
			throw new LogicException( "tag parsing loop" );
		}
		$this->tags[] = $tag;
	}

	/**
	 * Only called by HashtagCommentParser. Used to make sure that
	 * our code is actually executing at all.
	 *
	 * @param HashtagCommentParser $parser The parser running our code. May
	 *  not be the original isntance we call preprocess on, since that may
	 *  be decorated.
	 * @private
	 */
	public function startParse( HashtagCommentParser $parser ) {
		if ( !$this->lock ) {
			// Not collecting tags, so no-op
			return;
		}
		if ( $this->mostRecentParser !== null ) {
			throw new LogicException( "Edit tag parsing already started" );
		}
		$this->mostRecentParser = $parser;
	}

	/**
	 * Primary entrypoint. Call this to get tags from an edit summary
	 *
	 * @param CommentParserFactory $parserFactory (May or may not be HashtagCommentParserFactory
	 *  but output must wrap a HashtagCommentParser at the very least).
	 * @param string $summary The edit summary to look at
	 * @return array List of tags
	 */
	public function getTagsSeen( CommentParserFactory $parserFactory, string $summary ) {
		if ( $this->lock || $this->mostRecentParser !== null ) {
			throw new LogicException( __METHOD__ . " is not reenterant" );
		}
		// It is important we always create a fresh parser and do not reuse.
		$parser = $parserFactory->create();
		$this->lock = true;
		// Remember that $parser is not neccessarily the same as $this->mostRecentParser
		$parser->preprocess( $summary );

		if ( $this->mostRecentParser === null ) {
			throw new LogicException( "CommentParser never called startParse" );
		}
		$tags = array_unique( $this->tags );
		$this->tags = [];
		$this->lock = false;
		$this->mostRecentParser = null;
		return $tags;
	}

}
