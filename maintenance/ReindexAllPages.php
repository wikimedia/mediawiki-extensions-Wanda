<?php

namespace MediaWiki\Extension\Wanda\Maintenance;

use Maintenance;
use MediaWiki\Extension\Wanda\Hooks\PageIndexUpdater;
use MediaWiki\Extension\Wanda\WandaIndexPageJob;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

$maintClass = ReindexAllPages::class;

class ReindexAllPages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'Wanda' );
		$this->addDescription( 'Queue a reindex of every page' );
		$this->addOption( 'inline', 'Index pages in this process instead of queueing jobs' );
		$this->setBatchSize( 500 );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$dbr = $this->getReplicaDB();
		$inline = $this->hasOption( 'inline' );
		$lastPageId = 0;
		$processed = 0;
		$failed = 0;

		do {
			$rows = $dbr->newSelectQueryBuilder()
				->select( [ 'page_id', 'page_namespace', 'page_title' ] )
				->from( 'page' )
				->where( $dbr->expr( 'page_id', '>', $lastPageId ) )
				->orderBy( 'page_id' )
				->limit( $this->getBatchSize() )
				->caller( __METHOD__ )
				->fetchResultSet();

			$jobs = [];
			foreach ( $rows as $row ) {
				$lastPageId = (int)$row->page_id;
				$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
				if ( !$inline ) {
					$jobs[] = new WandaIndexPageJob( $title );
				} elseif ( !PageIndexUpdater::updateIndex(
					$title, $services->getWikiPageFactory()->newFromTitle( $title )
				) ) {
					$failed++;
				}
				$processed++;
			}

			if ( $jobs ) {
				$services->getJobQueueGroup()->push( $jobs );
			}
		} while ( $rows->numRows() === $this->getBatchSize() );

		if ( $inline ) {
			$this->output( "Indexed $processed pages, $failed failed.\n" );
		} else {
			$this->output( "Queued $processed pages for indexing.\n" );
		}
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;
