/**
 * Exercises the reader's sender-label helpers under Node.
 *
 * The helpers live inside mailbox_reader.js's IIFE, which needs a DOM, so the
 * block is sliced out by its boundary markers and evaluated on its own. A slice
 * that no longer resolves is itself a failure: if the helpers are renamed or
 * moved apart, this gate must be updated with them rather than quietly passing.
 *
 * What is under guard (the list column shows a name and hides the address):
 *   - a From display name wins whenever the message carries one
 *   - with no name the sending ORGANIZATION is the label, not the local part —
 *     hello@fireworks.ai reads as Fireworks, which is the whole point
 *   - except at a consumer mail provider, where the local part is the only
 *     identity there is, so Gmail must never become the label
 *   - the open message keeps the address beside the name: a display name is
 *     attacker-chosen, and the address is what survived DKIM
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', 'assets', 'mailbox_reader.js'), 'utf8');

const START = 'var CONSUMER_MAIL_DOMAINS';
const END = 'function threadRow(';
const from = source.indexOf(START);
const to = source.indexOf(END);
if (from < 0 || to < 0 || to <= from) {
	console.log('  FAIL: could not slice the sender-label helpers out of mailbox_reader.js'
		+ ' (markers moved — update this gate alongside them)');
	console.log('RESULT: FAIL 0 1');
	process.exit(1);
}

const block = source.slice(from, to);
const helpers = new Function(block
	+ '; return { senderName: senderName, senderFull: senderFull, orgLabel: orgLabel };')();

let passed = 0;
let failed = 0;

function eq(label, actual, expected) {
	if (actual === expected) {
		console.log('  PASS: ' + label);
		passed++;
	} else {
		console.log('  FAIL: ' + label + ' (got ' + JSON.stringify(actual)
			+ ', want ' + JSON.stringify(expected) + ')');
		failed++;
	}
}

function ok(label, cond) {
	if (cond) { console.log('  PASS: ' + label); passed++; }
	else { console.log('  FAIL: ' + label); failed++; }
}

const name = helpers.senderName;

console.log('== a display name always wins ==');
eq('unquoted name', name('Fireworks <hello@fireworks.ai>'), 'Fireworks');
eq('quoted name (the stored form)', name('"Fireworks Team" <hello@fireworks.ai>'), 'Fireworks Team');
eq('name kept verbatim, not re-cased', name('"iA Writer" <news@ia.net>'), 'iA Writer');

console.log('== no name: the organization is the label, not the local part ==');
eq('hello@fireworks.ai', name('hello@fireworks.ai'), 'Fireworks');
eq('no-reply at a subdomain', name('no-reply@accounts.google.com'), 'Google');
eq('meet@google.com', name('meet@google.com'), 'Google');
eq('product@stripe.com', name('product@stripe.com'), 'Stripe');
eq('angle-bracketed with no name', name('<hello@fireworks.ai>'), 'Fireworks');
eq('hyphenated org keeps its hyphen', name('alerts@e-trade.com'), 'E-Trade');
eq('deep infra subdomains dropped',
	name('noreply@mail.notifications.example.co.uk'), 'Example');
eq('ccTLD without a registry second level', name('info@bundesbank.de'), 'Bundesbank');

console.log('== consumer providers: the person is the identity ==');
eq('gmail address', name('jeremy.tunnell@gmail.com'), 'Jeremy Tunnell');
eq('hotmail address', name('bob@hotmail.com'), 'Bob');
eq('icloud address', name('a.b.cooper@icloud.com'), 'A B Cooper');
eq('proton address', name('someone@proton.me'), 'Someone');
ok('no consumer provider ever becomes the label',
	['gmail', 'Gmail', 'Yahoo', 'Icloud', 'Proton', 'Outlook']
		.indexOf(name('x.y@yahoo.com')) === -1);

console.log('== degenerate input ==');
eq('empty', name(''), '(unknown)');
eq('null', name(null), '(unknown)');
eq('not an address at all', name('garbage'), 'garbage');
eq('address with no local part', name('@gmail.com'), '@gmail.com');

console.log('== the open message keeps the address visible ==');
eq('stored quoting rendered plainly',
	helpers.senderFull('"Fireworks" <hello@fireworks.ai>'), 'Fireworks <hello@fireworks.ai>');
eq('bare address unchanged', helpers.senderFull('hello@fireworks.ai'), 'hello@fireworks.ai');
eq('empty', helpers.senderFull(''), '(unknown)');
// A friendly name proves nothing about the sender; the address must stay on screen.
ok('a spoofed display name cannot hide its domain',
	helpers.senderFull('"PayPal Support" <billing@evil.example>').indexOf('billing@evil.example') !== -1);

console.log('== the host reducer ==');
eq('bare host', helpers.orgLabel('fireworks.ai'), 'fireworks');
eq('subdomain', helpers.orgLabel('accounts.google.com'), 'google');
eq('ccTLD second level', helpers.orgLabel('example.co.uk'), 'example');
eq('single label', helpers.orgLabel('localhost'), 'localhost');

console.log('');
if (failed === 0) {
	console.log('RESULT: PASS ' + passed + ' ' + failed);
	process.exit(0);
}
console.log('RESULT: FAIL ' + passed + ' ' + failed);
process.exit(1);
