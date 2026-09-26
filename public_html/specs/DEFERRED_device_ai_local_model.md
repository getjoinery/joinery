# DEFERRED — A local model found and used without setup

**Status: DEFERRED (owner, 2026-09-25) — design record, not scheduled.**
Split out of `specs/fortress_mail_device_ai.md` after its WP0. That spec
builds a generic API call (the person's endpoint, URL and key, entered by
them). Calling a model on the person's own computer stays possible, but it is
theirs to configure, with the steps in the docs. This spec is the way back to
"it just works when a model is running".

## What this is, in plain terms

A person running Ollama on their laptop would like the mailbox to find it and
use it. Today it cannot, without that person running a command. Two things
stand in the way, and only one of them is ours:

- **The browser asks first.** Chrome (Local Network Access) asks the person
  once, per site, before a public https page may reach their computer or
  their network. That is one click, not configuration, and it is acceptable.
- **The model program refuses the site.** A program on the person's computer
  decides whether to answer a website. Ollama answers `403` to any site not
  named in its `OLLAMA_ORIGINS` environment variable, which means a command
  and an Ollama restart. The site's own CSP cannot grant this; it only
  decides where our page may send requests. The refusal is deliberate: it
  stops any website from quietly using a person's local model.

## Facts measured 2026-09-25 (WP0 of the parent spec)

On dev, Playwright Chromium 148, with the site's CSP enforced and widened for
the probe targets on a temporary page:

- Loopback without the permission: refused ("Permission was denied … the
  `loopback` address space"). Headless Chrome cannot show the prompt.
- Loopback with the permission, and the model allowing the site: works.
  `/api/chat` answered.
- Loopback with the permission, stock Ollama: refused by Ollama (no
  `Access-Control-Allow-Origin`).
- Tailnet IP over plain http (100.64/10): Chrome classes it as the `local`
  address space. After the permission, mixed content only warns, and the
  request reached Ollama.
- `navigator.permissions.query({name: 'local-network-access'})` works in
  Chrome 148, so a page can tell "allow the prompt" or "blocked by the browser"
  apart from "the model refused this site".
- Ollama 0.34 never sends `Access-Control-Allow-Private-Network`. It refuses
  a prompt larger than its context with `400 exceed_context_size_error`
  rather than truncating. `/v1/chat/completions` ignores `options.num_ctx`;
  native `/api/chat` honours it. The security scan's prompt plus a full
  digest measured 3,630 tokens.

Not measured: Safari, Firefox, real (headed) Chrome, and https through
`tailscale serve`.

## Can the page at least detect a running model?

Probably yes, but it cannot use one. After the person allows Chrome's prompt,
a `fetch(..., {mode: 'no-cors'})` to `http://localhost:11434/` resolves when
something is listening and rejects when nothing is. That is enough to say "we
found Ollama; to let this site use it, allow it in Ollama's settings", but not
enough to read an answer. Unmeasured; check it before relying on it. The probe
itself triggers Chrome's prompt, so it should run only when the person opens
the AI settings, never on page load.

## Ways to "no setup", to evaluate when picked up

1. **Local apps that answer websites by default or with a checkbox.** Check
   LM Studio (believed to have a CORS toggle in its server settings),
   llama.cpp's `llama-server` (believed to answer any origin by default),
   Jan and GPT4All, and whether the Ollama desktop app has an origins setting.
   If a common app answers with a checkbox, the local kind needs no command.
2. **A model inside the browser tab (WebGPU).** Nothing to install and
   nothing leaves the device. The costs: a download of a few GB, a capable
   GPU, and it runs only while the tab is open. The open question is whether
   a small in-tab model judges the security scan well enough; it is
   corpus-validated on larger models.
3. **A Joinery browser extension.** An extension can reach local programs
   with its own permissions. One-click install for the person; a real build
   and maintenance cost for us, per browser. Whether Ollama accepts an
   extension's origin without configuration is unverified.

## Scope when built

- Detection in the AI settings (the no-cors probe above) with the fix line
  for the program it found.
- A first-class local kind for whichever of 1–3 needs no command, with Test
  naming which gate stopped it (browser permission, model refused the site,
  model too old, context too small).
- Parent-spec rules that carry over unchanged: loopback-only hosts for
  anything stored in browser storage, the CSP naming only confirmed
  destinations, `/api/chat` with `num_ctx`.

## Open questions

- **Q1.** Which of 1–3 above, or a mix (an app checkbox where one exists, an
  in-tab model otherwise)?
- **Q2.** Does the `machine` kind (a model on another computer of the
  person's) need https, given that Chrome allows plain http to a tailnet
  address after its prompt? It depends on Safari and Firefox, which are
  unmeasured.
