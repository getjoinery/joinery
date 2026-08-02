<?php
/** @joinery-test
 * name: forward_consent
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Forwarding off a protected domain is consent-gated
 * (specs/implemented/sealed_content_egress.md § resolved decision 7).
 *
 * A domain set to a protecting security level promises its mail cannot be read
 * without the owner's key. A forwarding filter breaks that promise by design:
 * the copy leaves over SMTP in clear text, permanently out of the vault's
 * reach. That is a legitimate thing to want — but only as an informed choice,
 * so it happens behind a written acknowledgment naming where the mail goes.
 *
 * What this pins down:
 *
 *  - a standard domain needs no acknowledgment, so ordinary forwarding is
 *    untouched;
 *  - a protected domain will not forward without one;
 *  - the acknowledgment is bound to the destination, so repointing the filter
 *    somewhere else needs fresh consent rather than inheriting the old one;
 *  - a filter that may not forward still matches, labels and files — losing
 *    consent must not quietly lose the mail;
 *  - raising the domain's level revokes every acknowledgment on it, because
 *    consent given at one level was not given at a higher one.
 *
 * Run: php tests/run.php db --filter=forward_consent
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_filter_class.php'));

/** A domain at the given security level. */
function fc_domain(string $level, string $suffix): InboundEmailDomain {
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'fc-' . $suffix . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', $level);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);
	return $domain;
}

/** A domain-wide filter that labels and forwards. */
function fc_filter(int $domain_id, string $forward_to): InboundEmailFilter {
	$filter = new InboundEmailFilter(NULL);
	$filter->set('fil_ied_inbound_email_domain_id', $domain_id);
	$filter->set('fil_name', 'consent fixture');
	$filter->set('fil_match_from', 'someone@example.com');
	$filter->set('fil_action_star', true);
	$filter->set('fil_action_forward_to', $forward_to !== '' ? $forward_to : null);
	$filter->prepare();
	$filter->save();
	harness_register_row('fil_inbound_email_filters', 'fil_inbound_email_filter_id', (int)$filter->key);
	return $filter;
}

try {
	$destination = 'elsewhere@example.com';

	// =====================================================================
	section('a standard domain needs no acknowledgment');

	$standard = fc_domain(InboundEmailDomain::LEVEL_STANDARD, 'std');
	$std_filter = fc_filter((int)$standard->key, $destination);
	check(!$std_filter->forwardNeedsAcknowledgment(),
		'nothing to acknowledge — a standard domain never promised the mail was unreadable');
	check($std_filter->forwardConsentSatisfied(), 'so consent is satisfied by default');
	$std_actions = $std_filter->buildActionSet();
	check($std_actions['forward_to'] === array($destination), 'and the forward action stands');

	// =====================================================================
	section('a protected domain will not forward without one');

	$sealed = fc_domain(InboundEmailDomain::LEVEL_FORTRESS, 'seal');
	check($sealed->seals_content(), 'the fixture domain really does seal its content');

	$filter = fc_filter((int)$sealed->key, $destination);
	check($filter->forwardNeedsAcknowledgment(), 'the filter needs an acknowledgment');
	check(!$filter->forwardConsentSatisfied(), 'and does not have one yet');

	$actions = $filter->buildActionSet();
	check($actions['forward_to'] === array(), 'so the forward action is dropped');
	check($actions['star'] === true,
		'while the rest of the filter still runs — mail is matched and filed, only the copy off the server stops');

	// =====================================================================
	section('acknowledging it lets the mail through');

	$filter->recordForwardAcknowledgment(1);
	$filter->save();
	$reloaded = new InboundEmailFilter((int)$filter->key, TRUE);
	check($reloaded->forwardConsentSatisfied(), 'the acknowledgment stands after a round trip');
	check($reloaded->buildActionSet()['forward_to'] === array($destination),
		'and the forward action is back');
	check((string)$reloaded->get('fil_forward_ack_destination') === $destination,
		'the destination is recorded with the acknowledgment, not merely the fact of one');

	// =====================================================================
	section('consent is for one destination, not for forwarding in general');

	$reloaded->set('fil_action_forward_to', 'somewhere-else@example.com');
	$reloaded->save();
	$repointed = new InboundEmailFilter((int)$reloaded->key, TRUE);
	check(!$repointed->forwardConsentSatisfied(),
		'pointing the filter at a different address needs its own consent');
	check($repointed->buildActionSet()['forward_to'] === array(),
		'so it does not forward to the new address on the strength of the old agreement');

	// =====================================================================
	section('raising the level revokes every acknowledgment on the domain');

	$repointed->set('fil_action_forward_to', $destination);
	$repointed->recordForwardAcknowledgment(1);
	$repointed->save();
	check((new InboundEmailFilter((int)$repointed->key, TRUE))->forwardConsentSatisfied(),
		're-acknowledged, it forwards again');

	$revoked = InboundEmailFilter::clearForwardAcknowledgments((int)$sealed->key);
	check($revoked === 1, 'the raise revokes the acknowledgment', 'revoked=' . $revoked);

	$after_raise = new InboundEmailFilter((int)$repointed->key, TRUE);
	check(!$after_raise->forwardConsentSatisfied(), 'and the filter stops forwarding');
	check((string)$after_raise->get('fil_action_forward_to') === $destination,
		'the address is left in place, so re-acknowledging is one tick rather than a re-entry');
	$after_actions = $after_raise->buildActionSet();
	check($after_actions['forward_to'] === array(), 'the forward is dropped');
	check($after_actions['star'] === true, 'and the mail is still matched, starred and filed');

	// =====================================================================
	section('clearing the address clears the obligation');

	$after_raise->set('fil_action_forward_to', null);
	$after_raise->save();
	$no_forward = new InboundEmailFilter((int)$after_raise->key, TRUE);
	check(!$no_forward->forwardNeedsAcknowledgment(), 'a filter that does not forward needs no consent');
	check($no_forward->forwardConsentSatisfied(), 'and is not held back by the missing one');

} finally {
	// Cleanup only — never harness_finish() here; it exit()s, which would
	// swallow an in-flight exception and report a short PASS.
}

harness_finish();
