<?php
/**
 * Smtp2GoLeg — the three provider acts that give one customer outbound mail,
 * whoever the customer is.
 *
 * A Managed site (ProvisionHostedMail, one step per tick against a provision
 * row) and a self-hosted services tenant (JoineryServices, all at once in the
 * enrol call) build the same thing at SMTP2GO: a subaccount capped at the
 * allowance, a sender domain inside it, and one SMTP user inside that. What
 * differs is only where the credential goes afterwards — over the agent
 * channel, or back in the enrol response. So the provider calls live here
 * once, with their rules (specs/services_phase2_platform.md §2, §14):
 *
 *   - the subaccount id must be saved by the caller BEFORE the limit is set,
 *     which is why creation and the limit are two functions: a crash between
 *     them must never orphan a subaccount this plane never names again;
 *   - zero DNS records from the provider is a failure, not a note — a sender
 *     domain with nothing to publish never verifies;
 *   - the SMTP username carries the customer's slug and the test_ prefix of a
 *     test purchase; the user is minted in the provider's sandbox status when
 *     this plane says so (server_manager_smtp2go_sandbox_users).
 *
 * Nothing here ever puts the master key on a box.
 *
 * @version 1.0 - lifted from ProvisionHostedMail
 */
class Smtp2GoLeg {

	/** Where every customer sends through. */
	const SMTP_HOST = 'mail.smtp2go.com';
	const SMTP_PORT = 587;

	/**
	 * The sending identity is mail.<domain>, not the apex.
	 *
	 * A subdomain keeps the customer's own apex SPF and DKIM out of the
	 * operator's reach: a customer who later moves to their own provider, or who
	 * already sends from the apex through something else, is not fighting a
	 * record this plane published.
	 */
	public static function sendingDomain(string $domain): string {
		return 'mail.' . strtolower(trim($domain));
	}

	/**
	 * Create the customer's subaccount. Returns the id; the CALLER SAVES IT
	 * BEFORE calling setLimit() — a crash between the two would otherwise
	 * leave a subaccount at the provider that nothing here names again.
	 */
	public static function createSubaccount(Smtp2GoClient $client, string $label, string $email): string {
		return $client->addSubaccount($label, $email);
	}

	/** Cap the subaccount at the allowance. Convergent; safe to repeat. */
	public static function setLimit(Smtp2GoClient $client, string $subaccount_id, int $allowance): void {
		$client->setSubaccountLimit($subaccount_id, $allowance);
	}

	/**
	 * Register the sender domain inside the subaccount and return the provider's
	 * id and the DNS records to publish.
	 *
	 * @throws Smtp2GoLegException when the provider answered with no records.
	 * @return array{id:string, records:array}
	 */
	public static function addSenderDomain(Smtp2GoClient $client, string $subaccount_id, string $sender_domain): array {
		$result = $client->addDomain($subaccount_id, $sender_domain);
		// NO RECORDS IS A FAILURE, not a note. A sending domain with nothing to
		// publish never verifies, so mail from this site would be unsigned for
		// ever — while the subaccount, the domain and the SMTP user all looked
		// set up and every dashboard read green. The likeliest cause is the
		// client not recognising the shape the provider answered in, which is
		// exactly the kind of thing that must stop the line rather than pass
		// quietly through it.
		if (!$result['records']) {
			throw new Smtp2GoLegException(
				'The provider registered the sending domain but this platform could not read any DNS '
				. 'records out of its answer. Nothing was published, so mail from this site would be '
				. 'unsigned. Capture the domain/add response and check Smtp2GoProvider::recordsOf '
				. 'against it before retrying.');
		}
		return $result;
	}

	/**
	 * Mint the one credential that reaches the customer's box: an SMTP user
	 * inside their own subaccount, named for their slug.
	 *
	 * @return array{username:string, password:string, id:string}
	 */
	public static function mintSmtpUser(Smtp2GoClient $client, string $subaccount_id, string $slug, string $prefix): array {
		$username = Smtp2GoClient::mintUsername($slug, $prefix);
		$password = Smtp2GoClient::mintPassword();
		return $client->addSmtpUser($subaccount_id, $username, $password, self::sandboxUsers());
	}

	/**
	 * The values a site sends through this credential with. VALUES ONLY: which
	 * settings they land in is decided on the site (utils/hosted_mail_settings.php
	 * for a Managed node, the Email step's enrol handler for a services tenant),
	 * and this end cannot name a setting.
	 *
	 * The envelope sender and the HELO name are the SENDING identity, which is
	 * the subdomain the provider verified — not the apex the site answers on.
	 * Getting this wrong is the difference between mail that authenticates and
	 * mail that lands in spam.
	 */
	public static function sendValues(string $username, string $password, string $sender_domain): array {
		return array(
			'service'  => 'smtp',
			'host'     => self::SMTP_HOST,
			'port'     => self::SMTP_PORT,
			'username' => $username,
			'password' => $password,
			'sender'   => 'bounces@' . $sender_domain,
			'helo'     => $sender_domain,
			'hostname' => $sender_domain,
		);
	}

	/**
	 * Does this plane mint its customers' SMTP users in the provider's sandbox
	 * status (accepted, counted, never delivered)? On for a rehearsal plane, so
	 * a site built to prove the pipeline can email nobody; off for real.
	 */
	public static function sandboxUsers(): bool {
		$value = trim((string)Globalvars::get_instance()->get_setting('server_manager_smtp2go_sandbox_users', false, true));
		return $value !== '' && $value !== '0';
	}

	/** The monthly send allowance every customer subaccount is capped at. */
	public static function sendAllowance(): int {
		$value = (int)Globalvars::get_instance()->get_setting('server_manager_hosted_send_allowance', true, true);
		return $value > 0 ? $value : 1000;
	}
}

class Smtp2GoLegException extends Exception {}
