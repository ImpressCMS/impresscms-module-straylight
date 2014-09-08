<?php
/**
 * Generic interface for handling administration commands from remote devices (eg. Android, Arduino). 
 * 
 * Commands are authenticated via calculation of SHA256 HMAC with preshared, per-device keys. 
 * This allows the integrity and authenticity of the request to be validated, but the request is not
 * encrypted (ie. it is not private). Replay attacks are guarded against by inclusion of a counter
 * (tracked by the server), timestamp and a random string of crap (RSOC) in the the request, which 
 * ensures that each request has a unique HMAC fingerprint.
 * 
 * However, some devices (eg. Arduino) may not be capable of supplying all of these credentials due
 * to resource limitations, so the challenge tests are arranged in a semi-independent manner; 
 * you can comment out particular tests if your remote device can't handle them. Of course, this 
 * reduces security. As a minimum: Use the client_id, HMAC and counter or timestamp. The random 
 * text is highly desirable. If your client device can't generate adequate randomness, consider 
 * pre-generating a large chunk of it it on a more capable device and storing it on the client (in
 * this case, you MUST discard random data as you use it, you must NEVER re-use it).
 * 
 * Key length: Use a 256 byte key for optimum security. Shorter keys weaken security. There is 
 * no evidence that longer keys improve it. 256 is what the algorithm uses internally.
 * 
 * Randomness: Get your key from a high quality random source, such as GRC's perfect passwords page.
 * Be aware that random number generation on small devices (notably Arduino) can be extremely bad 
 * (that is to say, UNACCEPTABLE for security purposes) https://www.grc.com/passwords.htm
 * 
 * Data length: The overall length of device-supplied data used to calculate the HMAC should be at 
 * least 256 characters for security reasons. So you may want to adjust the length of your RSOC
 * accordingly.
 *
 * @copyright	Copyright Madfish (Simon Wilkinson).
 * @license		http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU GPL V2 or any later version
 * @since		1.0
 * @author		Madfish (Simon Wilkinson) <simon@isengard.biz>
 * @package		straylight
 * @version		$Id$
 */

function straylight_update_config($conf_name, $conf_value) {
	$criteria = icms_buildCriteria(array('conf_name' => $conf_name));
	$config_handler = icms::handler('icms_config');
	$configs = $config_handler->getConfigs($criteria);
	$configs = array_pop($configs);
	$configs->setVar('conf_value', $conf_value);
	$config_handler->insertConfig($configs);
}

function straylight_report_error($error) {
	// IMPORTANT: Ensure the error message is commented OUT on production sites
	echo 'Error: ' . $error;
	exit;
}

include_once 'header.php';
include_once ICMS_ROOT_PATH . '/header.php';

// Initialise
$clean_client_id = ''; // ID of the client device
$clean_command = ''; // Command requested by client device
$clean_counter = ''; // Counter value incremented by client device on each request
$clean_hmac = ''; // Client-generated HMAC of request parameters using pre-shared key
$clean_timestamp = ''; // Client-generated timestamp of request
$clean_random = ''; // Random data generated by client to mash up the request HMAC signature
$my_hmac = ''; // Server-generated HMAC of request parameters using pre-shared key
$data = '';
$valid_request = FALSE; // Set to TRUE if the request passes validation tests
$good_timestamp = FALSE; // Set to TRUE if the timestamp falls within an acceptable range
$straylight_authorised_client = FALSE; // Set to TRUE if the client device ID is known and authorised
$good_counter = FALSE; // Set to TRUE if the counter is > than the stored value for the last request
$good_hmac = FALSE; // Set to TRUE if the client-generated HMAC matches the server-generated HMAC
$authenticated = FALSE; // Set to TRUE if pass all validation and authentication tests


// 1. Check that required parameters have been supplied. Exit if anything missing.
if (empty($_POST['client_id']) 
		|| empty($_POST['command']) 
		|| empty($_POST['counter']) 
		|| empty($_POST['timestamp']) 
		|| empty($_POST['random']) 
		|| empty($_POST['hmac'])) {
	straylight_report_error('Missing required parameter');
}

// 2. Check that required parameters are of expected type and sanitise. Exit if encounter bad data.
$clean_client_id = ctype_digit($_POST['client_id']) ? 
	(int)($_POST['client_id']) : straylight_report_error('Client ID not decimal format');
$clean_counter = ctype_digit($_POST['counter']) ?
	(int)($_POST['counter']) : straylight_report_error('Counter not in decimal format');
$clean_command = trim($_POST['command']);
$clean_timestamp = ctype_digit($_POST['timestamp']) ?
	(int)($_POST['timestamp']) : straylight_report_error('Timestamp not decimal format');
$clean_random = ctype_alnum($_POST['random']) ?
	trim($_POST['random']) : straylight_report_error('Random factor not alphanumeric');
$clean_hmac = trim($_POST['hmac']);

// 2. Check command against vocabulary whitelist. Exit if command invalid. Alphabetical only.
$valid_commands = array(
	'checkPulse',
	'closeSite',
	'openSite',
	'clearCache',
	'debugOn',
	'debugOff',
	'lockDown');

if (in_array($clean_command, $valid_commands)) {
	$valid_request = TRUE;
} else {
	straylight_report_error('Invalid command');
}

