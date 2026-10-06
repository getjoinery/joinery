<?php
/** @joinery-test
 * name: mailbox_device_ai_panel
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * "Your AI, your model" — the decisions the AI panel section makes for an
 * end-to-end encrypted mailbox (specs/fortress_mail_device_ai.md § R7), run in
 * node against the real mailbox_device_ai.js:
 *   - which state the section is in (hidden off Fortress; no address yet;
 *     an address but no model in this browser; ready), and that a key and
 *     model saved for another origin are never offered to this one;
 *   - that the call address stays on the registered origin whatever path is
 *     typed;
 *   - which gate a Test outcome names: the browser asking or blocking, the
 *     model refusing this site, a wrong key, a missing model, a context too
 *     small (in the model's own words), or reachable.
 *
 * @version 1.4 - no automatic check: its outcomes go with it
 * @version 1.3 - the automatic check's outcomes
 * @version 1.2 - the site's own model: offered to register, to prefill, or not at all
 * @version 1.1 - a missing-model answer checked against the model list
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$js_path = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_device_ai.js');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
	harness_skip('device AI panel logic', 'node is not installed here');
	harness_finish();
}

$runner = tempnam(sys_get_temp_dir(), 'dai') . '.js';
file_put_contents($runner, "globalThis.window = globalThis;\n"
	. "require(" . json_encode($js_path) . ");\n"
	. "const L = window.MailboxDeviceAi.logic;\n"
	. "const O = 'https://api.fireworks.ai';\n"
	. "const saved = { origin: O, path: '/inference/v1', key: 'k', model: 'm' };\n"
	. "const SITE = { origin: 'http://100.69.133.69:11434', path: '/v1', model: 'qwen3.5:9b-nvfp4', host: '100.69.133.69:11434', operator: true };\n"
	. "const out = {\n"
	. "  hidden: L.sectionState({ origin: O, fortress: false }, saved).state,\n"
	. "  no_origin: L.sectionState({ origin: null, fortress: true }, saved).state,\n"
	. "  needs_model_none: L.sectionState({ origin: O, fortress: true }, null).state,\n"
	. "  needs_model_blank: L.sectionState({ origin: O, fortress: true }, Object.assign({}, saved, { model: ' ' })).state,\n"
	. "  other_origin: L.sectionState({ origin: 'https://other.example', fortress: true }, saved),\n"
	. "  ready: L.sectionState({ origin: O, fortress: true }, saved).state,\n"
	. "  site_none: L.siteOffer({ origin: null, fortress: true }, null),\n"
	. "  site_register: L.siteOffer({ origin: null, site_model: SITE }, null).kind,\n"
	. "  site_prefill: L.siteOffer({ origin: SITE.origin, site_model: SITE }, null).kind,\n"
	. "  site_saved: L.siteOffer({ origin: SITE.origin, site_model: SITE }, { origin: SITE.origin, path: '/v1', key: '', model: 'x' }),\n"
	. "  site_other: L.siteOffer({ origin: O, site_model: SITE }, null),\n"
	. "  url_ok: L.callUrl(O, '/inference/v1'),\n"
	. "  url_noslash: L.callUrl(O, 'v1/'),\n"
	. "  url_empty: L.callUrl('http://localhost:11434', ''),\n"
	. "  url_escape: [L.callUrl(O, '//evil.test/v1'), L.callUrl(O, 'https://evil.test'), L.callUrl(O, '/../x'), L.callUrl(O, '/v1?x=1'), L.callUrl(null, '/v1')],\n"
	. "  local: ['localhost', '127.0.0.1', '[::1]', '192.168.1.5', '10.1.2.3', '172.20.0.1', '100.69.133.69', 'studio.tail1.ts.net', 'box.local'].map(L.isLocalHost),\n"
	. "  public: ['api.fireworks.ai', '8.8.8.8', '172.32.0.1', '100.128.0.1'].map(L.isLocalHost),\n"
	. "  c_prompt: L.classify({ network: true, lna: 'prompt' }, true, 'm').kind,\n"
	. "  c_denied: L.classify({ network: true, lna: 'denied' }, true, 'm').kind,\n"
	. "  c_refused: L.classify({ network: true, lna: 'granted' }, true, 'm').kind,\n"
	. "  c_refused_nolna: L.classify({ network: true, lna: null }, true, 'm').kind,\n"
	. "  c_public_net: L.classify({ network: true, lna: 'prompt' }, false, 'm').kind,\n"
	. "  c_timeout: L.classify({ timeout: true }, false, 'm').kind,\n"
	. "  c_401: L.classify({ status: 401, body: { error: { message: 'The API key you provided is invalid.' } } }, false, 'm'),\n"
	. "  c_403: L.classify({ status: 403, body: {} }, false, 'm').kind,\n"
	. "  c_ctx: L.classify({ status: 400, body: { error: { message: 'request (3630 tokens) exceeds the available context size (2048 tokens), try increasing it' } } }, true, 'm'),\n"
	. "  c_404: L.classify({ status: 404, body: { error: { message: 'Model not found, inaccessible, and/or not deployed' } } }, false, 'm').kind,\n"
	. "  c_404_key: L.classify({ status: 404, body: { error: { message: 'Model not found, inaccessible, and/or not deployed' } }, keyCheck: { status: 401, body: { error: { message: 'The API key you provided is invalid.' } } } }, false, 'm'),\n"
	. "  c_404_keyok: L.classify({ status: 404, body: { error: { message: 'Model not found' } }, keyCheck: { status: 200, body: { data: [] } } }, false, 'm').kind,\n"
	. "  c_500: L.classify({ status: 500, body: { error: 'boom' } }, false, 'm'),\n"
	. "  c_ok: L.classify({ status: 200, body: { model: 'qwen3', usage: { prompt_tokens: 3630 }, choices: [{ message: { content: '{\"score\":1}' } }] } }, true, 'm'),\n"
	. "  c_ok_think: L.classify({ status: 200, body: { choices: [{ message: { content: '<think>hmm</think>{\"score\":1}' } }] } }, true, 'm'),\n"
	. "  c_ok_prose: L.classify({ status: 200, body: { choices: [{ message: { content: 'It looks safe.' } }] } }, true, 'm'),\n"
	. "  c_empty: L.classify({ status: 200, body: { choices: [] } }, true, 'm').kind,\n"
	. "  c_thought: L.classify({ status: 200, body: { choices: [{ message: { content: '', reasoning: 'hmm' }, finish_reason: 'length' }] } }, true, 'm').text,\n"
	. "};\n"
	. "process.stdout.write(JSON.stringify(out));\n");
$raw = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' 2>&1'));
@unlink($runner);
$r = json_decode($raw, true);
check(is_array($r), 'node loaded the panel module and ran its decisions', $raw);
if (!is_array($r)) {
	harness_finish();
}

section('Which state the section is in');
check($r['hidden'] === 'hidden', 'off a Fortress mailbox the section is hidden');
check($r['no_origin'] === 'no_origin', 'with no registered address it sends the member to set one');
check($r['needs_model_none'] === 'needs_model' && $r['needs_model_blank'] === 'needs_model',
	'an address but no model name in this browser asks for the model');
check($r['other_origin']['state'] === 'needs_model' && $r['other_origin']['usable'] === null,
	'a key and model saved for another address are not offered to this one');
check($r['ready'] === 'ready', 'address, and a model saved for it: ready to Test');

section('The site\'s own model, offered');
check($r['site_none'] === null, 'a site with no model to offer offers nothing');
check($r['site_register'] === 'register', 'no address registered: one click registers the site\'s');
check($r['site_prefill'] === 'prefill', 'the site\'s address registered and no model here: the fields start filled');
check($r['site_saved'] === null && $r['site_other'] === null,
	'a model already saved, or another address registered, is left alone');

section('The call stays on the registered address');
check($r['url_ok'] === 'https://api.fireworks.ai/inference/v1/chat/completions', 'origin + path + /chat/completions');
check($r['url_noslash'] === 'https://api.fireworks.ai/v1/chat/completions', 'a path typed without its slash, or with a trailing one, is tidied');
check($r['url_empty'] === 'http://localhost:11434/chat/completions', 'an empty path is allowed');
check($r['url_escape'] === array(null, null, null, null, null),
	'a path that would leave the origin, climb, carry a query, or has no origin to go with is refused');
check(!in_array(false, $r['local'], true), 'this computer, private, tailnet and .local addresses count as the person\'s own');
check(!in_array(true, $r['public'], true), 'public hosts, and the edges just outside the private ranges, do not');

section('What a Test outcome says');
check($r['c_prompt'] === 'browser_asks', 'the browser has not been allowed yet: "your browser asks first"');
check($r['c_denied'] === 'browser_blocked', 'the browser refused: "your browser blocked it"');
check($r['c_refused'] === 'model_refused' && $r['c_refused_nolna'] === 'model_refused',
	'allowed (or no permission to read) but the call failed: the model refused this site');
check($r['c_public_net'] === 'unreachable', 'a public host that cannot be reached is not blamed on the browser permission');
check($r['c_timeout'] === 'unreachable', 'no answer in time: unreachable');
check($r['c_401']['kind'] === 'wrong_key' && strpos($r['c_401']['text'], 'The API key you provided is invalid.') !== false,
	'401: wrong key, with the service\'s own words');
check($r['c_403'] === 'wrong_key', '403 that the page can read: wrong key');
check($r['c_ctx']['kind'] === 'context_small' && strpos($r['c_ctx']['text'], 'exceeds the available context size (2048 tokens)') !== false,
	'an overflow: context too small, the model\'s error verbatim');
check($r['c_404'] === 'model_missing', '404 about a model: no such model');
check($r['c_404_key']['kind'] === 'wrong_key' && strpos($r['c_404_key']['text'], 'The API key you provided is invalid.') !== false,
	'a "missing model" answer whose model list refuses the key is a wrong key (Fireworks answers a bad key that way)');
check($r['c_404_keyok'] === 'model_missing', 'and when the list accepts the key, the model really is missing');
check($r['c_500']['kind'] === 'error' && strpos($r['c_500']['text'], '500') !== false && strpos($r['c_500']['text'], 'boom') !== false,
	'any other error: its status and its words');
check($r['c_ok']['kind'] === 'reachable' && strpos($r['c_ok']['text'], 'qwen3') !== false && strpos($r['c_ok']['text'], '3630 tokens') !== false
	&& strpos($r['c_ok']['text'], 'not the JSON') === false, 'reachable: names the model and the size of the test message');
check($r['c_ok_think']['kind'] === 'reachable' && strpos($r['c_ok_think']['text'], 'not the JSON') === false,
	'a reasoning model\'s <think> block does not count against its JSON');
check($r['c_ok_prose']['kind'] === 'reachable' && strpos($r['c_ok_prose']['text'], 'not the JSON') !== false,
	'reachable but prose: says it may not suit the scan');
check($r['c_empty'] === 'error', 'an answer with nothing in it is an error');
check(strpos((string)$r['c_thought'], 'spent its whole answer reasoning') !== false,
	'an answer spent entirely on reasoning says so, and to choose a model that answers directly');


harness_finish();
