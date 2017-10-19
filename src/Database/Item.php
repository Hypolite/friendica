<?php

namespace Friendica\Database;

/**
 * @file src/Database/Item.php
 * @brief Layer between database calls and core
 *
 */

use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Config;

use dba;
use dbm;

/**
 * @brief This class contain functions that work as a layer between database calls and core
 *
 */
class Item {
	public static function store($item, $force_parent = false) {
		$item['guid'] = guid($item, true);

		/*
		 * If a Diaspora signature structure was passed in, pull it out of the
		 * item array and set it aside for later storage.
		 */

		$dsprsig = null;
		if (!empty($item['dsprsig'])) {
			$encoded_signature = $item['dsprsig'];
			$dsprsig = json_decode(base64_decode($item['dsprsig']));
			unset($item['dsprsig']);
		}

		// check for create date and expire time
		$expire_interval = Config::get('system', 'dbclean-expire-days', 0);

		$r = dba::select('user', array('expire'), array('uid' => $item['uid']), array("limit" => 1));
		if (dbm::is_result($r) && ($r['expire'] > 0) && ($r['expire'] < $expire_interval)) {
			$expire_interval = $r['expire'];
		}

		if (($expire_interval > 0) && !empty($item['created'])) {
			$expire_date = time() - ($expire_interval * 86400);
			$created_date = strtotime($item['created']);
			if ($created_date < $expire_date) {
				logger('item-store: item created ('.date('c', $created_date).') before expiration time ('.date('c', $expire_date).'). ignored. ' . print_r($item,true), LOGGER_DEBUG);
				return 0;
			}
		}

		/*
		 * Do we already have this item?
		 * We have to check several networks since Friendica posts could be repeated
		 * via OStatus (maybe Diasporsa as well)
		 */
		if (in_array(trim($item['network']), array(NETWORK_DIASPORA, NETWORK_DFRN, NETWORK_OSTATUS, ""))) {
			$select = array("`uri` = ? AND `uid` = ? AND `network` IN (?, ?, ?)",
					trim($item['uri']), $item['uid'],
					NETWORK_DIASPORA, NETWORK_DFRN, NETWORK_OSTATUS);
			$r = dba::select('item', array('id', 'network'), $select, array('limit' => 1));
			if (dbm::is_result($r)) {
				// We only log the entries with a different user id than 0. Otherwise we would have too many false positives
				if ($item['uid'] != 0) {
					logger("Item with uri ".$item['uri']." already existed for user ".$item['uid']." with id ".$r["id"]." target network ".$r["network"]." - new network: ".$item['network']);
				}

				return $r["id"];
			}
		}

		$item['network'] = network($item);

		// Checking if there is already an item with the same guid
		logger('checking for an item for user '.$item['uid'].' on network '.$item['network'].' with the guid '.$item['guid'], LOGGER_DEBUG);
		$condition = array('guid' => $item['guid'], 'network' => $item['network'], 'uid' => $item['uid']);
		if (dbm::exists('item', $condition)) {
			logger('found item with guid '.$item['guid'].' for user '.$item['uid'].' on network '.$item['network'], LOGGER_DEBUG);
			return 0;
		}





// ---------------------------------------------------------------------------------
		$hooks = array('pre' => 'post_remote', 'post' => 'post_remote_end');
		$item_id = self::insert($item, $force_parent, $hooks);

		check_item_notification($item_id, $item['uid']);
	}

	public static function publish($item) {
		$a = get_app();

		$item['wall'] = 1;
		$item['type'] = 'wall';
		$item['origin'] = 1;
		$item['last-child'] = 1;
		$item['network'] = NETWORK_DFRN;
		$item['protocol'] = PROTOCOL_DFRN;
		$item['guid'] = guid($item, false);




// ---------------------------------------------------------------------------------
		$hooks = array('pre' => 'post_local', 'post' => 'post_local_end');
		$item_id = self::insert($item, false, $hooks);
		if ($item_id != 0) {
			self::deliver($item);
		}
	}

