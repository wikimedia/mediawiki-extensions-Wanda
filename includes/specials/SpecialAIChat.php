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

 namespace MediaWiki\Extension\Wanda;

 use Html;
 use SpecialPage;

/**
 * @ingroup PFSpecialPages
 */
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
		$req = $this->getRequest();

		$out->addModules( 'ext.wanda.main' );

		$out->addHTML(
			Html::rawElement(
				'div',
				[ 'id' => 'chat-bot-container' ],
			)
		);
	}

}
