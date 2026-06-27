<?php
/**
 * Hook to add floating chat widget to all pages
 *
 * @author  Sanjay Thiyagarajan <sanjayipscoc@gmail.com>
 * @file
 * @ingroup Wanda
 * @license MIT
 */

namespace MediaWiki\Extension\Wanda\Hooks;

use MediaWiki\Extension\Wanda\CargoQueryHandler;
use MediaWiki\Extension\Wanda\SMWQueryHandler;
use MediaWiki\MediaWikiServices;

/**
 * Hooks for adding the floating chat widget
 */
class FloatingChatHook {

	/**
	 * Add the floating chat module to all pages
	 *
	 * @param \OutputPage $out
	 * @param \Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		// Don't add floating chat to the special page itself
		if ( $out->getTitle() && $out->getTitle()->isSpecial( 'Wanda' ) ) {
			return;
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();

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
			'WandaShowPopup' => $config->get( 'WandaShowPopup' ),
			'WandaEnableAttachments' => $config->get( 'WandaEnableAttachments' ),
			'WandaDisabledSources' => $disabledSources,
			'WandaMaxImageSize' => $config->get( 'WandaMaxImageSize' ),
			'WandaMaxImageCount' => $config->get( 'WandaMaxImageCount' ),
			'WandaShowConfidenceScore' => $config->get( 'WandaShowConfidenceScore' ),
			'WandaRAGSourceNames' => array_keys( $config->get( 'WandaRAGSources' ) ?? [] ),
			'WandaCanEdit' => $config->get( 'WandaEnableEditing' )
				&& $out->getUser()->isAllowed( 'wanda-edit' )
		] );

		// Add the floating chat module to all other pages
		$out->addModules( 'ext.wanda.floating' );
	}
}
