<?php

namespace MediaWiki\Extension\Hashtags;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\CommentParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Parser\Sanitizer;
use RuntimeException;

/**
 * This is our own version of Core's CommentParser.
 *
 * It works by wrapping around an existing CommentParser. We
 * find all the tags, replace them with markers, and at the end
 * replace the markers with links to Special:RecentChanges filtered by
 * that tag.
 *
 * This extends core's CommentParser in order to pass type checks. We
 * don't actually want to inherit anything. We implement all the
 * public methods to intercept and wrap around them.
 */
class HashtagCommentParser extends CommentParser {

	// Alternatively, we could just make it be '#'.
	public const HASHTAG_PREFIX = 'hashtag-';
	private const MARKER_PREFIX = "\x0F'\"";
	private const MARKER_REGEX = "/\x0F'\"([0-9]{7})/";
	private const MARKER_REGEX_ESCAPED = "/\x0F(?:'|&apos;|&#0?39;)(?:\"|&quot;|&#0?34;)([0-9]{7})/";
	private CommentParser $commentParser;
	private LinkRenderer $linkRenderer;
	private ChangeTagsStore $changeTagsStore;
	private bool $requireActivation;
	private array $invalidList;
	private LinkTarget $targetOfTagLinks;

	/** @var array Map of markers -> tag names */
	private $markerMap = [];
	/** @var array map of markers -> bool, if we should replace html or plaintext */
	private $markersToReplace = [];
	/** @var int How many markers so far */
	private $markerCount = 0;

	public function __construct(
		CommentParser $commentParser,
		LinkRenderer $linkRenderer,
		ChangeTagsStore $changeTagsStore,
		bool $requireActivation,
		array $invalidList,
		LinkTarget $targetOfTagLinks
	) {
		// CommentParser is technically marked @internal... but meh.
		$this->commentParser = $commentParser;
		$this->linkRenderer = $linkRenderer;
		$this->requireActivation = $requireActivation;
		$this->changeTagsStore = $changeTagsStore;
		$this->invalidList = $invalidList;
		$this->targetOfTagLinks = $targetOfTagLinks;
		// Intentionally do not call parent::__construct
	}

	/**
	 * Convert a comment to HTML, but replace links with markers which are
	 * resolved later.
	 *
	 * @param string $comment
	 * @param LinkTarget|null $selfLinkTarget
	 * @param bool $samePage
	 * @param string|false|null $wikiId
	 * @param bool $enableSectionLinks
	 * @return string
	 */
	public function preprocess( string $comment, ?LinkTarget $selfLinkTarget = null,
		$samePage = false, $wikiId = false, $enableSectionLinks = true
	) {
		$comment = Sanitizer::escapeHtmlAllowEntities( $comment );
		$comment = $this->extractTags( $comment );
		// We escape ourselves before processing tags, so call unsafe variant.
		$res = $this->commentParser->preprocessUnsafe(
			$comment, $selfLinkTarget, $samePage, $wikiId, $enableSectionLinks
		);
		$this->checkExtractedTags( $res );
		return $res;
	}

	/**
	 * Convert a comment in pseudo-HTML format to HTML, without escaping HTML.
	 *
	 * @param string $comment
	 * @param LinkTarget|null $selfLinkTarget
	 * @param bool $samePage
	 * @param string|false|null $wikiId
	 * @param bool $enableSectionLinks
	 * @return string
	 */
	public function preprocessUnsafe( $comment, ?LinkTarget $selfLinkTarget = null,
		$samePage = false, $wikiId = false, $enableSectionLinks = true
	) {
		$comment = $this->extractTags( $comment );
		$res = $this->commentParser->preprocessUnsafe(
			$comment, $selfLinkTarget, $samePage, $wikiId, $enableSectionLinks
		);
		$this->checkExtractedTags( $res );
		return $res;
	}

	/**
	 * Execute pending batch queries and replace markers in the specified
	 * string(s) with actual links.
	 *
	 * @param string|string[] $comments
	 * @return string|string[]
	 */
	public function finalize( $comments ) {
		$finalized = $this->commentParser->finalize( $comments );
		return $this->replaceMarkers( $finalized );
	}

	/**
	 * Get a new marker to insert into comment as a placeholder
	 * @return string
	 */
	private function newMarker(): string {
		$id = sprintf( self::MARKER_PREFIX . "%07d", $this->markerCount++ );
		if ( strlen( $id ) > 10 ) {
			throw new RuntimeException( "Too many markers" );
		}
		return $id;
	}

	/**
	 * Get a marker for a specific tag
	 *
	 * @param string $tag Tag name
	 * @return string Marker for that tag
	 */
	private function addMarker( string $tag ): string {
		$marker = $this->newMarker();
		$this->markerMap[substr( $marker, strlen( self::MARKER_PREFIX ) )] = $tag;
		return $marker;
	}

