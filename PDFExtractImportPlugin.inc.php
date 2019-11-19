<?php
/**
 * @file plugins/importexport/pdfextract/PDFExtractImportPlugin.inc.php
 *
 * Copyright (c) 2017 Simon Fraser University
 * Copyright (c) 2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PDFExtractImportPlugin
 * @ingroup plugins_importexport_pdfextract
 *
 * @brief PDFExtract XML import plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

class PDFExtractImportPlugin extends ImportExportPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'PDFExtractImportPlugin';
	}

	function getDisplayName() {
		return __('plugins.importexport.pdfextract.displayName');
	}

	function getDescription() {
		return __('plugins.importexport.pdfextract.description');
	}

	/**
	 * Execute import tasks using the command-line interface.
	 * @param $args array plugin options
	 */
	function executeCLI($scriptName, &$args) {

		if (sizeof($args) != 5) {
			$this->usage($scriptName);
			exit();
		}

		$journalPath = array_shift($args);
		$username = array_shift($args);
		$editorUsername = array_shift($args);
		$defaultEmail = array_shift($args);
		$pdfPath = rtrim(array_shift($args), '/');

		if (!$journalPath || !$username || !$editorUsername || !$pdfPath || !$defaultEmail) {
			$this->usage($scriptName);
			exit();
		}

		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journal = $journalDao->getByPath($journalPath);
		if (!$journal) {
			echo __('plugins.importexport.pdfextract.unknownJournal', array('journal' => $journalPath)) . "\n";
			exit();
		}

		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getByUsername($username);
		if (!$user) {
			echo __('plugins.importexport.pdfextract.unknownUser', array('username' => $username)) . "\n";
			exit();
		}

		$editor = $userDao->getByUsername($editorUsername);
		if (!$editor) {
			echo __('plugins.importexport.pdfextract.unknownUser', array('username' => $editorName)) . "\n";
			exit();
		}

		if (!file_exists($pdfPath) || !is_dir($pdfPath) ) {
			echo __('plugins.importexport.pdfextract.fileDoesNotExist', array('directory' => $pdfPath)) . "\n";
			exit();
		}

		if (!filter_var($defaultEmail, FILTER_VALIDATE_EMAIL)){
			echo __('plugins.importexport.pdfextract.unknownEmail', array('email' => $defaultEmail)). "\n";
			exit();
		}

		echo __('plugins.importexport.pdfextract.importStart');

		// Import volumes from oldest to newest
		$volumeHandle = opendir($pdfPath);
		while ($importVolumes[] = readdir($volumeHandle));
		sort($importVolumes, SORT_NATURAL);
		closedir($volumeHandle);
		foreach ($importVolumes as $volumeName){

			$volumePath = $pdfPath . '/' . $volumeName;

			if (!is_dir($volumePath) || preg_match('/^\./', $volumeName) || !$volumeName) continue;

			// Import issues from oldest to newest
			$importIssues = array();
			$issueHandle = opendir($volumePath);
			while ($importIssues[] = readdir($issueHandle));
			sort($importIssues, SORT_NATURAL);
			closedir($issueHandle);

			$allIssueIds = array();
			$curIssueId = 0;

			foreach ($importIssues as $issueName){
				$issuePath = $volumePath . '/' . $issueName;

				if (!is_dir($issuePath) || preg_match('/^\./', $issueName) || !$issueName) continue;


				// Import articles from oldest to newest
				$importArticles = array();
				$articleHandle = opendir($issuePath);
				while ($importArticles[] = readdir($articleHandle));
				sort($importArticles, SORT_NATURAL);
				closedir($articleHandle);

				$currSectionId = 0;
				$allSectionIds = array();
				foreach ($importArticles as $entry) {
					$articlePath = $issuePath . '/' . $entry;

					if (!is_file($articlePath) || preg_match('/^\./', $entry) || !$entry) continue;

			                $xml = $this->_fetchTEIFromGrobid($articlePath);
					$title = strip_tags($xml->teiHeader->fileDesc->titleStmt->title->asXML());
                			$abstract = $xml->teiHeader->profileDesc->abstract->children()[0]->asXML();


					$xml->registerXpathNamespace('TEI', 'http://www.tei-c.org/ns/1.0');

					$pubDate = $xml->xpath("//TEI:date[@type='published']")[0]['when'];
					$pubDate = date("Y-m-d H:i:s", strtotime($pubDate));


					$xml->registerXpathNamespace('TEI', 'http://www.tei-c.org/ns/1.0');
					$authorNodes  = $xml->xpath("//TEI:author");
					foreach ($authorNodes as $node) {

						$firstName = $node->persName->forename;
						$lastName = $node->persName->surname;
						$authorNames[]  = array('first' => $firstName->__toString(), 'last' => $lastName->__toString());
					}

			
			                // We have an issue and section, we can now process the article
			                $publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
                			$articleDao = DAORegistry::getDAO('ArticleDAO');
					$sectionDao = DAORegistry::getDAO('SectionDAO');

					$section = $sectionDao->getByAbbrev('ART', $journal->getId());

			                $this->_article = new Article();
			                $this->_article->setLocale($journal->getPrimaryLocale());
			                $this->_article->setLanguage('en');
			                $this->_article->setJournalId($journal->getId());
			                $this->_article->setSectionId($section->getId());
			                $this->_article->setStatus(STATUS_PUBLISHED);
			                $this->_article->setStageId(WORKFLOW_STAGE_ID_PRODUCTION);
			                $this->_article->setSubmissionProgress(0);
			                $this->_article->setTitle($title, $journal->getPrimaryLocale());
					$this->_article->setAbstract($abstract, $journal->getPrimaryLocale());
			                $this->_article->setDateSubmitted(date("Y-m-d H:i:s", time()));
					$this->_article->setDateStatusModified($pubDate);
					$articleDao->insertObject($this->_article);

					// Authors
					$authorDao = DAORegistry::getDAO('AuthorDAO');

					$userGroupId = null;
			                $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
			                $userGroupIds = $userGroupDao->getUserGroupIdsByRoleId(ROLE_ID_AUTHOR, $journal->getId());
			                if (!empty($userGroupIds)) $userGroupId = $userGroupIds[0];

					foreach ($authorNames as $a) {
			                	$author = new Author();
			                	$author->setGivenName($a['first'], $journal->getPrimaryLocale());
			                	$author->setFamilyName($a['last'], $journal->getPrimaryLocale());
			                	$author->setSequence(1);
			                	$author->setSubmissionId($this->_article->getId());
			                	$author->setEmail($defaultEmail);
			               		$author->setPrimaryContact(1);
			        	        $author->setIncludeInBrowse(true);
	
				                if ($userGroupId) $author->setUserGroupId($userGroupId);
						$authorDao->insertObject($author);
					}


			                // Assign editor as participant in production stage
			                $userGroupId = null;
			                $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
			                $userGroupIds = $userGroupDao->getUserGroupIdsByRoleId(ROLE_ID_MANAGER, $journal->getId());
			                foreach ($userGroupIds as $editorGroupId) {
			                        if ($userGroupDao->userGroupAssignedToStage($editorGroupId, $this->_article->getStageId())) break;
			                }
			                if ($editorGroupId) {
			                        $this->_editorGroupId = $editorGroupId;
			                        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			                        $stageAssignment = $stageAssignmentDao->build($this->_article->getId(), $editorGroupId, 1084);
			                } else {
			                        $this->_errors[] = array('plugins.importexport.pdfextract.import.error.missingEditorGroupId', array());
			                        return null;
			                }

			                // Insert published article entry
			                $publishedArticle = new PublishedArticle();
			                $publishedArticle->setId($this->_article->getId());
			                $publishedArticle->setSectionId($this->_article->getSectionId());
			                $publishedArticle->setIssueId(45); // FIXME get proper issue id
			                $publishedArticle->setDatePublished($pubDate);
			                $publishedArticle->setAccessStatus(ARTICLE_ACCESS_OPEN);
			                $publishedArticle->setSequence($this->_article->getId());
			                $publishedArticleDao->insertObject($publishedArticle);

					$this->_article->initializePermissions();


			                $pdfFilename = basename($articlePath);

			                // Create a representation of the article (i.e. a galley)
			                $representationDao = Application::getRepresentationDAO();
			                $representation = $representationDao->newDataObject();
			                $representation->setSubmissionId($this->_article->getId());
			                $representation->setName($pdfFilename, $journal->getPrimaryLocale());
			                $representation->setSequence(1);
			                $representation->setLabel('PDF');
			                $representation->setLocale($journal->getPrimaryLocale());
			                $representationDao->insertObject($representation);

			                // Add the PDF file and link representation with submission file
			                $genreDao = DAORegistry::getDAO('GenreDAO');
			                $genre = $genreDao->getByKey('SUBMISSION', $journal->getId());

					import('lib.pkp.classes.file.SubmissionFileManager');
					import('lib.pkp.classes.submission.SubmissionFile'); // constants

			                $submissionFileManager = new SubmissionFileManager($journal->getId(), $this->_article->getId());

			                $submissionFile = $submissionFileManager->copySubmissionFile(
			                        $articlePath,
			                        SUBMISSION_FILE_PROOF,
			                        1084,
			                        null,
			                        $genre->getId(),
			                        ASSOC_TYPE_REPRESENTATION,
			                        $representation->getId()
			                );
			                $representation->setFileId($submissionFile->getFileId());
			                $representationDao->updateObject($representation);

				}
			}
		}


		echo __('plugins.importexport.pdfextract.importEnd');
		exit();
	}

	/**
	 * Display the command-line usage information
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.pdfextract.cliUsage', array(
				'scriptName' => $scriptName,
				'pluginName' => $this->getName()
		)) . "\n";
	}

	function &getDocument($fileName) {
		$parser = new XMLParser();
		$returner =& $parser->parse($fileName);
		return $returner;
	}


	/*
	 * internal method to fetch TEI XML based on a PDF document
	 * @Param $pdfPath path to PDF document
	 * @return SimpleXML document
	 */

	function _fetchTEIFromGrobid($pdfPath) {

                $content = file_get_contents($pdfPath);

                // initialise the curl request
                $curlUrl = Config::getVar('grobid', 'url');
                $request = curl_init($curlUrl . '/api/processHeaderDocument');

                curl_setopt($request, CURLOPT_POST, true);
                curl_setopt(
                        $request,
                        CURLOPT_POSTFIELDS,
                        array('input' => $content)
                );

                // output the response
                curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
//                echo curl_exec($request);
                $result = curl_exec($request);
                $xml = simplexml_load_string($result);

		return $xml;
	}
}
?>
