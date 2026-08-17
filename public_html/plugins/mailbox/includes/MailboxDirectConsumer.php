<?php
/**
 * MailboxDirectConsumer - the mailbox facts the Joinery Direct framework needs,
 * answered in one place.
 *
 * Three questions, registered as callables at plugin bootstrap so core never
 * names a mailbox symbol:
 *
 *   - **Who is user@domain?** — and, critically, ANSWERED ABOUT THE DOMAIN even
 *     when the local part matches nothing. The sealed tiers must reply
 *     identically for a real address and a made-up one, so a resolver that could
 *     only speak about addresses it recognised would have leaked existence
 *     before the decoy key ever ran.
 *
 *   - **Is this sender in that recipient's contacts?** — the canned gate's
 *     lookup. It must never throw: an unreadable list is a decline, not an
 *     error, because an error would be a distinguishable answer.
 *
 *   - **Whose vault seals this domain's Direct signing key?** — the same custody
 *     question DKIM already asks, answered the same way, so a Fortress domain
 *     cannot sign in anyone's name from a locked box.
 *
 * @version 1.2
 * @changelog 1.2 - a resolved mailbox answers with its OWN protection posture;
 *   the domain's answer stands only while no mailbox has resolved
 * @version 1.1
 * @changelog 1.1 - resolveAddress reports identity (`exists`) and routing
 *   (`stores_email`) as separate facts; the store-only rule became the mail
 *   kind's declared `email_store` requirement instead of unaddressing the
 *   recipient for every kind.
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class MailboxDirectConsumer {

	/**
	 * What this deployment knows about a Direct recipient address.
	 *
	 * Returns null only when the DOMAIN is not hosted here, which is a fact
	 * about the deployment and therefore a request-level refusal. Everything
	 * finer — does the local part exist, whose consent gates it, which key to
	 * answer with — is reported inside the array, where the framework decides
	 * how much of it may be observable at this tier.
	 */
	public static function resolveAddress(string $address): ?array {
		$domain_name = DirectProtocol::domainOf($address);
		$local_part  = DirectProtocol::localPartOf($address);
		if ($domain_name === '' || $local_part === '') {
			return null;
		}

		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->key || !$domain->get('ied_is_enabled')) {
			return null;
		}

		$answer = array(
			'hosts_domain'  => true,
			'domain_id'     => intval($domain->key),
			// The domain's answer is the right one while no mailbox has resolved —
			// mail for an address nobody created belongs to the domain. Once a
			// mailbox resolves, its own posture replaces this (below).
			'seals_content' => $domain->seals_content(),
			'exists'        => false,
			'stores_email'  => false,
			'user_id'       => 0,
			'alias_id'      => 0,
			'vault_public_key' => null,
			'key_generation'   => 0,
		);

		$alias = self::aliasFor($local_part, $domain);
		if ($alias === null) {
			// No mailbox for this local part. The domain still answered, so the
			// framework can hand back a decoy at a sealed tier and a plain
			// `declined` at Standard — the same two behaviours a real address gets
			// when it does not accept this sender.
			return $answer;
		}

		// Any live alias is an addressable IDENTITY — exists is about who, never
		// about email routing. What each kind needs from that identity is the
		// kind's own declared requirement, which the framework judges: chat needs
		// only a consenting owner, so a forwarding alias chats fine; mail needs
		// `stores_email`, because a Direct payload never becomes a MIME document,
		// so forwardStoredMessage has nothing to relay — a forward-only alias has
		// nowhere to store, and forward-AND-store would keep the copy but drop the
		// forward silently, a partial delivery the sender cannot see. Mail's
		// decline sends it back to SMTP, which runs BOTH legs.
		$answer['exists']       = true;
		$answer['alias_id']     = intval($alias->key);
		$answer['stores_email'] =
			(string)$alias->get('iea_delivery_mode') === InboundEmailAlias::MODE_STORE;
		// Protection follows the identity that owns the mail, and once a mailbox
		// has resolved that identity is the mailbox (specs/mailbox_connect_flow.md
		// § D). A pulled-in Private mailbox on a Standard domain answers sealed;
		// its neighbour on the same domain answers plaintext.
		$answer['seals_content'] = $alias->seals_content();

		// A single-owner mailbox names that owner as the consenting user and, on a
		// sealing domain, seals to their vault. A shared mailbox — several grantees,
		// no single owner — is unencrypted by nature: it names no single user, gets
		// no sealing key (so the framework gates its plaintext book at commit), and
		// is authorized against the alias's own contacts.
		$grantees = InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key));
		if (count($grantees) === 1) {
			$answer['user_id'] = intval($grantees[0]);

			$owner_id = InboundEmailMessage::sealOwnerUserId(intval($alias->key), intval($domain->key));
			if ($owner_id !== null) {
				$vault = self::vaultFor($owner_id);
				if ($vault !== null) {
					$answer['vault_public_key'] = (string)$vault->get('uev_public_key');
					$answer['key_generation']   = intval($vault->get('uev_key_generation'));
				}
			}
		}

		return $answer;
	}

	/**
	 * Is $address in this user's contacts for this mailbox?
	 *
	 * Never throws and never distinguishes "unreadable" from "not a contact":
	 * both are a decline. At Standard the list is plaintext, so this always has
	 * a real answer; at the sealed tiers it is only ever called from the
	 * deferred drain, inside the recipient's own unlock window.
	 */
	public static function isContact(int $user_id, int $alias_id, string $address): bool {
		if ($alias_id <= 0 || $address === '') {
			return false;
		}
		try {
			$contacts = new MailboxContacts();
			if ($user_id > 0) {
				$found = $contacts->lookup($user_id, $address, $alias_id);
				return is_array($found) && empty($found['locked']) && isset($found['id']);
			}
			// A shared mailbox has no single owner: its address book is the alias's
			// own plaintext contacts, and an entry any grantee added counts. (Only
			// unencrypted contacts are visible this way — which is exactly the
			// shared/group case, whose book is plaintext by nature.)
			return $contacts->aliasHasContact($alias_id, $address);
		} catch (\Throwable $e) {
			error_log('MailboxDirectConsumer: contact lookup failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Whose vault should hold this domain's Direct signing key, or null for box
	 * custody.
	 *
	 * The rule mirrors DKIM exactly: a domain that seals content and names an
	 * owner keeps its signing key sealed to that owner and unwraps it in-window
	 * per send. Everything else keeps a box-custody key, so an ordinary
	 * deployment signs without anybody having to be logged in.
	 */
	public static function signingVaultOwner(string $domain_name): ?int {
		$domain = InboundEmailDomain::GetByDomain($domain_name);
		if (!$domain || !$domain->key || !$domain->seals_content()) {
			return null;
		}
		$owner_id = intval($domain->get('ied_owner_usr_user_id'));
		if ($owner_id <= 0) {
			return null;
		}
		return self::vaultFor($owner_id) !== null ? $owner_id : null;
	}

	private static function aliasFor(string $local_part, InboundEmailDomain $domain) {
		$results = new MultiInboundEmailAlias(array(
			'domain_id' => $domain->key,
			'alias'     => strtolower($local_part),
			'deleted'   => false,
		));
		$results->load();
		if (count($results)) {
			$alias = $results->get(0);
			if ($alias->get('iea_is_enabled')) {
				return $alias;
			}
		}
		return null;
	}

	private static function vaultFor(int $user_id) {
		try {
			return UserEncryptionVault::loadForUser($user_id);
		} catch (\Throwable $e) {
			return null;
		}
	}
}
