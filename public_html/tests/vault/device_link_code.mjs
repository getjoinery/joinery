/**
 * The approval page seals a vault only to a device key the typed code was
 * made from (assets/js/device-link.js keyIsTheCodes; DeviceLink::code_for_key
 * in PHP). The code reaches the person from the device; the key comes from the
 * site's records. A key changed in those records gives another code, so the
 * hand-over is refused.
 *
 * device-link.js runs unchanged in a vm context with stand-ins for the page.
 *
 * Exit 0 = pass. Run by device_link_code_gate.sh.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', '..', 'assets', 'js', 'device-link.js'), 'utf8');

let failed = 0;
function check(ok, label, detail) {
	console.log((ok ? 'PASS: ' : 'FAIL: ') + label + (ok || detail === undefined ? '' : ' (' + detail + ')'));
	if (!ok) failed++;
}

const doc = { readyState: 'loading', addEventListener() {}, getElementById: () => null, querySelector: () => null, querySelectorAll: () => [] };
const win = { joineryApi: { post: async () => ({}) }, document: doc };
const ctx = { window: win, document: doc, crypto: webcrypto, atob: (s) => Buffer.from(s, 'base64').toString('binary'),
	TextEncoder, Uint8Array, Promise, String, Array, Error, console, encodeURIComponent };
vm.createContext(ctx);
vm.runInContext(source, ctx, { filename: 'device-link.js' });
const DL = win.JoineryDeviceLink;

const b64 = (n, byte) => Buffer.alloc(n, byte).toString('base64');
const rand = (n) => Buffer.from(webcrypto.getRandomValues(new Uint8Array(n))).toString('base64');

// The vector tests/functional/drive/sync_contract_test.php holds for the PHP side.
const vector = await DL.codeForKey(b64(1216, 7), b64(16, 9));
check(vector === 'H8ME3V53', 'the page gives the same code as the server for the shared vector', vector);

check(DL.normalizeCode('h8me-3v53') === 'H8ME3V53' && DL.normalizeCode('O0 il-Lu') === '00111V',
	'a typed code folds as the server folds it', DL.normalizeCode('O0 il-Lu'));

const deviceKey = rand(1216), nonce = rand(16);
const code = await DL.codeForKey(deviceKey, nonce);
const shown = code.slice(0, 4).toLowerCase() + '-' + code.slice(4);
check(await DL.keyIsTheCodes(shown, { device_pubkey: deviceKey, code_nonce: nonce }), 'the device\'s own key passes, however the code was typed');

// The attack: the key on the waiting row is replaced after the device was given its code.
const attackerKey = rand(1216);
check(!(await DL.keyIsTheCodes(shown, { device_pubkey: attackerKey, code_nonce: nonce })), 'a key swapped on the row is refused');
check(!(await DL.keyIsTheCodes(shown, { device_pubkey: attackerKey, code_nonce: rand(16) })), 'and with a new nonce beside it');
check(!(await DL.keyIsTheCodes(shown, { device_pubkey: deviceKey, code_nonce: null })), 'a key with no nonce is refused');
check(!(await DL.keyIsTheCodes(shown, { device_pubkey: 'not base64!', code_nonce: nonce })), 'a key that cannot be read is refused');
check(!(await DL.keyIsTheCodes(shown, null)), 'nothing to check is refused');

// The check runs before any vault is opened or sealed.
const approve = source.slice(source.indexOf('async function approve'));
const at = (needle) => approve.indexOf(needle);
check(at('keyIsTheCodes(code, resolved)') > 0 && at('keyIsTheCodes(code, resolved)') < at('sealVaultKeyFor(resolved.device_pubkey, SCOPE')
	&& at('keyIsTheCodes(code, resolved)') < at('sealVaultKeyFor(resolved.device_pubkey,\n'),
	'approval checks the key before it seals any vault to it');

console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
