<?php

namespace MediaWiki\Extension\Hashtags;

use LogPage;
use Maintenance;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Logging\LoggingSelectQueryBuilder;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionStore;
use Wikimedia\Rdbms\IResultWrapper;

// @codeCoverageIgnoreStart
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class ReparseHashtags extends Maintenance {

	private RevisionStore $revisionStore;
	private CommentStore $commentStore;
	private ChangeTagsStore $changeTagsStore;
	private HashtagCommentParserFactory $cpFactory;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Check all edit summaries and log summaries to make sure"
			. " they have the correct hashtags."
		);
		// argname, description, required, has arg
		$this->addOption( 'type', 'Either logging or revision', true, true );
		$this->addOption( 'start', 'Id number to start with', false, true );
		$this->addOption( 'end', 'Id number to end with', false, true );
		$this->addOption( 'no-delete', 'Do not delete any change tags corresponding to'
			. ' hashtags that aren\'t in comment', false, false
		);
		$this->addOption( 'verbose', 'Show extra output' );
		$this->setBatchSize( 100 );
	}

	private function doSetup() {
		$this->revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$this->commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$this->changeTagsStore = MediaWikiServices::getInstance()->getChangeTagsStore();
		$commentParserFactory = MediaWikiServices::getInstance()->getCommentParserFactory();
		if ( !( $commentParserFactory instanceof HashtagCommentParserFactory ) ) {
				// Maybe something else wrapped our wrapper?
				// This is hacky, but should work.
				$commentParserFactory = ServicesHooks::wrapCommentParserFactory(
						$commentParserFactory,
						MediaWikiServices::getInstance()
				);
		}
				$this->cpFactory = $commentParserFactory;
	}

	public function execute() {
		$this->doSetup();
		$start = (int)$this->getOption( 'start', 0 );
		$end = (int)$this->getOption( 'end' ) ?: null;
		$noDelete = $this->getOption( 'no-delete', false );
		$type = $this->getOption( 'type' );
		if ( $type !== 'revision' && $type !== 'logging' ) {
			$this->fatalError( 'Must specify --type revision OR --type logging' );
		}
		$commentField = $type === 'logging' ? 'log_comment' : 'rev_comment';
		$idField = $type === 'logging' ? 'log_id' : 'rev_id';
		$delField = $type === 'logging' ? 'log_deleted' : 'rev_deleted';
		$count = 0;
		$itemsAdjusted = 0;

		do {
			$res = $this->doQuery( $type, $start, $end );
			foreach ( $res as $row ) {
				$start = $row->{$idField} + 1;
				$count++;
				// There is a lot of duplication with RCSave. Maybe
				// should be refactored to share code.
				// tags cannot contain comma, so this should be fine.
				if ( $this->getOption( 'verbose' ) ) {
					$this->verbose( "Starting " . $row->{$idField} );
				} elseif ( $count % 100 === 1 ) {
					$this->output( "Starting " . $row->{$idField} . "\n" );
				}
				$existingTags = explode( ',', $row->ts_tags );
				$this->verbose( "Existing tags: " . implode( ', ', $existingTags ) );
				$existingTags = array_filter( $existingTags,
					static function ( $tag ) {
						return substr( $tag, 0,
							strlen( HashtagCommentParser::HASHTAG_PREFIX )
						) === HashtagCommentParser::HASHTAG_PREFIX;
					}
				);
				if ( ( $row->{$delField} & LogPage::DELETED_COMMENT ) !== 0 ) {
					$comment = 0;
					$this->verbose( "Edit summary is revdeleted" );
				} else {
					$comment = $this->commentStore->getComment( $commentField, $row )->text;
				}
				$correctTags = $this->getTagsFromEditSummary( $comment );
				$tagsToRemove = array_diff( $existingTags, $correctTags );
				$tagsToAdd = array_diff( $correctTags, $existingTags );

				if ( $noDelete && $tagsToRemove ) {
					$this->verbose( "Keeping the following tags despite not fitting: "
						. implode( ', ', $tagsToRemove )
					);
				} elseif ( $tagsToRemove ) {
					$this->verbose( "Removing following tags: " . implode( $tagsToRemove ) );
				}
				if ( ( !$tagsToRemove && !$tagsToAdd ) || ( !$tagsToAdd && $noDelete ) ) {
					continue;
				}
				$itemsAdjusted++;
				if ( $tagsToAdd ) {
					$this->verbose( "Adding the following tags: " . implode( $tagsToAdd ) );
				}

				$rcId = null;
				$revId = $type === 'revision' ? $row->rev_id : null;
				$logId = $type === 'logging' ? $row->log_id : null;
				$this->changeTagsStore->updateTags( $tagsToAdd, $tagsToRemove, $rcId, $revId, $logId );
			}
			$this->waitForReplication();
		} while ( $res->numRows() === $this->getBatchSize() );

		$this->output( "Done $type. Total rows: $count. Adjusted items: $itemsAdjusted\n" );
	}

	private function doQuery( string $type, int $start, ?int $end ): IResultWrapper {
		$dbr = $this->getDB( DB_REPLICA );
		$idField = $type === 'revision' ? 'rev_id' : 'log_id';
		if ( $type === 'revision' ) {
			$builder = $this->revisionStore->newSelectQueryBuilder( $dbr )
				->joinComment();
		} elseif ( $type === 'logging' ) {
			$builder = new LoggingSelectQueryBuilder( $dbr );
		} else {
			throw new \UnexpectedValueException( "unrecognized type" );
		}
		$builder
			->where( $dbr->expr( $idField, '>=', $start ) )
			->limit( $this->getBatchSize() )
			->orderBy( $idField )
			->caller( __METHOD__ );
		if ( $end !== null ) {
			$builder->where( $dbr->expr( $idField, '<=', $end ) );
		}
		$this->changeTagsStore->modifyDisplayQueryBuilder( $builder, $type );
		return $builder->fetchResultSet();
	}

	private function getTagsFromEditSummary( string $summary ): array {
			$commentParser = $this->cpFactory->create();
		if ( !$commentParser instanceof HashtagCommentParser ) {
				// This should be impossible, however we are doing tricky
				// things here, so be defensive
				throw new \UnexpectedValueException( "Must be a HashtagCommentParser" );
		}

			$commentParser->preprocess( $summary );
			return $commentParser->getAllTagsSeen();
	}

	private function verbose( string $text ): void {
		if ( $this->getOption( 'verbose' ) ) {
			$this->output( $text . "\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = ReparseHashtags::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