	private static function guid($item, $store = true) {
		if (!empty($item['guid'])) {
			return $item['guid'];
		}

		// We have to avoid duplicates. So we create the GUID in form of a hash of the plink or uri.
		// In difference to the call to "uri_to_guid" several lines below we add the hash of our own host.
		// This is done because our host is the original creator of the post.
		if (isset($item['plink'])) {
			$guid = uri_to_guid($item['plink'], $a->get_hostname());
		} elseif (isset($item['uri'])) {
			$guid = uri_to_guid($item['uri'], $a->get_hostname());
		} elseif ($store) {
			$parsed = parse_url($item["author-link"]);
			$guid_prefix = hash("crc32", $parsed["host"]);
			$guid = get_guid(32, $guid_prefix);
		} else {
			$guid = get_guid(32);
		}

		return $guid;
	}

	private static function network($item) {
		if (!empty($item['network'])) {
			return $item['network'];
		}

		$condition = array("`network` IN (?, ?, ?) AND `nurl` = ? AND `uid` = ?",
				NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS,
				normalise_link($item['author-link']), $item['uid']);
		$r = dba::select('contact', array('network'), $condition, array('limit' => 1));

		if (!dbm::is_result($r)) {
			$condition = array("`network` IN (?, ?, ?) AND `nurl` = ?",
				NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS,
				normalise_link($item['author-link']));
			$r = dba::select('contact', array('network'), $condition, array('limit' => 1));
		}

		if (!dbm::is_result($r)) {
			$condition = array('id' => $item['contact-id'], 'uid' => $item['uid']);
			$r = dba::select('contact', array('network'), $condition, array('limit' => 1));
		}

		if (dbm::is_result($r)) {
			$network = $r["network"];
		}

		// Fallback to friendica (why is it empty in some cases?)
		if ($network == "") {
			$network = NETWORK_DFRN;
		}

		logger("item_store: Set network to " . $network . " for " . $item["uri"], LOGGER_DEBUG);

		return $network;
	}

	private static function contactId($item) {
		if (!empty($item["contact-id"])) {
			return $item["contact-id"];
		}

		$contact_id = 0;

		/*
		 * First we are looking for a suitable contact that matches with the author of the post
		 * This is done only for comments
		 */
		if ($item['parent-uri'] != $item['uri']) {
			$contact_id = get_contact($item['author-link'], $item['uid']);
		}

		// If not present then maybe the owner was found
		if ($contact_id == 0) {
			$contact_id = get_contact($item['owner-link'], $item['uid']);
		}

		// Still missing? Then use the "self" contact of the current user
		if ($contact_id == 0) {
			$r = dbm::select('contact', array('id'), array('self' => true, 'uid' => $item['uid']), array('limit' => 1));
			if (dbm::is_result($r)) {
				$contact_id = $r["id"];
			}
		}

		logger("Contact-id was missing for post ".$item["guid"]." from user id ".$item['uid']." - now set to ".$contact_id, LOGGER_DEBUG);

		return $contact_id;
	}

