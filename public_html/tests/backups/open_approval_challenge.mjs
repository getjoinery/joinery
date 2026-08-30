/**
 * Open an agent-sealed approval challenge with the SHIPPED BROWSER CODE.
 *
 * Not a reimplementation. This loads assets/js/recovery-readiness.js — the exact
 * file the operator's browser runs when a restore is waiting on their approval —
 * under a minimal window/document shim, and calls its openChallenge(). A second
 * copy of the algorithm written here would prove that two things this repository
 * contains agree with each other, which is not the question. The question is
 * whether the file that ships opens the blob the agent produces.
 *
 * Usage: node open_approval_challenge.mjs <fixture.json>
 * Exit 0 when the recovered plaintext is byte-for-byte what the agent sealed.
 */

import { readFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = process.argv[2];
if (!fixturePath) {
	console.error('usage: node open_approval_challenge.mjs <fixture.json>');
	process.exit(2);
}

const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
const scriptPath = path.resolve(here, '../../assets/js/recovery-readiness.js');
const source = readFileSync(scriptPath, 'utf8');

// The smallest window the file needs to load. It wires listeners on
// DOMContentLoaded and reads window.rrPanel / window.rrApproval; neither is set,
// so attachPanel finds nothing and returns. Everything else it exports is pure.
const sandbox = {
	window: {},
	document: { readyState: 'complete', addEventListener() {}, getElementById() { return null; }, querySelector() { return null; } },
	crypto: webcrypto,
	TextEncoder,
	TextDecoder,
	atob: (s) => Buffer.from(s, 'base64').toString('binary'),
	btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
	console,
	setTimeout,
	fetch: () => Promise.reject(new Error('no network in this check')),
};
sandbox.window.crypto = webcrypto;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: scriptPath });

const api = sandbox.window.rrRecovery || sandbox.window.recoveryReadiness || sandbox.rrRecovery;
const openChallenge = (api && api.openChallenge) || sandbox.openChallenge;
if (typeof openChallenge !== 'function') {
	console.error('FAIL: recovery-readiness.js exposed no openChallenge — the approval screen '
		+ 'cannot open a challenge, whatever the agent seals. Exported: '
		+ Object.keys(sandbox.window).join(', '));
	process.exit(1);
}

try {
	const recovered = await openChallenge(
		fixture.challenge, fixture.privateKey, fixture.publicKey, fixture.infoPrefix);

	if (recovered !== fixture.expected) {
		console.error('FAIL: the browser code opened the challenge but recovered different bytes.');
		console.error('  expected: ' + JSON.stringify(fixture.expected));
		console.error('  got     : ' + JSON.stringify(recovered));
		process.exit(1);
	}
	console.log('PASS: the shipped browser code opened the agent\'s approval challenge, '
		+ 'recovering the job binding and the statement hash intact.');

	// The value above is not just "what was sealed" — it is the byte string the
	// agent compares an answer against, and the page posts it back UNMODIFIED
	// (attachCeremony assigns it to the hidden field and submits). So it has to
	// survive a form POST unchanged. A line break would not: urlencoded
	// submission normalises them to CRLF, and the comparison would fail on an
	// answer that was in every other respect correct.
	//
	// This is the check that would have caught the mechanism's worst bug, where
	// the agent compared only the first line of a multi-line plaintext and every
	// genuine approval was refused. Both sides' unit tests were green, because
	// each was written against its author's belief about the other.
	if (/[\r\n]/.test(recovered)) {
		console.error('FAIL: the value the operator posts back contains a line break, so it cannot '
			+ 'survive a form POST byte-for-byte: ' + JSON.stringify(recovered));
		process.exit(1);
	}
	if (!/\bjob:\d+\b/.test(recovered) || !/\bstatement:[0-9a-f]{8}/.test(recovered)) {
		console.error('FAIL: the recovered value does not carry the job and statement binding, so '
			+ 'an answer for one restore would satisfy another: ' + JSON.stringify(recovered));
		process.exit(1);
	}
	console.log('PASS: it is a single line carrying the binding, so it round-trips a form POST '
		+ 'as the exact bytes the agent compares.');
} catch (err) {
	console.error('FAIL: the browser code could not open the agent\'s challenge: ' + (err && err.message));
	console.error('  This is the seam where the agent seals in Go and the operator opens in');
	console.error('  JavaScript. A disagreement here presents to a customer as "my recovery');
	console.error('  key does not work", during a restore.');
	process.exit(1);
}

// The negative: a different key must not open it. Cheap, and it is the check
// that would catch a construction that ignored the recipient key entirely.
try {
	const wrong = Buffer.alloc(32, 7).toString('base64');
	await openChallenge(fixture.challenge, wrong, fixture.publicKey, fixture.infoPrefix);
	console.error('FAIL: a key that is not the recipient opened the challenge.');
	process.exit(1);
} catch {
	console.log('PASS: a different recovery key does not open it.');
}

// And the wrong HKDF context must not either — that separation is what stops an
// approval challenge and a possession challenge being answers to each other.
try {
	await openChallenge(fixture.challenge, fixture.privateKey, fixture.publicKey,
		'joinery-backup-recovery-possession:');
	console.error('FAIL: the possession context opened an approval challenge — '
		+ 'the two are not domain-separated.');
	process.exit(1);
} catch {
	console.log('PASS: the possession context does not open an approval challenge.');
}
