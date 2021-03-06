<?php

abstract class PageQualityScorer{
	const YELLOW = 1;
	const RED = 2;

	abstract public function calculatePageScore();

	public static $registered_classes = [];
	public static $settings = [];
	public static $checksList = [];
	public static $text = null;
	public static $dom = null;

	public static $general_settings = [
		"red" => [
			"name" => "pag_scorer_red_score",
			"default" => 10,
		],
		"article_types" => [
			"name" => "pag_scorer_article_type",
			"data_type" => "list",
			"default" => "",
			"dependsOnExtension" => "ArticleType"
		],
	];

	/**
	 * This is a naive word counter, which pretty much ignores anything except spaces as word
	 * delimiters. It should work fine with utf-8 strings.
	 *
	 * @param string $text
	 *
	 * @return int|void
	 */
	function str_word_count_utf8( $text ) {
		// We do the following because strtr just didn't work right in utf-8 text
		$replacements = "\n:,[]={}|*,";
		$replacements = str_split( $replacements );
		// Add the Arabic comma as well
		$replacements[] = '،';
		$text = str_replace( $replacements, ' ', $text );

		// Remove comments
		$text = preg_replace( '/<!--[\s\S]*?-->/', '', $text );
		// Remove single-character words
		$text = preg_replace( '/ . /', ' ', $text );
		// Replace any type of space with a simple single space
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return count( explode( " ", $text ) );
	}

	public static function getCheckList() {
		return static::$checksList;
	}

	public static function loadAllScoreres() {
		if ( !empty( self::$registered_classes ) ) {
			return;
		}
		foreach ( glob( __DIR__ . "/scorers/*.php") as $filename ) {
			include_once $filename;
			self::$registered_classes[] = basename($filename, '.php');
		}
	}

	public static function getSetting( $type ) {
		$setting_value = null;
		if ( array_key_exists( $type, self::getSettingValues() ) ) {
			$setting_value = self::$settings[$type];
		} else if ( array_key_exists( $type, self::$general_settings ) ) {
			$setting_value = self::$general_settings[$type]['default'];
		} else {
			$setting_value = self::getCheckList()[$type]['default'];
		}

		if ( $setting_value ) {
			$isList = ( isset( self::getCheckList()[$type][ 'data_type' ] ) && self::getCheckList()[$type][ 'data_type' ] === 'list' ) ||
			          ( isset( self::$general_settings[$type]['data_type' ] ) && self::$general_settings[$type]['data_type' ] === 'list' );

			if ( $isList ) {
				$setting_value = preg_split( '/\R/', $setting_value ); // explode by line endings
			}
		}
		return $setting_value;
	}

	public static function getAllScorers( ) {
		if ( empty( self::$registered_classes ) ) {
			self::loadAllScoreres();
		}
		return self::$registered_classes;
	}

	public static function getSettingValues() {
		if ( !empty( self::$settings ) ) {
			return self::$settings;
		}
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'pq_settings',
			'*',
			array( true ),
			__METHOD__
		);

