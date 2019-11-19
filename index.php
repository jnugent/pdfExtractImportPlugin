<?php

/**
 * @defgroup plugins_importexport_pdfextract
 */

/**
 * @file plugins/importexport/pdfExtractImport/index.php
 *
 * Copyright (c) 2017 Simon Fraser University
 * Copyright (c) 2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_pdfextract
 * @brief Wrapper for PDF Extract/import plugin
 *
 */

require_once 'PDFExtractImportPlugin.inc.php';

error_reporting(E_ERROR);
return new PDFExtractImportPlugin();
?>
