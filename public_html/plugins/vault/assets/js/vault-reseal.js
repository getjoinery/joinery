/**
 * The password vault's part of rotating the passwords vault key
 * (JoinerySealed.resealScope).
 *
 * The store DEK that encrypts every entry is sealed to the vault's public key
 * (vlk_wrapped_dek). This opens it with the old key and seals it to the new
 * one through vault/keyring_replace, which accepts it only while the rotation
 * is pending. The entries themselves are untouched: their key did not change.
 * Registered server-side by VaultUnlock::clientReseal() in the plugin's
 * bootstrap, which is what puts it on the rotation page.
 *
 * Safe to run twice: a store key the old vault key cannot open but the new one
 * can was moved by an earlier run. One neither opens was unreadable before the
 * rotation; it is left and reported (ctx.skip).
 *
 * @version 1.1 - a store key neither key opens is reported, not fatal
 * @version 1.0
 */
(function () {
	'use strict';
	if (!window.JoinerySealed) return;

	JoinerySealed.onReseal('passwords', async function (ctx) {
		var kr = await joineryApi.post('vault/keyring_get', {});
		if (!kr || !kr.set_up || !kr.wrapped_dek) return;
		var dek;
		try {
			dek = await ctx.oldSession.openSealed(kr.wrapped_dek);
		} catch (e) {
			if (ctx.newSession) {
				try { await ctx.newSession.openSealed(kr.wrapped_dek); return; } catch (e2) { /* neither key */ }
			}
			// Unreadable before the rotation as well; leave it as it is.
			ctx.skip('the password store key');
			return;
		}
		var blob = await ctx.oldSession.sealTo(dek, ctx.newPublicKey);
		dek.fill(0);
		await joineryApi.post('vault/keyring_replace', { wrapped_dek: blob });
		ctx.progress('Re-sealed the password store key…');
	});
})();