/**
 * 3. Validate input:
 * a. The timestamp must fall within an acceptable range
 * b. The device must be known and authorised for remote Straylight administration.
 * c. The counter must be > than the previously stored value to protect against replay attacks
 * d. The concatenated request parameters must be authenticated by SHA256 HMAC with per-device key
 */

// a. Check timestamp falls in acceptable range (defined in module preferences, default 10 minutes)
$time = time();
$timestamp_differential = $time - $clean_timestamp;
if ($clean_timestamp <= $time && $timestamp_differential < icms_getConfig('timestamp_tolerance', 'straylight')) {
	$good_timestamp = TRUE;
} else {
	straylight_report_error('Bad timestamp. Check the clock of your device is accurate.');
}

// b. Check the device is currently Straylight authorised.
$straylight_client_handler = icms_getModuleHandler('client', basename(dirname(__FILE__)), 'straylight');
$straylight_client = $straylight_client_handler->get($clean_client_id);
if ($straylight_client && ($straylight_client->getVar('authorised', 'e') == TRUE)) {
	$straylight_authorised_client = TRUE;
} else {
	straylight_report_error('Client not Straylight authorised');
}

// c. Check request counter exceeds the stored value (guard against replay attacks)
if ($clean_counter > $straylight_client->getVar('request_counter', 'e')) {
	$good_counter = TRUE;
	$straylight_client_handler->update_request_counter($straylight_client, $clean_counter);
} else {
	straylight_report_error('Bad counter. This is not the most recent request from the client device.');
}

/**
 * d. Authenticate message via SHA256 HMAC and preshared key.
 * 
 * Note that client devices must also concatenate these fields in the same order when doing their
 * own HMAC calculation. To protect against tampering, all request fields must be included in the 
 * calculation (except for the HMAC itself).
 * 
 * Note: Special characters included in the GET request from your device need to be url encoded or 
 * serialised, and then decoded here prior to calculating the HMAC to ensure a reproduceable result.
 * Some characters, especially spaces and entities such as '&' can also mess up your parameters if
 * not encoded. If your device can't urlencode or is too limited to do it manually, then avoid use 
 * of such characters.
 */

$key = $straylight_client->getVar('shared_hmac_key', 'e');

// Comment out according to the parameters in use
$data = $clean_client_id . $clean_command 
		. $clean_counter
		. $clean_timestamp
		. $clean_random;
if (!empty($key)) {
	$my_hmac = hash_hmac('sha256', $data, $key, FALSE);
} else {
	straylight_report_error('No preshared key');
}
if ($my_hmac == $clean_hmac) // HMAC verified, authenticity and integrity has been established. 
{
	$good_hmac = TRUE;
	echo '<br />SUCCESS<br />';
}
else {
	straylight_report_error('Bad HMAC. Failed to confirm authenticity and integrity of message. Discarding.<br />');
}

// Final sanity check. Explicitly check that all necessary tests have been passed
if ($valid_request
		&& $good_timestamp
		&& $straylight_authorised_client
		&& $good_counter
		&& $good_hmac) {
	$authenticated = TRUE;	
} else {
	straylight_report_error('Sanity check failed, request not authenticated.');
}

//////////////////////////////////////////////////////////////////////
////////// REQUEST HAS PASSED VALIDATION AND AUTHENTICATION //////////
//////////////////////////////////////////////////////////////////////

/* Proceed to process the command
 * 
 * To extend the list of commands, simply i) add it to the command vocabulary array (see
 * comment 2. above), and ii) add a matching case statement below with the logic for implementing
 * it. The authentication mechanism is independent and will prevent unauthorised commands from 
 * being executed, so you can safely add things to the list. Commands must be alphabetical characters
 * only - no underscores, hyphens or numbers.
 */
if ($authenticated == TRUE)
{
	switch ($clean_command)
	{
		// Returns an 'ok' code if site up.
		case "checkPulse":
			http_response_code(200); // Requires PHP 5.4+
			break;

		// Closes the site
		case "closeSite":
			straylight_update_config('closesite', 1);
			break;
		
		// Not implemented. Access to relevant files requires having permissions to view the site
		// when it is closed. Could probably be accomplished using raw SQL but that has security 
		// implications (DB password) that I have not yet fully contemplated. So for the moment, 
		// it's out.
		case "openSite":
			break;

		// Clears the /cache and /templates_c folders
		case "clearCache":
			echo "Clear cache";
			break;

		// Turns inline debugging on
		case "debugOn":
			straylight_update_config('debug_mode', 1);		
			break;

		// Turns inline debugging off
		case "debugOff":
			straylight_update_config('debug_mode', 0);
			break;

		// Prepares the site to resist casual abuse by idiots. Protective measures include:
		// New user registrations are closed.
		// Multiple login of same user disallowed.
		// IP bans are enabled
		// Comments are closed for all modules.
		// CAPTCHA enabled on forms
		// HTML purifier is enabled
		// Minimum search string set to at least 5 characters.
		// GZIP compression is disabled (reduces processer load at cost of increased bandwidth)
		// Minimum password length set to 8 characters.
		// Minimum security level set to strong.
		// User change of email address disallowed.
		// User change of display name disallowed.
		// Display of external images and html in signatures disallowed.
		// Upload of custom avatar images disallowed.
		
		case "lockDown":
			// Run straylight_update_config for each setting
			break;
	}
}
else {
	exit;
}