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

		// Add the floating chat module to all other pages
		$out->addModules( 'ext.wanda.floating' );
	}
}
