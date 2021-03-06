<?php

use MediaWiki\MediaWikiServices;

class PageQualityHooks {

	public static function onPageSaveComplete( WikiPage $wikiPage, MediaWiki\User\UserIdentity $user, string $summary, int $flags, MediaWiki\Revision\RevisionRecord $revisionRecord, MediaWiki\Storage\EditResult $editResult ) {
		if ( PageQualityScorer::isPageScoreable( $wikiPage->getTitle() ) ) {
			list( $score, $responses ) = PageQualityScorer::runScorerForPage( $wikiPage->getTitle() );
		}
	}

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		if ( MediaWikiServices::getInstance()->getPermissionManager()->userHasRight( $out->getUser(), 'viewpagequality' ) ) {
			if ( PageQualityScorer::isPageScoreable( $out->getTitle() ) ) {
				list( $score, $responses ) = PageQualityScorer::getScorForPage( $out->getTitle() );

				$link = Html::rawElement(
					'a',
					[
						'href' => '#',
						'data-target' => '#pagequality-sidebar'
					],
					$out->msg( 'pq_quality_score_link' )->escaped(
					) . ' <span class="badge">' . $score . '</span>'
				);

				$out->setIndicators( [ 'pq_status' => $link ] );
				$out->addModules( 'ext.page_quality' );
			}
		}
	}

	/**
	 * LoadExtensionSchemaUpdate Hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdate
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdate( DatabaseUpdater $updater ) {
		$dir = __DIR__ . '/sql';

		$updater->addExtensionTable( 'pq_issues', "$dir/pq_issues.sql" );
		$updater->addExtensionTable( 'pq_settings', "$dir/pq_settings.sql" );
		$updater->addExtensionTable( 'pq_score', "$dir/pq_score.sql" );
		$updater->addExtensionTable( 'pq_score_log', "$dir/pq_score_log.sql" );
		$updater->addExtensionField( 'pq_settings', 'value_blob', "$dir/pq_settings_patch_add_value_blob.sql" );
		$updater->modifyExtensionField( 'pq_score_log', 'timestamp', "$dir/pq_score_log_patch_change_timestamp.sql" );
	}

}
