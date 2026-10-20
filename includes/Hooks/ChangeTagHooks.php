<?php

namespace MediaWiki\Extension\Wanda\Hooks;

use RecentChange;

/**
 * Registers the software-defined "wanda" change tag applied to every edit that
 * Wanda saves, so those edits are identifiable in page history, RecentChanges and
 * filters regardless of who requested them (task T409213).
 */
class ChangeTagHooks {

	/** The single change tag applied to Wanda-made edits. */
	public const TAG = 'wanda';

	/**
	 * Request-scoped flag: when armed, the next RecentChanges row created in this
	 * request is a Wanda-made edit and must carry the tag. RecentChanges rows are
	 * inserted in a deferred update after the save API returns, so the tag cannot
	 * be resolved inline; the flag lets {@see self::onRecentChange_save} attach it
	 * at insertion time with the correct rc_id and rev_id associations.
	 * @var bool
	 */
	private static $tagNextRecentChange = false;

	/** Mark the next RecentChanges save in this request as a Wanda edit. */
	public static function armNextRecentChange(): void {
		self::$tagNextRecentChange = true;
	}

	/**
	 * @param array &$tags
	 * @return void
	 */
	public static function onListDefinedTags( array &$tags ): void {
		$tags[] = self::TAG;
	}

	/**
	 * @param array &$tags
	 * @return void
	 */
	public static function onChangeTagsListActive( array &$tags ): void {
		$tags[] = self::TAG;
	}

	/**
	 * Tag the RecentChanges row (and its revision) for the armed Wanda edit. The
	 * tag is appended to the row's pending tags, which core then persists with the
	 * rc_id, rev_id and log_id, so the edit is filterable in RecentChanges,
	 * Watchlist and page history alike. Disarms immediately to tag a single change.
	 *
	 * @param RecentChange $recentChange
	 * @return void
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public static function onRecentChange_save( RecentChange $recentChange ): void {
		if ( self::$tagNextRecentChange ) {
			self::$tagNextRecentChange = false;
			$recentChange->addTags( self::TAG );
		}
	}
}
