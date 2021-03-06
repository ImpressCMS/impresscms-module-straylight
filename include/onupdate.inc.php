<?php
/**
 * File containing onUpdate and onInstall functions for the module
 *
 * This file is included by the core in order to trigger onInstall or onUpdate functions when needed.
 * Of course, onUpdate function will be triggered when the module is updated, and onInstall when
 * the module is originally installed. The name of this file needs to be defined in the
 * icms_version.php
 *
 * <code>
 * $modversion['onInstall'] = "include/onupdate.inc.php";
 * $modversion['onUpdate'] = "include/onupdate.inc.php";
 * </code>
 *
 * @copyright	Copyright Madfish (Simon Wilkinson).
 * @license		http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU General Public License (GPL)
 * @since		1.0
 * @author		Madfish (Simon Wilkinson) <simon@isengard.biz>
 * @package		straylight
 * @version		$Id$
 */

defined("ICMS_ROOT_PATH") or die("ICMS root path not defined");

// this needs to be the latest db version
define('STRAYLIGHT_DB_VERSION', 1);

/**
 * it is possible to define custom functions which will be call when the module is updating at the
 * correct time in update incrementation. Simpy define a function named <direname_db_upgrade_db_version>
 */
/*
function straylight_db_upgrade_1() {
}
function straylight_db_upgrade_2() {
}
*/

function icms_module_update_straylight($module)
{
    return TRUE;
}

function icms_module_install_straylight($module)
{
	// create a directory in the trust path to store the private key
	$path = XOOPS_TRUST_PATH . '/modules/' . basename(dirname(dirname(__FILE__)));
	$directory_exists = $file_exists = $writeable = true;

	// check if directory exists, make one if not, and write an empty index file
	if (!is_dir($path)) {
		$directory_exists = mkdir($path, 0777);
		$path .= '/index.html';
		
		// add an index file to prevent index lookups
		if (!is_file($path)) {
			$filename = $path;	
			$contents = '<script>history.go(-1);</script>';
			$handle = fopen($filename, 'wb');
			$result = fwrite($handle, $contents);
			echo 'result is: ' . $result;
			fclose($handle);
		}
	}

	return TRUE;
}