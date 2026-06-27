<?php
/**
 * A special page that allows the user to interact with the chatbot
 *
 * @author  Sanjay Thiyagarajan <sanjayipscoc@gmail.com>
 * @file
 * @ingroup Wanda
 * @category specialpage
 * @license MIT
 */

namespace MediaWiki\Extension\Wanda\Specials;

use MediaWiki\Extension\Wanda\CargoQueryHandler;
use MediaWiki\Extension\Wanda\SMWQueryHandler;
use MediaWiki\Html\Html;
use SpecialPage;

class SpecialAIChat extends SpecialPage {

	public function __construct() {
		parent::__construct( 'Wanda' );
	}

	/**
	 * Execute API action
	 * @param mixed $query
	 * @return void
	 */
	public function execute( $query ) {
		$this->setHeaders();

		$out = $this->getOutput();
		$config = $this->getConfig();

		// Hide structured-data sources whose backing extension is not installed, so
		// the source checkbox area only offers sources the wiki can actually query.
		$disabledSources = $config->get( 'WandaDisabledSources' ) ?? [];
		if ( !SMWQueryHandler::isSMWAvailable() && !in_array( 'smw', $disabledSources, true ) ) {
			$disabledSources[] = 'smw';
		}
		if ( !CargoQueryHandler::isCargoAvailable() && !in_array( 'cargo', $disabledSources, true ) ) {
			$disabledSources[] = 'cargo';
		}

		$out->addJsConfigVars( [
			'WandaEnableAttachments' => $config->get( 'WandaEnableAttachments' ),
			'WandaDisabledSources' => $disabledSources,
			'WandaMaxImageSize' => $config->get( 'WandaMaxImageSize' ),
			'WandaMaxImageCount' => $config->get( 'WandaMaxImageCount' ),
			'WandaShowConfidenceScore' => $config->get( 'WandaShowConfidenceScore' ),
			'WandaRAGSourceNames' => array_keys( $config->get( 'WandaRAGSources' ) ?? [] ),
			'WandaCanEdit' => $config->get( 'WandaEnableEditing' )
				&& $this->getUser()->isAllowed( 'wanda-edit' )
		] );

		$out->addModules( 'ext.wanda.main' );

		$out->addHTML(
			Html::rawElement(
				'div',
				[ 'id' => 'chat-bot-container' ],
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}

}
