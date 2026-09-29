/**
 * The mailbox's part of rotating the mail vault key (JoinerySealed.resealScope).
 *
 * The messages, drafts and search key move with the rotation's row walk; what
 * this adds is the relay pins (specs/client_custody_mail.md § R10). Each pin is
 * the relay identity this browser trusts for a relay-fronted Fortress mailbox,
 * MACed with a key derived from the mail vault's secret (session.mac). A new
 * secret derives a new MAC key, so every pin is checked with the old key and
 * MACed again with the new one; the relay it names does not change, so no
 * step-up is asked. Registered server-side by VaultUnlock::clientReseal('mail')
 * in the mailbox bootstrap, which is what puts it on the rotation page.
 *
 * Safe to run twice: a pin the new key already accepts was moved by an earlier
 * run, and is left. A pin neither key made was not this vault's before the
 * rotation either; it is left, and reported (ctx.skip), and the mailbox page
 * raises its alarm for it.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	if (!window.JoinerySealed) return;

	var PIN_PREFIX = 'joinery-relay-pin:v1\n';

	function pinMessage(aliasId, identity) {
		return new TextEncoder().encode(PIN_PREFIX + aliasId + '\n' + identity);
	}

	async function macOf(session, pin) {
		return VaultCrypto.b64encode(await session.mac(pinMessage(pin.alias_id, pin.relay_identity_public_key)));
	}

	JoinerySealed.onReseal('mail', async function (ctx) {
		var pins = ((await joineryApi.post('mailbox/relay_pins', {})) || {}).pins || [];
		var done = 0;
		for (var i = 0; i < pins.length; i++) {
			var pin = pins[i];
			if (ctx.newSession && (await macOf(ctx.newSession, pin)) === pin.mac) continue;   // moved already
			if ((await macOf(ctx.oldSession, pin)) !== pin.mac || !ctx.newSession) {
				ctx.skip('The relay pin of mailbox ' + pin.alias_id);
				continue;
			}
			await joineryApi.post('mailbox/relay_pin_set', { alias_id: pin.alias_id,
				relay_identity_public_key: pin.relay_identity_public_key, mac: await macOf(ctx.newSession, pin) });
			done++;
		}
		if (pins.length) ctx.progress('Re-made ' + done + ' relay pin' + (done === 1 ? '' : 's') + '…');
	});
})();