		$all_checklist = PageQualityScorer::getAllChecksList();
		self::$settings = [];
		foreach( $res as $row ) {
			self::$settings[$row->setting] = $row->value;
			if ( array_key_exists( $row->setting, $all_checklist ) && array_key_exists( 'data_type', $all_checklist[$row->setting] ) && $all_checklist[$row->setting]['data_type'] === 'list' ) {
				self::$settings[$row->setting] = $row->value_blob ?? null;
			}
		}
		return self::$settings;
	}


	/**
	 * @param Title $title
	 *
	 * @return bool
	 */
	public static function isPageScoreable( $title ) {
		$relevantArticleTypes = PageQualityScorer::getSetting( 'article_types' );
		if ( !empty( $relevantArticleTypes ) && ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleType = \MediaWiki\Extension\ArticleType\ArticleType::getArticleType( $title );
			if ( !in_array( $articleType, $relevantArticleTypes ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $text
	 *
	 * @return DOMDocument|null
	 */
	public static function loadDOM( $text ) {
		// @todo load only actual page content. right now this will also load stuff like the "protectedpagewarning" message
		$dom = new DOMDocument('1.0', 'utf-8');
		// Unicode-compatibility - see https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $text );
		$dom->preserveWhiteSpace = false;
		self::$dom = $dom;
		self::$text = $text;
		self::removeIgnoredElements();

		return self::$dom;
	}

	/**
	 * @return string|null
	 */
	protected static function getText() {
		return self::$text;
	}

	/**
	 * @return DOMDocument|null
	 */
	protected static function getDOM() {
		return self::$dom;
	}

	public static function getAllChecksList() {
		PageQualityScorer::loadAllScoreres();
		$all_checklist = [];
		foreach( self::$registered_classes as $scorer_class ) {
			$all_checklist += $scorer_class::getCheckList();
		}
		return $all_checklist;
	}

	public static function getScorForPage( $title ) {
		PageQualityScorer::loadAllScoreres();

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'pq_issues',
			'*',
			[ 'page_id' => $title->getArticleID()],
			__METHOD__
		);

		$score = 0;
		$responses = [];
		foreach( $res as $row ) {
			$responses[$row->pq_type][] = [
				'example' => $row->example,
				'score' => $row->score
			];
			$score += $row->score;
		}
		return [$score, $responses];
	}

	public static function runScorerForPage( $title, $page_html = "", $automated_run = false ) {
		if ( empty( $page_html ) ) {
			$pageObj = WikiPage::factory( $title );
			$page_html = $pageObj->getContent( Revision::RAW )->getParserOutput( $title )->getText();
		}
		PageQualityScorer::loadAllScoreres();
		list( $score, $responses ) = PageQualityScorer::runAllScoreres( $page_html );

		$dbw = wfGetDB( DB_MASTER );
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'pq_issues',
			'score',
			[ 'page_id' => $title->getArticleID()],
			__METHOD__
		);
		$old_score = 0;
		if ( $res->numRows() > 0 ) {
			foreach( $res as $row ) {
				$old_score += $row->score;
			}
		}
		$dbw->delete(
			'pq_score',
			array( 'page_id' => $title->getArticleID() ),
			__METHOD__
		);
		$dbw->delete(
			'pq_issues',
			array( 'page_id' => $title->getArticleID() ),
			__METHOD__
		);

		if ( !self::isPageScoreable( $title ) ) {
			return [ 0, []];
		}

		foreach( $responses as $type => $type_responses ) {
			foreach( $type_responses as $response ) {
				$dbw->insert(
					'pq_issues',
					[
						'page_id' => $title->getArticleID(),
						'pq_type' => $type,
						'score'   => $response['score'],
						'example' => $response['example']
					],
					__METHOD__,
					array( 'IGNORE' )
				);
			}
		}

		if ( !$automated_run && abs( $old_score - $score ) > 1 ) {
			$dbw->insert(
				'pq_score_log',
				[
					'page_id'     => $title->getArticleID(),
					'revision_id' => $title->getLatestRevID(),
					'new_score'   => $score,
					'old_score'   => $old_score,
					'timestamp' => $dbw->timestamp()
				],
				__METHOD__,
				array( 'IGNORE' )
			);
		}
		$dbw->insert(
			'pq_score',
			[
				'page_id'     => $title->getArticleID(),
				'score'   => $score
			],
			__METHOD__,
			array( 'IGNORE' )
		);

		return [$score, $responses];
	}

	public static function runAllScoreres( $text ) {
		self::loadDOM( $text );
		$responses = [];
		foreach( self::$registered_classes as $scorer_class ) {
			$scorer_obj = new $scorer_class();
			$responses += $scorer_obj->calculatePageScore();
		}

		$score = 0;
		foreach( $responses as $type => $type_responses ) {
			foreach( $type_responses as $response ) {
				$score += $response['score'];
			}
		}
		return [ $score, $responses ];
	}

	protected static function getElementsByClassName( DOMDocument $dom, $className ) {
		$xpath = new DOMXpath( $dom );
		$expression = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $className . ' ")]';
		return $xpath->query( $expression );
	}

	protected static function removeIgnoredElements () {
		$ignoredElements = self::getElementsByClassName( self::getDOM(), 'pagequality-ignore' );
		foreach ( $ignoredElements as $element ) {
			$element->parentNode->removeChild( $element );
		}
	}

}