	private static function insert($item, $force_parent = false, $hooks = array()) {
		$a = get_app();

		if (!empty($item['gravity'])) {
			$item['gravity'] = intval($item['gravity']);
		} elseif ($item['parent-uri'] === $item['uri']) {
			$item['gravity'] = 0;
		} elseif (activity_match($item['verb'], ACTIVITY_POST)) {
			$item['gravity'] = 6;
		} else {
			$item['gravity'] = 6;   // extensible catchall
		}

		$item['uri']           = (!empty($item['uri'])           ? trim($item['uri'])           : item_new_uri($a->get_hostname(), $item['uid'], $item['guid']));
		$item['parent-uri']    = (!empty($item['parent-uri'])    ? trim($item['parent-uri'])    : $item['uri']);
		$item['thr-parent']    = (!empty($item['thr-parent'])    ? trim($item['thr-parent'])    : $item['parent-uri']);
		$item['type']          = (!empty($item['type'])          ? trim($item['type'])          : 'remote');
		$item['wall']          = (!empty($item['wall'])          ? intval($item['wall'])        : 0);
		$item['extid']         = (!empty($item['extid'])         ? trim($item['extid'])         : '');
		$item['author-name']   = (!empty($item['author-name'])   ? trim($item['author-name'])   : '');
		$item['author-link']   = (!empty($item['author-link'])   ? trim($item['author-link'])   : '');
		$item['author-avatar'] = (!empty($item['author-avatar']) ? trim($item['author-avatar']) : '');
		$item['owner-name']    = (!empty($item['owner-name'])    ? trim($item['owner-name'])    : '');
		$item['owner-link']    = (!empty($item['owner-link'])    ? trim($item['owner-link'])    : '');
		$item['owner-avatar']  = (!empty($item['owner-avatar'])  ? trim($item['owner-avatar'])  : '');
		$item['received']      = (!empty($item['received'])      ? datetime_convert('UTC','UTC', $item['received'])  : datetime_convert());
		$item['created']       = (!empty($item['created'])       ? datetime_convert('UTC','UTC', $item['created'])   : $item['received']);
		$item['edited']        = (!empty($item['edited'])        ? datetime_convert('UTC','UTC', $item['edited'])    : $item['created']);
		$item['changed']       = (!empty($item['changed'])       ? datetime_convert('UTC','UTC', $item['changed'])   : $item['created']);
		$item['commented']     = (!empty($item['commented'])     ? datetime_convert('UTC','UTC', $item['commented']) : $item['created']);
		$item['title']         = (!empty($item['title'])         ? trim($item['title'])         : '');
		$item['location']      = (!empty($item['location'])      ? trim($item['location'])      : '');
		$item['coord']         = (!empty($item['coord'])         ? trim($item['coord'])         : '');
		$item['last-child']    = (!empty($item['last-child'])    ? intval($item['last-child'])  : 0);
		$item['visible']       = (!empty($item['visible'])       ? intval($item['visible'])     : 1);
		$item['deleted']       = 0;
		$item['verb']          = (!empty($item['verb'])          ? trim($item['verb'])          : '');
		$item['object-type']   = (!empty($item['object-type'])   ? trim($item['object-type'])   : '');
		$item['object']        = (!empty($item['object'])        ? trim($item['object'])        : '');
		$item['target-type']   = (!empty($item['target-type'])   ? trim($item['target-type'])   : '');
		$item['target']        = (!empty($item['target'])        ? trim($item['target'])        : '');
		$item['plink']         = (!empty($item['plink'])         ? trim($item['plink'])         : System::baseUrl() . '/display/' . urlencode($item['guid']));
		$item['allow_cid']     = (!empty($item['allow_cid'])     ? trim($item['allow_cid'])     : '');
		$item['allow_gid']     = (!empty($item['allow_gid'])     ? trim($item['allow_gid'])     : '');
		$item['deny_cid']      = (!empty($item['deny_cid'])      ? trim($item['deny_cid'])      : '');
		$item['deny_gid']      = (!empty($item['deny_gid'])      ? trim($item['deny_gid'])      : '');
		$item['private']       = (!empty($item['private'])       ? intval($item['private'])     : 0);
		$item['bookmark']      = (!empty($item['bookmark'])      ? intval($item['bookmark'])    : 0);
		$item['body']          = (!empty($item['body'])          ? trim($item['body'])          : '');
		$item['tag']           = (!empty($item['tag'])           ? trim($item['tag'])           : '');
		$item['attach']        = (!empty($item['attach'])        ? trim($item['attach'])        : '');
		$item['app']           = (!empty($item['app'])           ? trim($item['app'])           : '');
		$item['origin']        = (!empty($item['origin'])        ? intval($item['origin'])      : 0);
		$item['postopts']      = (!empty($item['postopts'])      ? trim($item['postopts'])      : '');
		$item['resource-id']   = (!empty($item['resource-id'])   ? trim($item['resource-id'])   : '');
		$item['event-id']      = (!empty($item['event-id'])      ? intval($item['event-id'])    : 0);
		$item['inform']        = (!empty($item['inform'])        ? trim($item['inform'])        : '');
		$item['file']          = (!empty($item['file'])          ? trim($item['file'])          : '');
		$item['contact-id']    = (!empty($item['contact-id'])    ? intval($item['contact-id'])  : self::contactId($item));
		$item['author-id']     = (!empty($item['author-id'])     ? intval($item['author-id'])   : get_contact($item["author-link"], 0));
		$item['owner-id']      = (!empty($item['owner-id'])      ? intval($item['owner-id'])    : get_contact($item["owner-link"], 0));

		// Store conversation data
		$item = store_conversation($item);

		// Add language data
		item_add_language_opt($item);

		// When there is no content then we don't post it
		if ($item['body'].$item['title'] == '') {
			return 0;
		}

		// Items cannot be stored before they happen ...
		if ($item['created'] > datetime_convert()) {
			$item['created'] = datetime_convert();
		}

		// We haven't invented time travel by now.
		if ($item['edited'] > datetime_convert()) {
			$item['edited'] = datetime_convert();
		}

		if (($item['author-link'] == "") && ($item['owner-link'] == "")) {
			logger("Both author-link and owner-link are empty. Called by: " . System::callstack(), LOGGER_DEBUG);
		}

		if (blockedContact($item["author-id"])) {
			logger('Contact '.$item["author-id"].' is blocked, item '.$item["uri"].' will not be stored');
			return 0;
		}

		if (blockedContact($item["owner-id"])) {
			logger('Contact '.$item["owner-id"].' is blocked, item '.$item["uri"].' will not be stored');
			return 0;
		}

		if ($item["gcontact-id"] == 0) {
			/*
			 * The gcontact should mostly behave like the contact. But is is supposed to be global for the system.
			 * This means that wall posts, repeated posts, etc. should have the gcontact id of the owner.
			 * On comments the author is the better choice.
			 */
			if ($item['parent-uri'] === $item['uri']) {
				$item["gcontact-id"] = get_gcontact_id(array("url" => $item['owner-link'], "network" => $item['network'],
									 "photo" => $item['owner-avatar'], "name" => $item['owner-name']));
			} else {
				$item["gcontact-id"] = get_gcontact_id(array("url" => $item['author-link'], "network" => $item['network'],
									 "photo" => $item['author-avatar'], "name" => $item['author-name']));
			}
		}

		// Check for hashtags in the body and repair or add hashtag links
		item_body_set_hashtags($item);

		if ($item['parent-uri'] === $item['uri']) {
			$parent_id = 0;
			$parent_deleted = 0;
			$allow_cid = $item['allow_cid'];
			$allow_gid = $item['allow_gid'];
			$deny_cid  = $item['deny_cid'];
			$deny_gid  = $item['deny_gid'];
		} else {
			// find the parent and snarf the item id and ACLs
			// and anything else we need to inherit

			$fields = array('uri', 'parent-uri', 'id', 'deleted',
					'allow_cid', 'allow_gid', 'deny_cid', 'deny_gid',
					'wall', 'private', 'forum_mode');
			$condition = array('uri' => $item['parent-uri'], 'uid' => $item['uid']);
			$params = array('limit' => 1, 'order' => array('id' => false));
			$r = dba::select('item', $fields, $condition, $params);

			if (dbm::is_result($r)) {
				// is the new message multi-level threaded?
				// even though we don't support it now, preserve the info
				// and re-attach to the conversation parent.

				if ($r['uri'] != $r['parent-uri']) {
					$item['parent-uri'] = $r['parent-uri'];
					$condition = array('uri' => $item['parent-uri'],
							'parent-uri' => $item['parent-uri'],
							'uid' => $item['uid']);
					$params = array('limit' => 1, 'order' => array('id' => false));
					$z = dba::select('item', $fields, $condition, $params);

					if (dbm::is_result($z)) {
						$r = $z;
					}
				}

				$parent_id      = $r['id'];
				$parent_deleted = $r['deleted'];
				$allow_cid      = $r['allow_cid'];
				$allow_gid      = $r['allow_gid'];
				$deny_cid       = $r['deny_cid'];
				$deny_gid       = $r['deny_gid'];
				$item['wall']    = $r['wall'];

				/*
				 * If the parent is private, force privacy for the entire conversation
				 * This differs from the above settings as it subtly allows comments from
				 * email correspondents to be private even if the overall thread is not.
				 */
				if ($r['private']) {
					$item['private'] = $r['private'];
				}

				/*
				 * Edge case. We host a public forum that was originally posted to privately.
				 * The original author commented, but as this is a comment, the permissions
				 * weren't fixed up so it will still show the comment as private unless we fix it here.
				 */
				if ((intval($r['forum_mode']) == 1) && (! $r['private'])) {
					$item['private'] = 0;
				}

				// If its a post from myself then tag the thread as "mention"
				logger("item_store: Checking if parent ".$parent_id." has to be tagged as mention for user ".$item['uid'], LOGGER_DEBUG);
				$u = dba::select('user', array('nickname'), array('uid' => $item['uid']), array('limit' => 1));
				if (dbm::is_result($u)) {
					$a = get_app();
					$self = normalise_link(System::baseUrl() . '/profile/' . $u['nickname']);
					logger("item_store: 'myself' is ".$self." for parent ".$parent_id." checking against ".$item['author-link']." and ".$item['owner-link'], LOGGER_DEBUG);
					if ((normalise_link($item['author-link']) == $self) || (normalise_link($item['owner-link']) == $self)) {
						dba::update('thread', array('mention' => true), array('iid' => $parent_id));
						logger("item_store: tagged thread ".$parent_id." as mention for user ".$self, LOGGER_DEBUG);
					}
				}
			} else {
				/*
				 * Allow one to see reply tweets from status.net even when
				 * we don't have or can't see the original post.
				 */
				if ($force_parent) {
					logger('item_store: $force_parent=true, reply converted to top-level post.');
					$parent_id = 0;
					$item['parent-uri'] = $item['uri'];
					$item['gravity'] = 0;
				} else {
					logger('item_store: item parent '.$item['parent-uri'].' for '.$item['uid'].' was not found - ignoring item');
					return 0;
				}

				$parent_deleted = 0;
			}
		}

		$condition = array("`uri` = ? AND `network` IN (?, ?) AND `uid` = ?",
				$item['uri'], $item['network'], NETWORK_DFRN, $item['uid']);
		if (dba::exists('item', $condition)) {
			logger('duplicated item with the same uri found. '.print_r($item,true));
			return 0;
		}

		// On Friendica and Diaspora the GUID is unique
		if (in_array($item['network'], array(NETWORK_DFRN, NETWORK_DIASPORA))) {
			$condition = array('guid' => $item['guid'], 'uid' => $item['uid']);
			if (dba::exists('item', $condition)) {
				logger('duplicated item with the same guid found. '.print_r($item,true));
				return 0;
			}
		} else {
			// Check for an existing post with the same content. There seems to be a problem with OStatus.
			$condition = array("`body` = ? AND `network` = ? AND `created` = ? AND `contact-id` = ? AND `uid` = ?",
						$item['body'], $item['network'], $item['created'], $item['contact-id'], $item['uid']);
			if (dba::exists('item', $condition)) {
				logger('duplicated item with the same body found. '.print_r($item,true));
				return 0;
			}
		}

		// Is this item available in the global items (with uid=0)?
		if ($item["uid"] == 0) {
			$item["global"] = true;

			// Set the global flag on all items if this was a global item entry
			dba::update('item', array('global' => true), array('uri' => $item["uri"]));
		} else {
			$item["global"] = dba::exists('item', array('uid' => 0, 'uri' => $item["uri"]));
		}

		// ACL settings
		if (strlen($allow_cid) || strlen($allow_gid) || strlen($deny_cid) || strlen($deny_gid)) {
			$private = 1;
		} else {
			$private = $item['private'];
		}

		$item["allow_cid"] = $allow_cid;
		$item["allow_gid"] = $allow_gid;
		$item["deny_cid"] = $deny_cid;
		$item["deny_gid"] = $deny_gid;
		$item["private"] = $private;
		$item["deleted"] = $parent_deleted;

		// Fill the cache field
		put_item_in_cache($item);

		if (!empty($hooks['pre'])) {
			call_hooks($hooks['pre'], $item);
		}

		// This array field is used to trigger some automatic reactions
		// It is mainly used in the "post_local" hook.
		unset($item['api_source']);

		if (!empty($item['cancel'])) {
			logger('item_store: post cancelled by plugin.');
			return 0;
		}

		/*
		 * Check for already added items.
		 * There is a timing issue here that sometimes creates double postings.
		 * An unique index would help - but the limitations of MySQL (maximum size of index values) prevent this.
		 */
		if ($item["uid"] == 0) {
			if (dba::exists('item', array('uri' => trim($item['uri']), 'uid' => 0))) {
				logger('Global item already stored. URI: '.$item['uri'].' on network '.$item['network'], LOGGER_DEBUG);
				return 0;
			}
		}

		logger('item_store: ' . print_r($item,true), LOGGER_DATA);

		dba::transaction();
		$r = dba::insert('item', $item);

		// When the item was successfully stored we fetch the ID of the item.
		if (dbm::is_result($r)) {
			$current_post = dba::lastInsertId();
		} else {
			// This can happen - for example - if there are locking timeouts.
			dba::rollback();

			// Store the data into a spool file so that we can try again later.

			// At first we restore the Diaspora signature that we removed above.
			if (isset($encoded_signature)) {
				$item['dsprsig'] = $encoded_signature;
			}

			// Now we store the data in the spool directory
			// We use "microtime" to keep the arrival order and "mt_rand" to avoid duplicates
			$file = 'item-'.round(microtime(true) * 10000).'-'.mt_rand().'.msg';

			$spoolpath = get_spoolpath();
			if ($spoolpath != "") {
				$spool = $spoolpath.'/'.$file;
				file_put_contents($spool, json_encode($item));
				logger("Item wasn't stored - Item was spooled into file ".$file, LOGGER_DEBUG);
			}
			return 0;
		}

		if ($current_post == 0) {
			// This is one of these error messages that never should occur.
			logger("couldn't find created item - we better quit now.");
			dba::rollback();
			return 0;
		}

		// How much entries have we created?
		// We wouldn't need this query when we could use an unique index - but MySQL has length problems with them.
		$r = dba::p("SELECT COUNT(*) AS `entries` FROM `item` WHERE `uri` = ? AND `uid` = ? AND `network` = ?",
				$item['uri'], $item['uid'] , $item['network']);

		if (!dbm::is_result($r)) {
			// This shouldn't happen, since COUNT always works when the database connection is there.
			logger("We couldn't count the stored entries. Very strange ...");
			dba::rollback();
			return 0;
		}

		if ($r["entries"] > 1) {
			// There are duplicates. We delete our just created entry.
			logger('Duplicated post occurred. uri = ' . $item['uri'] . ' uid = ' . $item['uid']);

			// Yes, we could do a rollback here - but we are having many users with MyISAM.
			dba::delete('item', array('id' => $current_post));
			dba::commit();
			return 0;
		} elseif ($r["entries"] == 0) {
			// This really should never happen since we quit earlier if there were problems.
			logger("Something is terribly wrong. We haven't found our created entry.");
			dba::rollback();
			return 0;
		}

		logger('item_store: created item '.$current_post);
		item_set_last_item($item);

		if (!$parent_id || ($item['parent-uri'] === $item['uri'])) {
			$parent_id = $current_post;
		}

		// Set parent id
		$r = dba::update('item', array('parent' => $parent_id), array('id' => $current_post));

		$item['id'] = $current_post;
		$item['parent'] = $parent_id;

		// update the commented timestamp on the parent
		// Only update "commented" if it is really a comment
		if (($item['verb'] == ACTIVITY_POST) || !get_config("system", "like_no_comment")) {
			dba::update('item', array('commented' => datetime_convert(), 'changed' => datetime_convert()), array('id' => $parent_id));
		} else {
			dba::update('item', array('changed' => datetime_convert()), array('id' => $parent_id));
		}

		if ($dsprsig) {

			/*
			 * Friendica servers lower than 3.4.3-2 had double encoded the signature ...
			 * We can check for this condition when we decode and encode the stuff again.
			 */
			if (base64_encode(base64_decode(base64_decode($dsprsig->signature))) == base64_decode($dsprsig->signature)) {
				$dsprsig->signature = base64_decode($dsprsig->signature);
				logger("Repaired double encoded signature from handle ".$dsprsig->signer, LOGGER_DEBUG);
			}

			dba::insert('sign', array('iid' => $current_post, 'signed_text' => $dsprsig->signed_text,
						'signature' => $dsprsig->signature, 'signer' => $dsprsig->signer));
		}

		$deleted = tag_deliver($item['uid'], $current_post);

		/*
		 * current post can be deleted if is for a community page and no mention are
		 * in it.
		 */

		if (!$deleted && !empty($hooks['post'])) {
			$r = dbm::select('item', array(), array('id' => $current_post), array('limit' => 1));
			if ((dbm::is_result($r)) && (count($r) == 1)) {
				call_hooks($hooks['post'], $r);
			} else {
				logger('item_store: new item not found in DB, id ' . $current_post);
			}
		}

		if ($item['parent-uri'] === $item['uri']) {
			add_thread($current_post);
		} else {
			update_thread($parent_id);
		}

		dba::commit();

		/*
		 * Due to deadlock issues with the "term" table we are doing these steps after the commit.
		 * This is not perfect - but a workable solution until we found the reason for the problem.
		 */
		create_tags_from_item($current_post);
		create_files_from_item($current_post);

		/*
		 * If this is now the last-child, force all _other_ children of this parent to *not* be last-child
		 * It is done after the transaction to avoid dead locks.
		 */
		if ($item['last-child']) {
			$condition = array("`parent-uri` = ? AND `uid` = ? AND `id` != ?",
						$item['uri'], $item['uid'], $current_post);
			dbm::update('item', array('last-child' => 0), $condition);
		}

		if ($item['parent-uri'] === $item['uri']) {
			add_shadow_thread($current_post);
		} else {
			add_shadow_entry($current_post);
		}

		return $current_post;
	}

	public static function modify($item, $condition) {
	}

	public static function delete($condition) {
		dba::update('item', array('deleted' => true, 'title' => '', 'body' => '',
						'edited' => datetime_convert(), 'changed' => datetime_convert()),
					array('id' => $item["id"]));

		// Delete the thread - if it is a starting post and not a comment
		if ($target_type != 'Comment') {
			delete_thread($item["id"], $item["parent-uri"]);
		}
	}

	private static function deliver($item) {
		if ($item['parent-uri'] === $item['uri']) {
			$notify_type = 'wall-new';
		} else {
			$notify_type    = 'comment-new';
		}

		proc_run(array('priority' => PRIORITY_HIGH, 'dont_fork' => true), "include/notifier.php", $notify_type, $item['id']);
	}
}
