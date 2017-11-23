<?php
use Friendica\Database\DBM;
use Friendica\Core\System;

require_once 'include/HTTPSig';

function init($a) {

	$ret = array('success' => false, 'url' => '', 'message' => '');
	logger('mod_magic: invoked', LOGGER_DEBUG);

	logger('mod_magic: args: ' . print_r($_REQUEST,true),LOGGER_DATA);

	$addr = ((x($_REQUEST,'addr')) ? $_REQUEST['addr'] : '');
	$dest = ((x($_REQUEST,'dest')) ? $_REQUEST['dest'] : '');
	$test = ((x($_REQUEST,'test')) ? intval($_REQUEST['test']) : 0);
//	$rev  = ((x($_REQUEST,'rev'))  ? intval($_REQUEST['rev'])  : 0);
	$delegate = ((x($_REQUEST,'delegate')) ? $_REQUEST['delegate']  : '');

	$parsed = parse_url($dest);
	if (!$parsed) {
		if ($test) {
			$ret['message'] .= 'could not parse ' . $dest . EOL;
			return($ret);
		}
		goaway($dest);
	}

	$basepath = $parsed['scheme'] . '://' . $parsed['host'] . (($parsed['port']) ? ':' . $parsed['port'] : ''); 

	// NOTE: I guess $dest isn't just the profile url (could be also other profile pages e.g. photo) we need to find
	// a way to also other profile pages
	$contact = dba::select(
		"contact",
		array(),
		array("nurl" => normalise_link($dest)),
		array("limit" => 1)
	);

	if (!DBM::is_result($contact)) {
		// Here We need to write the part to probe and insert the contact.
		logger("No contact record found");

		goaway($dest);
	}

	// Important - we need some part for already authed people
	if (array_key_exists("id", $a->observer) && strpos($contact['nurl'], normalise_link(System::baseUrl())) !== false) {
		logger("Contact is already authenticated");
		goaway($dest);
	}

	if (local_user()) {
		$user = $a->$user;
		logger("Local user found: ".print_r($user, true));

		// OpenWebAuth
		if ($owa) {
			// Extract the basepath
			// NOTE: we need another solution because this does only work
			// for friendica contacts :-/ .
			$exp = explode("/profile/", $contact['url']);
			$basepath = $exp[0];

			$headers = [];
			$headers['Accept'] = 'application/x-dfrn+json';
			$headers['X-Open-Web-Auth'] = random_string();
			$headers = HTTPSig::createSig('', $headers, $user['prvkey'],
				'acct:'.$user['nickname'].'@'.$a->get_hostname().($a->path ? '/'.$a->path : ''), false, true, 'sha512');

			logger("The Open-Web-Auth header: ".print_r($headers, true));

			$x = z_fetch_url($basepath.'/owa', false, $redirects, ['headers' => $headers]);

			logger("Result of owa fetch: ".print_r($x, true));

			if ($x['success']) {
				$j = json_decode($x['body'], true);
				if ($j['success'] && $j['token']) {
					$x = strpbrk($dest, '?&');
					$args = (($x) ? '&owt='.$j['token'] : '?f=&owt='.$j['token']).(($delegate) ? '&delegate=1' : '');

					logger("Destination: ".$dest." args: ".$args);
					goaway($dest.$args);
				}
			}
			goaway($dest);
		}
	}
}
