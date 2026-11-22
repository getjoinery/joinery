/**
 * Dev walk fixtures (specs/dev_walk_fixtures.md), the browser half: run with the
 * MCP tool browser_run_code_unsafe and `filename` /tmp/playwright-mcp/walk_vault.js
 * (walk_fixture.php create copies it there), right after
 * `php walk_fixture.php create <purpose>`.
 *
 * It signs the browser in as the handoff's user at /login with the handoff
 * password (the MCP browser runs --isolated, so it starts signed out; a
 * browser that is already signed in, from a shared profile, switches with the
 * admin's login-as button instead), confirms the browser is now the fixture
 * user and stops if it is anyone else, adds a CDP virtual authenticator (one
 * per browser: an existing one is reused), adds a passkey with the same
 * password, and sets up the vault. The recovery codes are shown by the page
 * and never read back. Returns the user id and the vault's status line.
 *
 * Delete the handoff file afterwards (walk_fixture.php also drops one older
 * than five minutes).
 *
 * @version 1.3 - signs in at the handoff's site (base), not a fixed address; an account the site holds at its
 *   second-factor page before any other opens is proved by the passkey step instead: adding a passkey
 *   asks for the fixture's own password, which another account's session refuses, and the name is
 *   read on the profile page once that page opens, before the vault is touched
 * @version 1.2 - login-as is a POST; stops unless signed in as the fixture user
 * @version 1.1 - signs in at /login with the fixture's own password
 * @version 1.0
 */
async (page) => {
	const tab = await page.context().newPage();
	let handoff;
	try {
		await tab.goto('file:///tmp/playwright-mcp/walk-handoff.json');
		handoff = JSON.parse(await tab.evaluate(() => document.body.innerText));
	} catch (e) {
		await tab.close();
		return { ok: false, step: 'handoff', error: 'no readable handoff: run walk_fixture.php create first' };
	}
	await tab.close();
	const base = handoff.base || 'https://dev.getjoinery.com';

	// Sign in as the fixture user: its own password at /login, or (a browser
	// already signed in by a remembered admin) the admin's login-as.
	await page.context().clearCookies({ name: 'PHPSESSID' });
	await page.goto(base + '/login');
	await page.waitForTimeout(1500);
	const email = page.locator('input[name="email"]');
	let signin = 'login';
	if (await email.count()) {
		await email.fill(handoff.email);
		await page.locator('input[name="password"]').fill(handoff.password);
		await email.locator('xpath=ancestor::form').locator('[type=submit]').first().click();
		await page.waitForTimeout(3000);
	} else {
		// The admin page's button is a form POST; the context's request shares
		// the browser's cookies, so the switched session lands in the browser.
		signin = 'login_as';
		await page.context().request.post(base + '/admin/admin_user_login_as',
			{ form: { usr_user_id: String(handoff.user_id) } });
		await page.goto(base + '/');
		await page.waitForTimeout(1500);
	}
	if (/\/login|verify-totp/.test(page.url())) {
		return { ok: false, step: signin, error: 'sign-in did not complete', url: page.url() };
	}

	// Everything below changes the signed-in account's security settings, so
	// it runs only as the fixture user (named Claude / Walk <purpose>). A site
	// that requires a second factor holds the account at its security page
	// until one exists, so the profile page may not open yet: then the passkey
	// step is the proof (it asks for the fixture's password), and the name is
	// read once the passkey exists, before the vault.
	const purpose = (handoff.email.match(/^claude-walk-(.+)@example\.com$/) || [])[1];
	if (!purpose) return { ok: false, step: 'identity', error: 'the handoff is not a walk fixture', signin };
	async function isFixture() {
		await page.goto(base + '/profile/account_edit');
		await page.waitForTimeout(1500);
		if (!/\/profile\/account_edit/.test(page.url())) return null;   // held at the security page
		const last = await page.locator('input[name="usr_last_name"]').inputValue().catch(() => null);
		return last === 'Walk ' + purpose;
	}
	const before = await isFixture();
	if (before === false) {
		return { ok: false, step: 'identity', error: 'the browser is not signed in as the fixture user; stopped before touching any security setting', signin };
	}

	// One internal virtual authenticator per browser.
	const cdp = await page.context().newCDPSession(page);
	await cdp.send('WebAuthn.enable', { enableUI: false });
	let authenticator = 'reused';
	try {
		const r = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
			protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true,
			isUserVerified: true, automaticPresenceSimulation: true, hasPrf: true } });
		authenticator = r.authenticatorId ? 'added' : 'reused';
	} catch (e) { /* this browser already has one */ }

	// The passkey (adding one asks for the account password).
	await page.goto(base + '/profile/security');
	await page.waitForTimeout(2500);
	await page.getByRole('button', { name: /Add a Passkey/i }).first().click();
	await page.waitForTimeout(2000);
	await page.evaluate((v) => {
		const inp = Array.from(document.querySelectorAll('input[type=password]')).find(i => i.offsetParent);
		inp.value = v; inp.dispatchEvent(new Event('input', { bubbles: true }));
	}, handoff.password);
	await page.getByRole('button', { name: 'Continue' }).first().click();
	await page.waitForTimeout(5000);
	const naming = page.getByRole('dialog');
	if (await naming.count()) {
		await naming.last().getByRole('textbox').fill('Claude virtual passkey (walk)');
		await naming.last().getByRole('button', { name: 'Continue' }).click();
		await page.waitForTimeout(6000);
	}

	if (before === null && (await isFixture()) !== true) {
		return { ok: false, step: 'identity', error: 'after the passkey the profile is not the fixture user\'s; stopped before the vault', signin };
	}
	await page.goto(base + '/profile/security');
	await page.waitForTimeout(2500);

	// The vault. Its recovery codes are on screen until Done; nothing reads them.
	await page.getByRole('button', { name: 'Set Up Your Vault' }).first().click();
	await page.waitForTimeout(6000);
	const ack = page.getByRole('dialog').last().getByRole('button', { name: 'I understand' });
	if (await ack.count()) {
		await ack.click();
		await page.waitForTimeout(8000);
	}
	const done = page.locator('main').getByRole('button', { name: 'Done' });
	if (await done.count()) {
		await done.click();
		await page.waitForTimeout(4000);
	}
	const status = await page.evaluate(() => {
		const m = document.body.innerText.replace(/\s+/g, ' ').match(/Encrypted Vault.{0,60}?Status: (\w+)/);
		return m ? m[1] : null;
	});
	return { ok: status === 'Unlocked', user_id: handoff.user_id, signin, authenticator, vault: status };
}
