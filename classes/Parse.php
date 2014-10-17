<?php
/**
 * DynamicPageList
 * DPL Parse Class
 *
 * @author		IlyaHaykinson, Unendlich, Dangerville, Algorithmix, Theaitetos, Alexia E. Smith
 * @license		GPL
 * @package		DynamicPageList
 *
 **/
namespace DPL;

class Parse {
	/**
	 * Mediawiki Database Object
	 *
	 * @var		object
	 */
	private $DB = null;

	/**
	 * \DPL\Parameters Object
	 *
	 * @var		object
	 */
	private $parameters = null;

	/**
	 * \DPL\Logger Object
	 *
	 * @var		object
	 */
	private $logger = null;

	/**
	 * Array of prequoted table names.
	 *
	 * @var		object
	 */
	private $tableNames = null;

	/**
	 * Main Constructor
	 *
	 * @access	public
	 * @return	void
	 */
	public function __construct() {
		$this->DB = wfGetDB(DB_SLAVE);
		$this->parameters = new Parameters();
		$this->logger = new Logger();
		$this->tableNames = Query::getTableNames();
		$this->getUrlArgs();
	}

	/**
	 * The real callback function for converting the input text to wiki text output
	 *
	 * @access	public
	 * @param	
	 * @return	string	Wiki/HTML Output
	 */
	public function parse($input, $params, $parser, &$bReset, $calledInMode) {
		global $wgUser, $wgLang, $wgContLang, $wgRequest, $wgNonincludableNamespaces;

		wfProfileIn(__METHOD__);

		//Check that we are not in an infinite transclusion loop
		if (isset($parser->mTemplatePath[$parser->mTitle->getPrefixedText()])) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_TRANSCLUSIONLOOP, $parser->mTitle->getPrefixedText());
			return $this->logger->getMessages();
		}

		//Check if DPL shall only be executed from protected pages.
		if (Config::getSetting('runFromProtectedPagesOnly') === true && !$parser->mTitle->isProtected('edit')) {
			//Ideally we would like to allow using a DPL query if the query istelf is coded on a template page which is protected. Then there would be no need for the article to be protected.  However, how can one find out from which wiki source an extension has been invoked???
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_NOTPROTECTED, $parser->mTitle->getPrefixedText());
			return $this->logger->getMessages();
		}

		if (strpos($input, '{%DPL_') >= 0) {
			for ($i = 1; $i <= 5; $i++) {
				$input = self::resolveUrlArg($input, 'DPL_arg' . $i);
			}
		}

		$offset = $wgRequest->getInt('DPL_offset', $this->parameters->getData('offset')['default']);

		// commandline parameters like %DPL_offset% are replaced
		$input = self::resolveUrlArg($input, 'DPL_offset');
		$input = self::resolveUrlArg($input, 'DPL_count');
		$input = self::resolveUrlArg($input, 'DPL_fromTitle');
		$input = self::resolveUrlArg($input, 'DPL_findTitle');
		$input = self::resolveUrlArg($input, 'DPL_toTitle');

		$originalInput = $input;

		$bDPLRefresh = ($wgRequest->getVal('DPL_refresh', '') == 'yes');

		//Options
		$DPLCache        = '';
		$DPLCachePath    = '';

		//Array for LINK / TEMPLATE / CATGEORY / IMAGE by RESET / ELIMINATE
		if (Options::$options['eliminate'] == 'all') {
			$bReset = array(
				false,
				false,
				false,
				false,
				true,
				true,
				true,
				true
			);
		} else {
			$bReset = array(
				false,
				false,
				false,
				false,
				false,
				false,
				false,
				false
			);
		}

		/***************************************/
		/* User Input preparation and parsing. */
		/***************************************/
		$rawParameters	= $this->prepareUserInput($input);
		$bIncludeUncat = false; // to check if pseudo-category of Uncategorized pages is included

		foreach ($rawParameters as $key => $parameterOption) {
			if (strpos($parameterOption, '=') === false) {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_UNKNOWNPARAM, $parameter." [missing '=']");
				continue;
			}

			list($parameter, $option) = explode('=', $parameterOption, 2);
			$parameter = trim($parameter);
			$option  = trim($option);

			if (strpos($parameter, '<') !== false || strpos($parameter, '>') !== false) {
				//Having the actual less than and greater than symbols is nasty for programatic look up.  The old parameter is still supported along with the new, but we just fix it here before calling it.
				$parameter = str_replace('<', 'lt', $parameter);
				$parameter = str_replace('>', 'gt', $parameter);
			}

			if (empty($parameter) || substr($parameter, 0, 1) == '#' || ($this->parameters->exists($parameter) && !$this->testRichness($parameter))) {
				continue;
			}

			if (!$this->parameters->exists($parameter)) {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_UNKNOWNPARAM, $parameter);
			}

			//Ignore parameter settings without argument (except namespace and category)
			if (empty($option)) {
				if ($parameter != 'namespace' && $parameter != 'notnamespace' && $parameter != 'category' && $this->parameters->exists($parameter)) {
					continue;
				}
			}

			//Parameter functions return true or false.  The full parameter data will be passed into the Query object later.
			if ($this->parameters->$function($option) === false) {
				//Do not build this into the output just yet.  It will be collected at the end.
				$this->logger->addMessage(\DynamicPageListHooks::WARN_WRONGPARAM, $parameter, $option);
			}
		}

		/*************************/
		/* Execute and Exit Only */
		/*************************/
		if ($this->parameters->getParameter('execandexit')) {
			//@TODO: Fix up this parameter's arguments in ParameterData and handle it handles the response.
			//The keyword "geturlargs" is used to return the Url arguments and do nothing else.
			if ($sExecAndExit == 'geturlargs') {
				return '';
			}
			//In all other cases we return the value of the argument (which may contain parser function calls)
			return $sExecAndExit;
		}



		/*******************/
		/* Are we caching? */
		/*******************/
		if (!$this->parameters->getParameter('allowcachedresults')) {
			$parser->disableCache();
		}
		if ($this->parameters->getParameter('warncachedresults')) {
			$resultsHeader = '{{DPL Cache Warning}}'.$resultsHeader;
		}
		global $wgUploadDirectory, $wgRequest;
		if ($DPLCache != '') {
			$cacheFile = "$wgUploadDirectory/dplcache/$DPLCachePath/$DPLCache";
			// when the page containing the DPL statement is changed we must recreate the cache as the DPL statement may have changed
			// when the page containing the DPL statement is changed we must recreate the cache as the DPL statement may have changed
			// otherwise we accept thecache if it is not too old
			if (!$bDPLRefresh && file_exists($cacheFile)) {
				// find out if cache is acceptable or too old
				$diff = time() - filemtime($cacheFile);
				if ($diff <= $iDPLCachePeriod) {
					$cachedOutput    = file_get_contents($cacheFile);
					$cachedOutputPos = strpos($cachedOutput, "+++\n");
					// when submitting a page we check if the DPL statement has changed
					if ($wgRequest->getVal('action', 'view') != 'submit' || ($originalInput == substr($cachedOutput, 0, $cachedOutputPos))) {
						$cacheTimeStamp = self::prettyTimeStamp(date('YmdHis', filemtime($cacheFile)));
						$cachePeriod    = self::durationTime($iDPLCachePeriod);
						$diffTime       = self::durationTime($diff);
						$output .= substr($cachedOutput, $cachedOutputPos + 4);
						if ($this->logger->iDebugLevel >= 2) {
							$output .= "{{Extension DPL cache|mode=get|page={{FULLPAGENAME}}|cache=$DPLCache|date=$cacheTimeStamp|now=" . date('H:i:s') . "|age=$diffTime|period=$cachePeriod|offset=$offset}}";
						}
						// ignore further parameters, stop processing, return cache content
						return $output;
					}
				}
			}
		}

		// debug level 5 puts nowiki tags around the output
		if ($this->logger->iDebugLevel == 5) {
			$this->logger->iDebugLevel = 2;
			$resultsHeader      = '<pre><nowiki>' . $resultsHeader;
			$sResultsFooter .= '</nowiki></pre>';
		}

		// construct internal keys for TableRow according to the structure of "include"
		// this will be needed in the output phase
		self::updateTableRowKeys($aTableRow, $aSecLabels);
		// foreach ($aTableRow as $key => $val) $output .= "TableRow($key)=$val;<br/>";

		$iIncludeCatCount      = count($aIncludeCategories);
		$iTotalIncludeCatCount = count($aIncludeCategories, COUNT_RECURSIVE) - $iIncludeCatCount;
		$iExcludeCatCount      = count($aExcludeCategories);
		$iTotalCatCount        = $iTotalIncludeCatCount + $iExcludeCatCount;

		if ($calledInMode == 'tag') {
			// in tag mode 'eliminate' is the same as 'reset' for tpl,cat,img
			if ($bReset[5]) {
				$bReset[1] = true;
				$bReset[5] = false;
			}
			if ($bReset[6]) {
				$bReset[2] = true;
				$bReset[6] = false;
			}
			if ($bReset[7]) {
				$bReset[3] = true;
				$bReset[7] = false;
			}
		} else {
			if ($bReset[1]) {
				\DynamicPageListHooks::$createdLinks['resetTemplates'] = true;
			}
			if ($bReset[2]) {
				\DynamicPageListHooks::$createdLinks['resetCategories'] = true;
			}
			if ($bReset[3]) {
				\DynamicPageListHooks::$createdLinks['resetImages'] = true;
			}
		}
		if (($calledInMode == 'tag' && $bReset[0]) || $calledInMode == 'func') {
			if ($bReset[0]) {
				\DynamicPageListHooks::$createdLinks['resetLinks'] = true;
			}
			// register a hook to reset links which were produced during parsing DPL output
			global $wgHooks;
			if (!in_array('DynamicPageListHooks::endReset', $wgHooks['ParserAfterTidy'])) {
				$wgHooks['ParserAfterTidy'][] = 'DynamicPageListHooks::endReset';
			}
		}


		// ###### CHECKS ON PARAMETERS ######

		// too many categories!
		if (($iTotalCatCount > \DynamicPageListHooks::$maxCategoryCount) && (!\DynamicPageListHooks::$allowUnlimitedCategories)) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_TOOMANYCATS, \DynamicPageListHooks::$maxCategoryCount);
		}

		// too few categories!
		if ($iTotalCatCount < \DynamicPageListHooks::$minCategoryCount) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_TOOFEWCATS, \DynamicPageListHooks::$minCategoryCount);
		}

		// no selection criteria! Warn only if no debug level is set
		if ($iTotalCatCount == 0 && $bSelectionCriteriaFound == false) {
			if ($this->logger->iDebugLevel <= 1) {
				return $output;
			}
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_NOSELECTION);
		}

		// ordermethod=sortkey requires ordermethod=category
		// delayed to the construction of the SQL query, see near line 2211, gs
		//if (in_array('sortkey',$aOrderMethods) && ! in_array('category',$aOrderMethods)) $aOrderMethods[] = 'category';

		// no included categories but ordermethod=categoryadd or addfirstcategorydate=true!
		if ($iTotalIncludeCatCount == 0 && ($aOrderMethods[0] == 'categoryadd' || $bAddFirstCategoryDate == true)) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_CATDATEBUTNOINCLUDEDCATS);
		}

		// more than one included category but ordermethod=categoryadd or addfirstcategorydate=true!
		// we ALLOW this parameter combination, risking ambiguous results
		//if ($iTotalIncludeCatCount > 1 && ($aOrderMethods[0] == 'categoryadd' || $bAddFirstCategoryDate == true) )
		//	return $this->logger->addMessage(\DynamicPageListHooks::FATAL_CATDATEBUTMORETHAN1CAT);

		// no more than one type of date at a time!
		if ($bAddPageTouchedDate + $bAddFirstCategoryDate + $bAddEditDate > 1) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_MORETHAN1TYPEOFDATE);
		}

		// the dominant section must be one of the sections mentioned in includepage
		if ($iDominantSection > 0 && count($aSecLabels) < $iDominantSection) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_DOMINANTSECTIONRANGE, count($aSecLabels));
		}

		// category-style output requested with not compatible order method
		if ($sPageListMode == 'category' && !array_intersect($aOrderMethods, array(
			'sortkey',
			'title',
			'titlewithoutnamespace'
		))) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'mode=category', 'sortkey | title | titlewithoutnamespace');
		}

		// addpagetoucheddate=true with unappropriate order methods
		if ($bAddPageTouchedDate && !array_intersect($aOrderMethods, array(
			'pagetouched',
			'title'
		))) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'addpagetoucheddate=true', 'pagetouched | title');
		}

		// addeditdate=true but not (ordermethod=...,firstedit or ordermethod=...,lastedit)
		//firstedit (resp. lastedit) -> add date of first (resp. last) revision
		if ($bAddEditDate && !array_intersect($aOrderMethods, array(
			'firstedit',
			'lastedit'
		)) & ($sLastRevisionBefore . $sAllRevisionsBefore . $sFirstRevisionSince . $sAllRevisionsSince == '')) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'addeditdate=true', 'firstedit | lastedit');
		}

		// adduser=true but not (ordermethod=...,firstedit or ordermethod=...,lastedit)
		/**
		 * @todo allow to add user for other order methods.
		 * The fact is a page may be edited by multiple users. Which user(s) should we show? all? the first or the last one?
		 * Ideally, we could use values such as 'all', 'first' or 'last' for the adduser parameter.
		 */
		if ($bAddUser && !array_intersect($aOrderMethods, array(
			'firstedit',
			'lastedit'
		)) & ($sLastRevisionBefore . $sAllRevisionsBefore . $sFirstRevisionSince . $sAllRevisionsSince == '')) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'adduser=true', 'firstedit | lastedit');
		}
		if (isset($sMinorEdits) && !array_intersect($aOrderMethods, array(
			'firstedit',
			'lastedit'
		))) {
			return $this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'minoredits', 'firstedit | lastedit');
		}

		/**
		 * If we include the Uncategorized, we need the 'dpl_clview': VIEW of the categorylinks table where we have cl_to='' (empty string) for all uncategorized pages. This VIEW must have been created by the administrator of the mediawiki DB at installation. See the documentation.
		 */
		if ($bIncludeUncat) {
			// If the view is not there, we can't perform logical operations on the Uncategorized.
			if (!self::$DB->tableExists('dpl_clview')) {
				$sSqlCreate_dpl_clview = 'CREATE VIEW ' . $this->tableNames['dpl_clview'] . " AS SELECT IFNULL(cl_from, page_id) AS cl_from, IFNULL(cl_to, '') AS cl_to, cl_sortkey FROM " . $this->tableNames['page'] . ' LEFT OUTER JOIN ' . $this->tableNames['categorylinks'] . ' ON ' . $this->tableNames['page'] . '.page_id=cl_from';
				$this->logger->addMessage(\DynamicPageListHooks::FATAL_NOCLVIEW, $this->tableNames['dpl_clview'], $sSqlCreate_dpl_clview);
				return $output;
			}
		}

		//add*** parameters have no effect with 'mode=category' (only namespace/title can be viewed in this mode)
		if ($sPageListMode == 'category' && ($bAddCategories || $bAddEditDate || $bAddFirstCategoryDate || $bAddPageTouchedDate || $bIncPage || $bAddUser || $bAddAuthor || $bAddContribution || $bAddLastEditor)) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_CATOUTPUTBUTWRONGPARAMS);
		}

		//headingmode has effects with ordermethod on multiple components only
		if ($sHListMode != 'none' && count($aOrderMethods) < 2) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_HEADINGBUTSIMPLEORDERMETHOD, $sHListMode, 'none');
			$sHListMode = 'none';
		}

		// openreferences is incompatible with many other options
		if ($acceptOpenReferences && $bConflictsWithOpenReferences) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_OPENREFERENCES);
			$acceptOpenReferences = false;
		}

		// backward scrolling: if the user specified titleLE and wants ascending order we reverse the SQL sort order
		if ($sTitleLE != '' && $sTitleGE == '') {
			if ($sOrder == 'ascending') {
				$sOrder = 'descending';
			}
		}

		$output .= '{{Extension DPL}}';



		// ###### BUILD SQL QUERY ######
		$sSqlPage_counter  = '';
		$sSqlPage_size     = '';
		$sSqlPage_touched  = '';
		$sSqlCalcFoundRows = '';
		if (!\DynamicPageListHooks::$allowUnlimitedResults && $sGoal != 'categories' && strpos($resultsHeader . $sResultsFooter . $sNoResultsHeader, '%TOTALPAGES%') !== false) {
			$sSqlCalcFoundRows = 'SQL_CALC_FOUND_ROWS';
		}
		if ($sDistinctResultSet === false) {
			$sSqlDistinct = '';
		} else {
			$sSqlDistinct = 'DISTINCT';
		}
		$sSqlGroupBy = '';
		if ($sDistinctResultSet == 'strict' && (count($aLinksTo) + count($aNotLinksTo) + count($aLinksFrom) + count($aNotLinksFrom) + count($aLinksToExternal) + count($aImageUsed)) > 0) {
			$sSqlGroupBy = 'page_title';
		}

		$sSqlWhere              = ' WHERE 1=1 ';
		$sSqlSelPage            = ''; // initial page for selection

		// normally we create a result of normal pages, but when goal=categories is set, we create a list of categories
		// as this conflicts with some options we need to avoid producing incoorect SQl code
		$bGoalIsPages = true;
		if ($sGoal == 'categories') {
			$aOrderMethods = explode(',', '');
			$bGoalIsPages  = false;
		}



		// recent changes  =============================

		if ($sLastRevisionBefore . $sAllRevisionsBefore . $sFirstRevisionSince . $sAllRevisionsSince != '') {
			$sSqlRevisionTable = $this->tableNames['revision'] . ' AS rev, ';
			$sSqlRev_timestamp = ', rev_timestamp';
			$sSqlRev_id        = ', rev_id';


		}

		// SELECT ... FROM
		if ($acceptOpenReferences) {
			// SELECT ... FROM
			if (count($aImageContainer) > 0) {
				$sSqlSelectFrom = "SELECT $sSqlCalcFoundRows $sSqlDistinct " . $sSqlCl_to . 'ic.il_to, ' . $sSqlSelPage . "ic.il_to AS sortkey" . ' FROM ' . $this->tableNames['imagelinks'] . ' AS ic';
			} else {
				$sSqlSelectFrom = "SELECT $sSqlCalcFoundRows $sSqlDistinct " . $sSqlCl_to . 'pl_namespace, pl_title' . $sSqlSelPage . $sSqlSortkey . ' FROM ' . $this->tableNames['pagelinks'];
			}
		} else {
			$sSqlSelectFrom = "SELECT $sSqlCalcFoundRows $sSqlDistinct " . $sSqlCl_to . $this->tableNames['page'] . '.page_namespace AS page_namespace,' . $this->tableNames['page'] . '.page_title AS page_title,' . $this->tableNames['page'] . '.page_id AS page_id' . $sSqlSelPage . $sSqlSortkey . $sSqlPage_counter . $sSqlPage_size . $sSqlPage_touched . $sSqlRev_user . $sSqlRev_timestamp . $sSqlRev_id . $sSqlCats . $sSqlCl_timestamp . ' FROM ' . $sSqlRevisionTable . $sSqlCreationRevisionTable . $sSqlNoCreationRevisionTable . $sSqlChangeRevisionTable . $sSqlRCTable . $sSqlPageLinksTable . $sSqlExternalLinksTable . $this->tableNames['page'];
		}

		// JOIN ...
		if ($sSqlClHeadTable != '' || $sSqlClTableForGC != '') {
			$b2tables = ($sSqlClHeadTable != '') && ($sSqlClTableForGC != '');
			$sSqlSelectFrom .= ' LEFT OUTER JOIN ' . $sSqlClHeadTable . ($b2tables ? ', ' : '') . $sSqlClTableForGC . ' ON (' . $sSqlCond_page_cl_head . ($b2tables ? ' AND ' : '') . $sSqlCond_page_cl_gc . ')';
		}

		// count(all categories) <= max no of categories
		$sSqlWhere .= $sSqlCond_MaxCat;

		// check against forbidden namespaces
		if (is_array($wgNonincludableNamespaces) && array_count_values($wgNonincludableNamespaces) > 0 && implode(',', $wgNonincludableNamespaces) != '') {
			$sSqlWhere .= ' AND ' . $this->tableNames['page'] . '.page_namespace NOT IN (' . implode(',', $wgNonincludableNamespaces) . ')';
		}

		// GROUP BY ...
		if ($sSqlGroupBy != '') {
			$sSqlWhere .= ' GROUP BY ' . $sSqlGroupBy . ' ';
		}


		if ($sAllRevisionsSince != '' || $sAllRevisionsBefore != '') {
			if ($aOrderMethods[0] == '' || $aOrderMethods[0] == 'none') {
				$sSqlWhere .= ' ORDER BY ';
			} else {
				$sSqlWhere .= ', ';
			}
			$sSqlWhere .= 'rev_id DESC';
		}

		// when we go for a list of categories as result we transform the output of the normal query into a subquery
		// of a selection on the categorylinks

		if ($sGoal == 'categories') {
			$sSqlSelectFrom = 'SELECT DISTINCT cl3.cl_to FROM ' . $this->tableNames['categorylinks'] . ' AS cl3 WHERE cl3.cl_from IN ( ' . preg_replace('/SELECT +DISTINCT +.* FROM /', 'SELECT DISTINCT ' . $this->tableNames['page'] . '.page_id FROM ', $sSqlSelectFrom);
			if ($sOrder == 'descending') {
				$sSqlWhere .= ' ) ORDER BY cl3.cl_to DESC';
			} else {
				$sSqlWhere .= ' ) ORDER BY cl3.cl_to ASC';
			}
		}


		// ###### DUMP SQL QUERY ######
		if ($this->logger->iDebugLevel >= 3) {
			//DEBUG: output SQL query
			$output .= "DPL debug -- Query=<br />\n<tt>" . $sSqlSelectFrom . $sSqlWhere . "</tt>\n\n";
		}

		// Do NOT proces the SQL command if debug==6; this is useful if the SQL statement contains bad code
		if ($this->logger->iDebugLevel == 6) {
			return $output;
		}


		// ###### PROCESS SQL QUERY ######
		$queryError = false;
		try {
			$res = self::$DB->query($sSqlSelectFrom . $sSqlWhere);
		}
		catch (Exception $e) {
			$queryError = true;
		}
		if ($queryError == true || $res === false) {
			$result = "The DPL extension (version " . DPL_VERSION . ") produced a SQL statement which lead to a Database error.<br/>\n
The reason may be an internal error of DPL or an error which you made, especially when using DPL options like 'categoryregexp' or 'titleregexp'.  Usage of non-greedy *? matching patterns are not supported.<br/>\n
Error message was:<br />\n<tt>" . self::$DB->lastError() . "</tt>\n\n";
			return $result;
		}

		if (self::$DB->numRows($res) <= 0) {
			$header = str_replace('%TOTALPAGES%', '0', str_replace('%PAGES%', '0', $sNoResultsHeader));
			if ($sNoResultsHeader != '') {
				$output .= str_replace('\n', "\n", str_replace("¶", "\n", $header));
			}
			$footer = str_replace('%TOTALPAGES%', '0', str_replace('%PAGES%', '0', $sNoResultsFooter));
			if ($sNoResultsFooter != '') {
				$output .= str_replace('\n', "\n", str_replace("¶", "\n", $footer));
			}
			if ($sNoResultsHeader == '' && $sNoResultsFooter == '') {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_NORESULTS);
			}
			self::$DB->freeResult($res);
			return $output;
		}

		// generate title for Special:Contributions (used if adduser=true)
		$sSpecContribs = '[[:Special:Contributions|Contributions]]';

		$aHeadings = array(); // maps heading to count (# of pages under each heading)
		$aArticles = array();

		if (isset($iRandomCount) && $iRandomCount > 0) {
			$nResults = self::$DB->numRows($res);
			//mt_srand() seeding was removed due to PHP 5.2.1 and above no longer generating the same sequence for the same seed.
			if ($iRandomCount > $nResults) {
				$iRandomCount = $nResults;
			}

			//This is 50% to 150% faster than the old while (true) version that could keep rechecking the same random key over and over again.
			$pick = range(1, $nResults);
			shuffle($pick);
			$pick = array_slice($pick, 0, $iRandomCount);
		}

		$iArticle            = 0;
		$firstNamespaceFound = '';
		$firstTitleFound     = '';
		$lastNamespaceFound  = '';
		$lastTitleFound      = '';

		foreach ($res as $row) {
			$iArticle++;

			// in random mode skip articles which were not chosen
			if (isset($iRandomCount) && $iRandomCount > 0 && !in_array($iArticle, $pick)) {
				continue;
			}

			if ($sGoal == 'categories') {
				$pageNamespace = 14; // CATEGORY
				$pageTitle     = $row->cl_to;
			} else if ($acceptOpenReferences) {
				if (count($aImageContainer) > 0) {
					$pageNamespace = NS_FILE;
					$pageTitle     = $row->il_to;
				} else {
					// maybe non-existing title
					$pageNamespace = $row->pl_namespace;
					$pageTitle     = $row->pl_title;
				}
			} else {
				// existing PAGE TITLE
				$pageNamespace = $row->page_namespace;
				$pageTitle     = $row->page_title;
			}

			// if subpages are to be excluded: skip them
			if (!$bIncludeSubpages && (!(strpos($pageTitle, '/') === false))) {
				continue;
			}

			$title     = \Title::makeTitle($pageNamespace, $pageTitle);
			$thisTitle = $parser->getTitle();

			// block recursion: avoid to show the page which contains the DPL statement as part of the result
			if ($bSkipThisPage && $thisTitle->equals($title)) {
				// $output.= 'BLOCKED '.$thisTitle->getText().' DUE TO RECURSION'."\n";
				continue;
			}

			$dplArticle = new Article($title, $pageNamespace);
			//PAGE LINK
			$sTitleText = $title->getText();
			if ($bShowNamespace) {
				$sTitleText = $title->getPrefixedText();
			}
			if ($aReplaceInTitle[0] != '') {
				$sTitleText = preg_replace($aReplaceInTitle[0], $aReplaceInTitle[1], $sTitleText);
			}

			//chop off title if "too long"
			if (isset($iTitleMaxLen) && (strlen($sTitleText) > $iTitleMaxLen)) {
				$sTitleText = substr($sTitleText, 0, $iTitleMaxLen) . '...';
			}
			if ($bShowCurID && isset($row->page_id)) {
				$articleLink = '[{{fullurl:' . $title->getText() . '|curid=' . $row->page_id . '}} ' . htmlspecialchars($sTitleText) . ']';
			} else if (!$bEscapeLinks || ($pageNamespace != NS_CATEGORY && $pageNamespace != NS_FILE)) {
				// links to categories or images need an additional ":"
				$articleLink = '[[' . $title->getPrefixedText() . '|' . $wgContLang->convert($sTitleText) . ']]';
			} else {
				$articleLink = '[{{fullurl:' . $title->getText() . '}} ' . htmlspecialchars($sTitleText) . ']';
			}

			$dplArticle->mLink = $articleLink;

			//get first char used for category-style output
			if (isset($row->sortkey)) {
				$dplArticle->mStartChar = $wgContLang->convert($wgContLang->firstChar($row->sortkey));
			}
			if (isset($row->sortkey)) {
				$dplArticle->mStartChar = $wgContLang->convert($wgContLang->firstChar($row->sortkey));
			} else {
				$dplArticle->mStartChar = $wgContLang->convert($wgContLang->firstChar($pageTitle));
			}

			// page_id
			if (isset($row->page_id)) {
				$dplArticle->mID = $row->page_id;
			} else {
				$dplArticle->mID = 0;
			}

			// external link
			if (isset($row->el_to)) {
				$dplArticle->mExternalLink = $row->el_to;
			}

			//SHOW PAGE_COUNTER
			if (isset($row->page_counter)) {
				$dplArticle->mCounter = $row->page_counter;
			}

			//SHOW PAGE_SIZE
			if (isset($row->page_len)) {
				$dplArticle->mSize = $row->page_len;
			}
			//STORE initially selected PAGE
			if (count($aLinksTo) > 0 || count($aLinksFrom) > 0) {
				if (!isset($row->sel_title)) {
					$dplArticle->mSelTitle     = 'unknown page';
					$dplArticle->mSelNamespace = 0;
				} else {
					$dplArticle->mSelTitle     = $row->sel_title;
					$dplArticle->mSelNamespace = $row->sel_ns;
				}
			}

			//STORE selected image
			if (count($aImageUsed) > 0) {
				if (!isset($row->image_sel_title)) {
					$dplArticle->mImageSelTitle = 'unknown image';
				} else {
					$dplArticle->mImageSelTitle = $row->image_sel_title;
				}
			}

			if ($bGoalIsPages) {
				//REVISION SPECIFIED
				if ($sLastRevisionBefore . $sAllRevisionsBefore . $sFirstRevisionSince . $sAllRevisionsSince != '') {
					$dplArticle->mRevision = $row->rev_id;
					$dplArticle->mUser     = $row->rev_user_text;
					$dplArticle->mDate     = $row->rev_timestamp;
				}

				//SHOW "PAGE_TOUCHED" DATE, "FIRSTCATEGORYDATE" OR (FIRST/LAST) EDIT DATE
				if ($bAddPageTouchedDate) {
					$dplArticle->mDate = $row->page_touched;
				} elseif ($bAddFirstCategoryDate) {
					$dplArticle->mDate = $row->cl_timestamp;
				} elseif ($bAddEditDate && isset($row->rev_timestamp)) {
					$dplArticle->mDate = $row->rev_timestamp;
				} elseif ($bAddEditDate && isset($row->page_touched)) {
					$dplArticle->mDate = $row->page_touched;
				}

				// time zone adjustment
				if ($dplArticle->mDate != '') {
					$dplArticle->mDate = $wgLang->userAdjust($dplArticle->mDate);
				}

				if ($dplArticle->mDate != '' && $sUserDateFormat != '') {
					// we apply the userdateformat
					$dplArticle->myDate = gmdate($sUserDateFormat, wfTimeStamp(TS_UNIX, $dplArticle->mDate));
				}
				// CONTRIBUTION, CONTRIBUTOR
				if ($bAddContribution) {
					$dplArticle->mContribution = $row->contribution;
					$dplArticle->mContributor  = $row->contributor;
					$dplArticle->mContrib      = substr('*****************', 0, round(log($row->contribution)));
				}


				//USER/AUTHOR(S)
				// because we are going to do a recursive parse at the end of the output phase
				// we have to generate wiki syntax for linking to a user´s homepage
				if ($bAddUser || $bAddAuthor || $bAddLastEditor || $sLastRevisionBefore . $sAllRevisionsBefore . $sFirstRevisionSince . $sAllRevisionsSince != '') {
					$dplArticle->mUserLink = '[[User:' . $row->rev_user_text . '|' . $row->rev_user_text . ']]';
					$dplArticle->mUser     = $row->rev_user_text;
					$dplArticle->mComment  = $row->rev_comment;
				}

				//CATEGORY LINKS FROM CURRENT PAGE
				if ($bAddCategories && $bGoalIsPages && ($row->cats != '')) {
					$artCatNames = explode(' | ', $row->cats);
					foreach ($artCatNames as $artCatName) {
						$dplArticle->mCategoryLinks[] = '[[:Category:' . $artCatName . '|' . str_replace('_', ' ', $artCatName) . ']]';
						$dplArticle->mCategoryTexts[] = str_replace('_', ' ', $artCatName);
					}
				}
				// PARENT HEADING (category of the page, editor (user) of the page, etc. Depends on ordermethod param)
				if ($sHListMode != 'none') {
					switch ($aOrderMethods[0]) {
						case 'category':
							//count one more page in this heading
							$aHeadings[$row->cl_to] = isset($aHeadings[$row->cl_to]) ? $aHeadings[$row->cl_to] + 1 : 1;
							if ($row->cl_to == '') {
								//uncategorized page (used if ordermethod=category,...)
								$dplArticle->mParentHLink = '[[:Special:Uncategorizedpages|' . wfMsg('uncategorizedpages') . ']]';
							} else {
								$dplArticle->mParentHLink = '[[:Category:' . $row->cl_to . '|' . str_replace('_', ' ', $row->cl_to) . ']]';
							}
							break;
						case 'user':
							$aHeadings[$row->rev_user_text] = isset($aHeadings[$row->rev_user_text]) ? $aHeadings[$row->rev_user_text] + 1 : 1;
							if ($row->rev_user == 0) { //anonymous user
								$dplArticle->mParentHLink = '[[User:' . $row->rev_user_text . '|' . $row->rev_user_text . ']]';

							} else {
								$dplArticle->mParentHLink = '[[User:' . $row->rev_user_text . '|' . $row->rev_user_text . ']]';
							}
							break;
					}
				}
			}

			$aArticles[] = $dplArticle;
		}
		self::$DB->freeResult($res);
		$rowcount = -1;
		if ($sSqlCalcFoundRows != '') {
			$res      = self::$DB->query('SELECT FOUND_ROWS() AS rowcount');
			$row      = self::$DB->fetchObject($res);
			$rowcount = $row->rowcount;
			self::$DB->freeResult($res);
		}

		// backward scrolling: if the user specified titleLE we reverse the output order
		if ($sTitleLE != '' && $sTitleGE == '' && $sOrder == 'descending') {
			$aArticles = array_reverse($aArticles);
		}

		// special sort for card suits (Bridge)
		if ($bOrderSuitSymbols) {
			$aArticles = self::cardSuitSort($aArticles);
		}


		// ###### SHOW OUTPUT ######

		$listMode = new ListMode($sPageListMode, $aSecSeparators, $aMultiSecSeparators, $sInlTxt, $sListHtmlAttr, $sItemHtmlAttr, $aListSeparators, $offset, $iDominantSection);

		$hListMode = new ListMode($sHListMode, $aSecSeparators, $aMultiSecSeparators, '', $sHListHtmlAttr, $sHItemHtmlAttr, $aListSeparators, $offset, $iDominantSection);

		$dpl = new DynamicPageList(
			$aHeadings,
			$bHeadingCount,
			$iColumns,
			$iRows,
			$iRowSize,
			$sRowColFormat,
			$aArticles,
			$aOrderMethods[0],
			$hListMode,
			$listMode,
			$bEscapeLinks,
			$bAddExternalLink,
			$bIncPage,
			$iIncludeMaxLen,
			$aSecLabels,
			$aSecLabelsMatch,
			$aSecLabelsNotMatch,
			$bIncParsed,
			$parser,
			$logger,
			$aReplaceInTitle,
			$iTitleMaxLen,
			$defaultTemplateSuffix,
			$aTableRow,
			$bIncludeTrim,
			$iTableSortCol,
			$sUpdateRules,
			$sDeleteRules
		);

		if ($rowcount == -1) {
			$rowcount = $dpl->getRowCount();
		}
		$dplResult = $dpl->getText();
		$header    = '';
		if ($sOneResultHeader != '' && $rowcount == 1) {
			$header = str_replace('%TOTALPAGES%', $rowcount, str_replace('%PAGES%', 1, $sOneResultHeader));
		} else if ($rowcount == 0) {
			$header = str_replace('%TOTALPAGES%', $rowcount, str_replace('%PAGES%', $dpl->getRowCount(), $sNoResultsHeader));
			if ($sNoResultsHeader != '') {
				$output .= str_replace('\n', "\n", str_replace("¶", "\n", $header));
			}
			$footer = str_replace('%TOTALPAGES%', $rowcount, str_replace('%PAGES%', $dpl->getRowCount(), $sNoResultsFooter));
			if ($sNoResultsFooter != '') {
				$output .= str_replace('\n', "\n", str_replace("¶", "\n", $footer));
			}
			if ($sNoResultsHeader == '' && $sNoResultsFooter == '') {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_NORESULTS);
			}
		} else {
			if ($resultsHeader != '') {
				$header = str_replace('%TOTALPAGES%', $rowcount, str_replace('%PAGES%', $dpl->getRowCount(), $resultsHeader));
			}
		}
		$header = str_replace('\n', "\n", str_replace("¶", "\n", $header));
		$header = str_replace('%VERSION%', DPL_VERSION, $header);
		$footer = '';
		if ($sOneResultFooter != '' && $rowcount == 1) {
			$footer = str_replace('%PAGES%', 1, $sOneResultFooter);
		} else {
			if ($sResultsFooter != '') {
				$footer = str_replace('%TOTALPAGES%', $rowcount, str_replace('%PAGES%', $dpl->getRowCount(), $sResultsFooter));
			}
		}
		$footer = str_replace('\n', "\n", str_replace("¶", "\n", $footer));
		$footer = str_replace('%VERSION%', DPL_VERSION, $footer);

		// replace %DPLTIME% by execution time and timestamp in header and footer
		$nowTimeStamp   = self::prettyTimeStamp(date('YmdHis'));
		$dplElapsedTime = sprintf('%.3f sec.', microtime(true) - $dplStartTime);
		$header         = str_replace('%DPLTIME%', "$dplElapsedTime ($nowTimeStamp)", $header);
		$footer         = str_replace('%DPLTIME%', "$dplElapsedTime ($nowTimeStamp)", $footer);

		// replace %LASTTITLE% / %LASTNAMESPACE% by the last title found in header and footer
		if (($n = count($aArticles)) > 0) {
			$firstNamespaceFound = str_replace(' ', '_', $aArticles[0]->mTitle->getNamespace());
			$firstTitleFound     = str_replace(' ', '_', $aArticles[0]->mTitle->getText());
			$lastNamespaceFound  = str_replace(' ', '_', $aArticles[$n - 1]->mTitle->getNamespace());
			$lastTitleFound      = str_replace(' ', '_', $aArticles[$n - 1]->mTitle->getText());
		}
		$header = str_replace('%FIRSTNAMESPACE%', $firstNamespaceFound, $header);
		$footer = str_replace('%FIRSTNAMESPACE%', $firstNamespaceFound, $footer);
		$header = str_replace('%FIRSTTITLE%', $firstTitleFound, $header);
		$footer = str_replace('%FIRSTTITLE%', $firstTitleFound, $footer);
		$header = str_replace('%LASTNAMESPACE%', $lastNamespaceFound, $header);
		$footer = str_replace('%LASTNAMESPACE%', $lastNamespaceFound, $footer);
		$header = str_replace('%LASTTITLE%', $lastTitleFound, $header);
		$footer = str_replace('%LASTTITLE%', $lastTitleFound, $footer);
		$header = str_replace('%SCROLLDIR%', $scrollDir, $header);
		$footer = str_replace('%SCROLLDIR%', $scrollDir, $footer);

		$output .= $header . $dplResult . $footer;

		self::defineScrollVariables($firstNamespaceFound, $firstTitleFound, $lastNamespaceFound, $lastTitleFound, $scrollDir, $iCount, "$dplElapsedTime ($nowTimeStamp)", $rowcount, $dpl->getRowCount());

		// save generated wiki text to dplcache page if desired

		if ($DPLCache != '') {
			if (!is_writeable($cacheFile)) {
				wfMkdirParents(dirname($cacheFile));
			} else if (($bDPLRefresh || $wgRequest->getVal('action', 'view') == 'submit') && strpos($DPLCache, '/') > 0 && strpos($DPLCache, '..') === false) {
				// if the cache file contains a path and the user requested a refesh (or saved the file) we delete all brothers
				wfRecursiveRemoveDir(dirname($cacheFile));
				wfMkdirParents(dirname($cacheFile));
			}
			$cacheTimeStamp = self::prettyTimeStamp(date('YmdHis'));
			$cFile          = fopen($cacheFile, 'w');
			fwrite($cFile, $originalInput);
			fwrite($cFile, "+++\n");
			fwrite($cFile, $output);
			fclose($cFile);
			$dplElapsedTime = time() - $dplStartTime;
			if ($this->logger->iDebugLevel >= 2) {
				$output .= "{{Extension DPL cache|mode=update|page={{FULLPAGENAME}}|cache=$DPLCache|date=$cacheTimeStamp|age=0|now=" . date('H:i:s') . "|dpltime=$dplElapsedTime|offset=$offset}}";
			}
			$parser->disableCache();
		}

		// The following requires an extra parser step which may consume some time
		// we parse the DPL output and save all references found in that output in a global list
		// in a final user exit after the whole document processing we eliminate all these links
		// we use a local parser to avoid interference with the main parser

		if ($bReset[4] || $bReset[5] || $bReset[6] || $bReset[7]) {
			global $wgHooks;
			//Register a hook to reset links which were produced during parsing DPL output
			if (!in_array('DynamicPageListHooks::endEliminate', $wgHooks['ParserAfterTidy'])) {
				$wgHooks['ParserAfterTidy'][] = 'DynamicPageListHooks::endEliminate';
			}

			//Use a new parser to handle rendering.
			$localParser = new \Parser();
			$parserOutput = $localParser->parse($output, $parser->mTitle, $parser->mOptions);
		}
		if ($bReset[4]) { // LINKS
			// we trigger the mediawiki parser to find links, images, categories etc. which are contained in the DPL output
			// this allows us to remove these links from the link list later
			// If the article containing the DPL statement itself uses one of these links they will be thrown away!
			\DynamicPageListHooks::$createdLinks[0] = array();
			foreach ($parserOutput->getLinks() as $nsp => $link) {
				\DynamicPageListHooks::$createdLinks[0][$nsp] = $link;
			}
		}
		if ($bReset[5]) { // TEMPLATES
			\DynamicPageListHooks::$createdLinks[1] = array();
			foreach ($parserOutput->getTemplates() as $nsp => $tpl) {
				\DynamicPageListHooks::$createdLinks[1][$nsp] = $tpl;
			}
		}
		if ($bReset[6]) { // CATEGORIES
			\DynamicPageListHooks::$createdLinks[2] = $parserOutput->mCategories;
		}
		if ($bReset[7]) { // IMAGES
			\DynamicPageListHooks::$createdLinks[3] = $parserOutput->mImages;
		}

		wfProfileOut(__METHOD__);

		return $output;
	}

	/**
	 * Do basic clean up and structuring of raw user input.
	 *
	 * @access	private
	 * @param	string	Raw User Input
	 * @return	array	Array of raw text parameter => option.
	 */
	private function prepareUserInput($input) {
		//We replace double angle brackets with single angle brackets to avoid premature tag expansion in the input.
		//The ¦ symbol is an alias for |.
		//The combination '²{' and '}²'will be translated to double curly braces; this allows postponed template execution which is crucial for DPL queries which call other DPL queries.
		$input = str_replace(['«', '»', '¦', '²{', '}²'], ['<', '>', '|', '{{', '}}'], $input);

		//Standard new lines into the standard \n and clean up any hanging new lines.
		$input = str_replace(["\r\n", "\r"], "\n", $input);
		$input = trim($input, "\n");
		return explode("\n", $input);
	}

	// auxiliary functions ===============================================================================

	// create keys for TableRow which represent the structure of the "include=" arguments
	private static function updateTableRowKeys(&$aTableRow, $aSecLabels) {
		$tableRow  = $aTableRow;
		$aTableRow = array();
		$groupNr   = -1;
		$t         = -1;
		foreach ($aSecLabels as $label) {
			$t++;
			$groupNr++;
			$cols = explode('}:', $label);
			if (count($cols) <= 1) {
				if (array_key_exists($t, $tableRow)) {
					$aTableRow[$groupNr] = $tableRow[$t];
				}
			} else {
				$n     = count(explode(':', $cols[1]));
				$colNr = -1;
				$t--;
				for ($i = 1; $i <= $n; $i++) {
					$colNr++;
					$t++;
					if (array_key_exists($t, $tableRow)) {
						$aTableRow[$groupNr . '.' . $colNr] = $tableRow[$t];
					}
				}
			}
		}
	}

	private static function getSubcategories($cat, $pageTable, $depth) {
		if (self::$DB === null) {
			self::$DB = wfGetDB(DB_SLAVE);
		}
		$cats = $cat;
		$res  = self::$DB->query("SELECT DISTINCT page_title FROM ".$pageTable." INNER JOIN " . self::$DB->tableName('categorylinks') . " AS cl0 ON " . $this->tableNames['page'] . ".page_id = cl0.cl_from AND cl0.cl_to='" . str_replace(' ', '_', $cat) . "'" . " WHERE page_namespace='".NS_CATEGORY."'");
		foreach ($res as $row) {
			if ($depth > 1) {
				$cats .= '|' . self::getSubcategories($row->page_title, $this->tableNames['page'], $depth - 1);
			} else {
				$cats .= '|' . $row->page_title;
			}
		}
		self::$DB->freeResult($res);
		return $cats;
	}

	private static function prettyTimeStamp($t) {
		return substr($t, 0, 4) . '/' . substr($t, 4, 2) . '/' . substr($t, 6, 2) . '  ' . substr($t, 8, 2) . ':' . substr($t, 10, 2) . ':' . substr($t, 12, 2);
	}

	private static function durationTime($t) {
		if ($t < 60) {
			return "00:00:" . str_pad($t, 2, "0", STR_PAD_LEFT);
		}
		if ($t < 3600) {
			return "00:" . str_pad(floor($t / 60), 2, "0", STR_PAD_LEFT) . ':' . str_pad(floor(fmod($t, 60)), 2, "0", STR_PAD_LEFT);
		}
		if ($t < 86400) {
			return str_pad(floor($t / 3600), 2, "0", STR_PAD_LEFT) . ':' . str_pad(floor(fmod(floor($t / 60), 60)), 2, "0", STR_PAD_LEFT) . ':' . str_pad(fmod($t, 60), 2, "0", STR_PAD_LEFT);
		}
		if ($t < 2 * 86400) {
			return "1 day";
		}
		return floor($t / 86400) . ' days';
	}

	private static function resolveUrlArg($input, $arg) {
		global $wgRequest;
		$dplArg = $wgRequest->getVal($arg, '');
		if ($dplArg == '') {
			$input = preg_replace('/\{%' . $arg . ':(.*)%\}/U', '\1', $input);
			return str_replace('{%' . $arg . '%}', '', $input);
		} else {
			$input = preg_replace('/\{%' . $arg . ':.*%\}/U  ', $dplArg, $input);
			return str_replace('{%' . $arg . '%}', $dplArg, $input);
		}
	}

	/**
	 * This function uses the Variables extension to provide URL-arguments like &DPL_xyz=abc in the form of a variable which can be accessed as {{#var:xyz}} if Extension:Variables is installed.
	 *
	 * @access	public
	 * @return	void
	 */
	private function getUrlArgs() {
		global $wgRequest, $wgExtVariables;
		//@TODO: Figure out why this function needs to set ALL request variables and not just those related to DPL.
		$args = $wgRequest->getValues();
		foreach ($args as $argName => $argValue) {
			Variables::setVar(array(
				'',
				'',
				$argName,
				$argValue
			));
		}
		if (!isset($wgExtVariables)) {
			return;
		}
		$args  = $wgRequest->getValues();
		$dummy = '';
		foreach ($args as $argName => $argValue) {
			$wgExtVariables->vardefine($dummy, $argName, $argValue);
		}
	}

	/**
	 * This function uses the Variables extension to provide navigation aids like DPL_firstTitle, DPL_lastTitle, DPL_findTitle.  These variables can be accessed as {{#var:DPL_firstTitle}} if Extension:Variables is installed.
	 *
	 * @access	public
	 * @return	void
	 */
	private static function defineScrollVariables($firstNamespace, $firstTitle, $lastNamespace, $lastTitle, $scrollDir, $dplCount, $dplElapsedTime, $totalPages, $pages) {
		global $wgExtVariables;
		Variables::setVar(array(
			'',
			'',
			'DPL_firstNamespace',
			$firstNamespace
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_firstTitle',
			$firstTitle
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_lastNamespace',
			$lastNamespace
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_lastTitle',
			$lastTitle
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_scrollDir',
			$scrollDir
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_time',
			$dplElapsedTime
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_count',
			$dplCount
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_totalPages',
			$totalPages
		));
		Variables::setVar(array(
			'',
			'',
			'DPL_pages',
			$pages
		));

		if (!isset($wgExtVariables)) {
			return;
		}
		$dummy = '';
		$wgExtVariables->vardefine($dummy, 'DPL_firstNamespace', $firstNamespace);
		$wgExtVariables->vardefine($dummy, 'DPL_firstTitle', $firstTitle);
		$wgExtVariables->vardefine($dummy, 'DPL_lastNamespace', $lastNamespace);
		$wgExtVariables->vardefine($dummy, 'DPL_lastTitle', $lastTitle);
		$wgExtVariables->vardefine($dummy, 'DPL_scrollDir', $scrollDir);
		$wgExtVariables->vardefine($dummy, 'DPL_time', $dplElapsedTime);
		$wgExtVariables->vardefine($dummy, 'DPL_count', $dplCount);
		$wgExtVariables->vardefine($dummy, 'DPL_totalPages', $totalPages);
		$wgExtVariables->vardefine($dummy, 'DPL_pages', $pages);
	}

	/**
	 * Sort an array of Article objects by the card suit symbol.
	 *
	 * @access	public
	 * @param	array	Article objects in an array.
	 * @return	array	Sorted objects
	 */
	private static function cardSuitSort($articles) {
		$skey = array();
		for ($a = 0; $a < count($articles); $a++) {
			$title  = preg_replace('/.*:/', '', $articles[$a]->mTitle);
			$token  = preg_split('/ - */', $title);
			$newkey = '';
			foreach ($token as $tok) {
				$initial = substr($tok, 0, 1);
				if ($initial >= '1' && $initial <= '7') {
					$newkey .= $initial;
					$suit = substr($tok, 1);
					if ($suit == '♣') {
						$newkey .= '1';
					} else if ($suit == '♦') {
						$newkey .= '2';
					} else if ($suit == '♥') {
						$newkey .= '3';
					} else if ($suit == '♠') {
						$newkey .= '4';
					} else if ($suit == 'sa' || $suit == 'SA' || $suit == 'nt' || $suit == 'NT') {
						$newkey .= '5 ';
					} else {
						$newkey .= $suit;
					}
				} else if ($initial == 'P' || $initial == 'p')
					$newkey .= '0 ';
				else if ($initial == 'X' || $initial == 'x')
					$newkey .= '8 ';
				else
					$newkey .= $tok;
			}
			$skey[$a] = "$newkey#$a";
		}
		for ($a = 0; $a < count($articles); $a++) {
			$cArticles[$a] = clone ($articles[$a]);
		}
		sort($skey);
		for ($a = 0; $a < count($cArticles); $a++) {
			$key          = intval(preg_replace('/.*#/', '', $skey[$a]));
			$articles[$a] = $cArticles[$key];
		}
		return $articles;
	}
}
?>