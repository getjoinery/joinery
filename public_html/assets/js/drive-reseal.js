/**
 * Drive's part of rotating the drive vault key (JoinerySealed.resealScope).
 *
 * Every Fortress file key the member can read is a FileKeyGrant sealed to
 * their drive public key — on their own files and on files shared with them.
 * This opens each with the old key and seals it to the new one, through
 * drive_key_grants_reseal (the member's own grant rows only, only while the
 * rotation is pending). Registered server-side by VaultUnlock::clientReseal()
 * in includes/DriveSealed.php, which is what puts it on the rotation page.
 *
 * Safe to run twice: a grant the old key cannot open but the new one can was
 * moved by an earlier run, and is left. A grant neither key opens was
 * unreadable before the rotation; it is left too, and reported (ctx.skip).
 *
 * @version 1.1 - a grant neither key opens is skipped and reported, not fatal
 * @version 1.0
 */
(function () {
	'use strict';
	if (!window.JoinerySealed) return;

	JoinerySealed.onReseal('drive', async function (ctx) {
		var after = 0, done = 0;
		for (;;) {
			var page = await joineryApi.post('drive_key_grants_reseal', { mode: 'list', after_id: after, limit: 200 });
			var grants = page.grants || [];
			var keys = {};
			for (var i = 0; i < grants.length; i++) {
				var g = grants[i], fk = null;
				try {
					fk = await ctx.oldSession.openSealed(g.wrapped_file_key);
				} catch (e) {
					if (ctx.newSession) {
						try { await ctx.newSession.openSealed(g.wrapped_file_key); continue; } catch (e2) { /* neither key */ }
					}
					// Unreadable before the rotation as well; leave it as it is.
					ctx.skip('Drive file ' + g.file_id);
					continue;
				}
				keys[g.file_id] = await ctx.oldSession.sealTo(fk, ctx.newPublicKey);
				fk.fill(0);
			}
			if (Object.keys(keys).length) {
				await joineryApi.post('drive_key_grants_reseal', { mode: 'write', keys: keys });
			}
			done += grants.length;
			ctx.progress('Re-sealed ' + done + ' Drive file key' + (done === 1 ? '' : 's') + '…');
			if (!page.next_after_id) break;
			after = page.next_after_id;
		}
	});
})();
