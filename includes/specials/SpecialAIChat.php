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

		$out->addJsConfigVars( [
			'WandaMaxImageSize' => $config->get( 'WandaMaxImageSize' ),
			'WandaMaxImageCount' => $config->get( 'WandaMaxImageCount' )
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
