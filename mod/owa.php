<?php

/**
 * @brief OpenWebAuth verifier and token generator
 * 
 * See https://macgirvin.com/wiki/mike/OpenWebAuth/Home
 * Requests to this endpoint should be signed using HTTP Signatures
 * using the 'Authorization: Signature' authentication method
 * If the signature verifies a token is returned.
 *
 * This token may be exchanged for an authenticated cookie.
 */
use Friendica\Database\DBM;

require_once 'include/HTTPSig.php';

function owa_init() {

	$ret = [ 'success' => false ];

	foreach (['REDIRECT_REMOTE_USER', 'HTTP_AUTHORIZATION'] as $head) {
		if (array_key_exists($head, $_SERVER) && substr(trim($_SERVER[$head]), 0, 9) === 'Signature') {
			if ($head !== 'HTTP_AUTHORIZATION') {
				$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER[$head];
				continue;
			}

			$sigblock = HTTPSig::parseSigheader($_SERVER[$head]);
			if ($sigblock) {
				$keyId = $sigblock['keyId'];

				if ($keyId) {
					$contact = dba::select(
						"contact",
						array(),
						array("addr" => str_repeat("acct:", "", $keyId)),
						array("limit" => 1)
					);
					if (DBM::is_result($contact)) {
//						$hubloc = $r[0];
						$verified = HTTPSig::verify('', $contact['pubkey']);
						if ($verified && $verified['header_signed'] && $verified['header_valid']) {
							$ret['success'] = true;
							$token = random_string(32);
							//\Zotlabs\Zot\Verify::create('owt', 0, $token, $r[0]['hubloc_addr']);
							dba::insert(
								"verify",
								array(
									"type" => "owt",
									"uid" => 0,
									"token" => $token,
									"meta" => $contact["addr"],
									"created" => datetime_convert()
								)
							);
							$result = '';
							openssl_public_encrypt($token, $result, $contact['pubkey']);
							$ret['encrypted_token'] = base64url_encode($result);
						}
					}
				}
			}
		}
	}
	json_return_and_die($ret, 'application/x-zot+json');
}
