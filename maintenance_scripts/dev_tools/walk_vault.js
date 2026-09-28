/**
 * Dev walk fixtures (specs/dev_walk_fixtures.md), the browser half: run with the
 * MCP tool browser_run_code_unsafe and `filename` /tmp/playwright-mcp/walk_vault.js
 * (walk_fixture.php create copies it there), right after
 * `php walk_fixture.php create <purpose>`.
 *
 * It signs the browser in as the handoff's user at /login with the handoff
 * password (the MCP browser runs --isolated, so it starts signed out; a
 * browser that is already signed in, from a shared profile, switches with the
 * admin's login-as instead), adds a CDP virtual authenticator (one per
 * browser: an existing one is reused), adds a passkey with the same password,
 * and sets up the vault. The recovery codes are shown by the page
 * and never read back. Returns the user id and the vault's status line.
 *
 * Delete the handoff file afterwards (walk_fixture.php also drops one older
 * than five minutes).
 *
 * @version 1.1 - signs in at /login with the fixture's own password
 * @version 1.0
 */
async (page) => {
	const base = 'https://dev.getjoinery.com';
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
		signin = 'login_as';
		await page.goto(base + '/admin/admin_user_login_as?usr_user_id=' + encodeURIComponent(handoff.user_id));
		await page.waitForTimeout(1500);
	}
	if (/\/login|verify-totp/.test(page.url())) {
		return { ok: false, step: signin, error: 'sign-in did not complete', url: page.url() };
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