	/**
	 * Find all the tags and replace them with markers to be replaced later
	 *
	 * @param string $comment Comment text before preprocessing
	 * @return string Comment after markers inserted
	 */
	private function extractTags( $comment ): string {
		return preg_replace_callback(
			// It is a bit unclear how to define a hashtag in an i18n context
			// I am going with a unicode letter followed by stuff that is
			// letter, number, connecting punctuation, dash punctuation,
			// mark or modifier symbol.
			// I am unsure if all symbols should be included or if marks should
			// be allowed as first letter.
			// We allow a formatting character to come before # for bidi control chars
			'/(\p{Zs}|^|\p{Zs}\p{Cf})#(\p{L}[\p{L}\p{N}\p{M}\p{Pc}\p{Pd}\p{Sk}]*)/u',
			function ( $m ) {
				$prefix = $m[1];
				$tag = $m[2];
				if ( $this->isValidTag( $tag ) ) {
					return $prefix . $this->addMarker( $tag );
				}
				return $prefix . '#' . $tag;
			},
			$comment
		);
	}

	private function isValidTag( string $tag ): bool {
		if ( ( $this->invalidList[$tag] ?? false ) === true ) {
			return false;
		}
		if ( $this->requireActivation ) {
			// This does not include software activated tags, only user activated.
			// No hashtags should meet that criteria in this case, but unclear if we
			// should still check.
			$tags = $this->changeTagsStore->listExplicitlyDefinedTags();
			return in_array( self::HASHTAG_PREFIX . $tag, $tags );
		}
		return true;
	}

	/**
	 * Detect which markers were eaten by links.
	 *
	 * If a marker disappears after preprocessing, that means it is
	 * probably in the body of a link tag. Make a list as we don't
	 * want to make a double link. (This is purely for UI and does
	 * not matter from a security perspective).
	 *
	 * For titles that are isAlwaysKnown(), the replacement happens too
	 * fast and we end up with a double nested link.
	 * e.g. [[Special:RecentChanges|foo #bar]] has unfortunate (but still secure) output.
	 *
	 * @param string $comment Comment text after preprocessing
	 */
	private function checkExtractedTags( string $comment ): void {
		if ( preg_match_all( self::MARKER_REGEX, $comment, $m ) ) {
			foreach ( $m[1] as $marker ) {
				$this->markersToReplace[$marker] = true;
			}
		}
	}

	/**
	 * Replace our markers to give the final result text
	 *
	 * @param string|string[] $comment Final comment text, after finalize
	 * @return string|string[]
	 */
	private function replaceMarkers( $comment ) {
		$comment = preg_replace_callback(
			self::MARKER_REGEX,
			function ( $marker ) {
				if ( !isset( $this->markerMap[$marker[1]] ) ) {
					// This should probably not happen.
					wfWarn( "Marker '$marker[1]' is missing in HashtagCommentParser" );
					return $marker[0];
				}
				if ( ( $this->markersToReplace[$marker[1]] ?? false ) === true ) {
					return $this->generateTagLink( $this->markerMap[$marker[1]] );
				} else {
					// Inside the text of a link tag. Do not make a second link
					return htmlspecialchars( '#' . $this->markerMap[$marker[1]] );
				}
			},
			$comment
		);
		$comment = preg_replace_callback(
			self::MARKER_REGEX_ESCAPED,
			function ( $marker ) {
				if ( !isset( $this->markerMap[$marker[1]] ) ) {
					// This should probably not happen.
					wfWarn( "Marker '$marker[1]' is missing in HashtagCommentParser" );
					return $marker[0];
				}
				// Our marker got escaped, replace it with just the plaintext tag
				// This likely means the tag is in an attribute. For security reasons
				// it is important we do not insert a link tag.
				// It is not actually clear if this code is ever actually reachable
				return htmlspecialchars( '#' . $this->markerMap[$marker[1]] );
			},
			$comment
		);
		return $comment;
	}

	/**
	 * Make a link for the hashtag
	 *
	 * @param string $tag The name of the tag
	 * @return string HTML link to RC filtered by that tag
	 */
	private function generateTagLink( $tag ) {
		// This always links to RC. An argument could be
		// made that instead we should filter the current page
		// (e.g. History page or log list) instead of RC.
		return $this->linkRenderer->makeLink(
			$this->targetOfTagLinks,
			'#' . $tag,
			[ 'class' => 'mw-hashtag' ],
			[ 'tagfilter' => self::HASHTAG_PREFIX . $tag ]
		);
	}

	/**
	 * Get a list of all tags seen so far
	 *
	 * I am unsure if it makes sense
	 * exclude those not in markersToReplace.
	 *
	 * @warning If this class has processed multiple comments, all are counted.
	 * @return array
	 */
	public function getAllTagsSeen(): array {
		$tags = [];
		foreach ( $this->markersToReplace as $marker => $value ) {
			if ( $value ) {
				$tags[] = self::HASHTAG_PREFIX . $this->markerMap[$marker];
			}
		}
		return array_unique( $tags );
	}
}
