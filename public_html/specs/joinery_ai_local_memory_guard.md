# Joinery AI — Local provider memory guard

**Status:** Proposed
**Plugin:** `joinery_ai`
**Touches:** `OpenAiCompatibleProvider`, `LlmProviderFactory`, `settings_form.php`,
`plugin.json` (new settings), `LlmProviderException` (one new classify code) —
plus a new small sidecar service on whatever host runs the local model
(currently the Mac Studio, `100.69.133.69`; the incidents below occurred on
the earlier Mac mini host, which now serves as the iOS/Android build box).
**Pairs with:** the `AgentLoop::LOCAL_PER_CALL_MAX_TOKENS` cap already added
for the same failure class.

## Goal

When the box running the local model is low on memory, or has Xcode/the
Android emulator open, a chat reply or recipe run should fail fast with a clear
error — not sit for minutes producing nothing before the caller gives up or
times out.

In plain terms: today, if the local model's host is under memory pressure, or
another heavy dev tool is running on the same box, generation just gets very
slow (sometimes pathologically so — a small model can end up looping without
ever producing a stop token, and the 9B model has been observed to effectively
hang whenever Xcode or the Android emulator is open, regardless of free memory).
The user sees a spinner for minutes with no way to tell "the host is busy" from
"this is just a big reply coming." This spec adds two fast, explicit checks
before dispatch: a general low-memory floor (advisory-strength — see below),
and a hard, unconditional block on the 9B model specifically whenever Xcode or
Android dev tooling is detected running, since that combination has reliably
caused a hang every time it's been observed, independent of how much free RAM
is left.

## Why this happened

Traced directly from a real incident (on the then-current mac mini host): a
chat turn to `qwen3:4b` (local, via Ollama) hung for 2+ minutes. The Ollama log showed the model
actively generating the whole time — not stuck, just decoding into a loop,
n_decoded still climbing past 4,800 tokens with repeated `slot context shift`
lines. Two contributing causes were found and fixed separately:

1. Ollama's context window defaulted to 4,096 tokens, too small for this app's
   system prompt + tool schemas + history — fixed by setting
   `OLLAMA_CONTEXT_LENGTH` on the server.
2. `AgentLoop` had no local-specific output-token cap, which could exceed the
   context on its own — fixed by adding `AgentLoop::LOCAL_PER_CALL_MAX_TOKENS`
   for local models specifically (initially 4,000; since raised to 16,000
   after the server context window was raised to 24k on the Studio host).

Separately, a follow-up comparison of `qwen3:4b` vs `qwen3.5:9b-nvfp4` on the
same prompt found the 9B model taking 2+ minutes to not even finish, traced to
Xcode (with GPU-debugging tools open) running concurrently on the mini and
competing for the same Metal GPU the MLX runner needs. At the time, free system
RAM had dropped to 277 MB (from a healthy ~8.6 GB with Xcode closed). The 4B
model (llama.cpp/Metal, not MLX) was unaffected in the same window. By this
point it's a reliably reproduced pattern, not a one-off: Xcode or Android dev
tooling open on the model host means the 9B model hangs — this spec treats
that as a hard rule to enforce, not just a correlated risk to warn about.
(The model host has since moved to the Mac Studio, which normally runs no dev
tooling — the mini is now the build box — so the GPU-contention case is rarer
by separation of duties. The guard still enforces it: nothing prevents opening
Xcode on the Studio, and the memory floor applies regardless of host.)

Neither of the two context/token-budget fixes catches *this* failure mode — a
host that's generally under memory/resource pressure, or actively contending
for the GPU, for reasons unrelated to context size or output length. Ollama
itself has no setting for either: its scheduler only checks whether a model's
own footprint fits before loading, never whether headroom is left over for
anything else running on the box, and it does not monitor memory or GPU
contention once a generation is underway.

## Design

### A minimal status sidecar on the model host

Ollama's API has no endpoint that reports host memory, so a tiny standalone
HTTP service is added alongside it — same deployment shape as Ollama's own
LaunchAgent, reachable over the same Tailscale path, no new attack surface
beyond what already exists for Ollama itself (no auth, private network only).

- One file, stdlib only (no pip/psutil dependency to install and keep patched)
  — shells out to `vm_stat`, `sysctl vm.swapusage`, and `ps aux`, parses the
  numbers/process names needed, and serves them as JSON on a single route.
- `GET /status` → `{"free_mb": 8593, "total_mb": 16384, "swap_used_mb": 401,
  "gpu_contenders": []}` — `gpu_contenders` is a list of matched labels (e.g.
  `["Xcode"]`, `["Android emulator"]`, both, or empty when clear). Matching is
  a fixed table of process-name substrings, checked against `ps aux`:
  `Xcode.app/Contents/MacOS/Xcode` → `Xcode`; `qemu-system-aarch64` → `Android
  emulator`. (A running Gradle daemon alone is not included — it's the
  emulator's GPU/Metal usage that actually contends with Ollama, per the
  incident; a Gradle build with no emulator running hasn't been observed to
  cause this.)
- Managed by its own LaunchAgent (`RunAtLoad` + `KeepAlive`, logging to
  `/tmp/memguard.log`/`.err`), independent of the Ollama LaunchAgent — one
  going down doesn't take the other with it.
- Mac-only (`vm_stat`/`sysctl`/this `ps` matching are macOS-specific) —
  acceptable since the current and only local-model host is the Mac Studio. A
  Linux host would need the memory equivalent from `/proc/meminfo` and its own
  process-matching table; out of scope until a second local host exists.

### Provider-side checks

`OpenAiCompatibleProvider` gains three optional constructor parameters:
`guard_url`, `guard_min_free_mb`, and `guard_gpu_gated_models` (an array),
defaulting to `''` / `0` / `[]` (disabled) so `FireworksProvider`'s existing
`parent::__construct(...)` call is unaffected — the guard is local-only by
construction, not by a runtime branch.

At the top of `createMessageStreamed()`, before building the request: if
`guard_url` is set, `GET` it with a short fixed timeout (2s connect, 2s read —
hardcoded, not a setting; this call must never itself become the slow part).
Outcomes, checked in order once the guard responds:

- **`gpu_contenders` is non-empty AND the requested model is in
  `guard_gpu_gated_models`** → throw `LlmProviderException` immediately, with a
  message naming what was detected (e.g. "qwen3.5:9b-nvfp4 is blocked while
  Xcode is running on the local host — they compete for the same GPU and this
  reliably hangs. Close Xcode, or switch to qwen3:4b or a cloud model."). This
  check is **unconditional on free memory** — it fires regardless of how much
  RAM is free, because the observed failure is GPU scheduling contention, not
  memory exhaustion. Only the models named in `guard_gpu_gated_models` are
  gated; models not in that list (e.g. `qwen3:4b`) are never blocked by this
  check, since only the MLX/GPU-heavy model has actually been observed to hang.
- **Else, `free_mb` below `guard_min_free_mb`** → throw `LlmProviderException`,
  message worded to include "overloaded" so `LlmProviderException::classify()`
  buckets it as `api_server_error` with no change needed to the classifier for
  this path.
- **Else** → proceed normally.
- **Guard unreachable / times out / malformed response** → proceed normally
  (fail-open on *both* checks). The guard is advisory infrastructure; it must
  never make local inference depend on a second service's uptime — a guard
  outage should degrade to today's behavior (no block, no warning), not add a
  new way for chat to break. This means the hard GPU block is only as reliable
  as the sidecar's uptime, same trade-off as the memory floor.

### New `LlmProviderException` code

The GPU-contention block gets its own `classify()` code,
`local_gpu_contention`, recognized by a distinct marker phrase in the message
(e.g. "GPU contention"), with a `friendlyMessage()` that surfaces the real
reason and the suggested fix directly — this is the one case worth breaking
from the generic "try again in a moment" wording, since the fix is specific and
actionable (close Xcode/the emulator, or pick a different model) rather than
"wait and retry," which would just fail again immediately.

### New settings

- `joinery_ai_local_memory_guard_url` (default `''`) — base URL of the sidecar,
  e.g. `http://100.69.133.69:8787`. Blank disables both checks entirely; every
  existing local deployment keeps working with zero config changes.
- `joinery_ai_local_min_free_mb` (default `1024`) — minimum free host RAM
  required to dispatch. 1 GB is the floor observed to correlate with the actual
  incidents above (277 MB free during the Xcode contention case; the
  emulator/Gradle case in prior notes saw similarly tight free RAM).
- `joinery_ai_local_gpu_gated_models` (default `''`) — comma-separated model
  ids to hard-block whenever `gpu_contenders` is non-empty, regardless of free
  memory (e.g. `qwen3.5:9b-nvfp4`). Follows the same comma-separated
  convention as `joinery_ai_local_model`. Blank means no model is gated by this
  check — an admin opts specific models in once they've observed the same
  hang, rather than the platform guessing which models are GPU-heavy.

All three follow the existing `joinery_ai_local_*` settings pattern in
`settings_form.php` (shown/hidden by the same `local` provider
`visibility_rules` group as `joinery_ai_local_base_url` etc.) and are declared
with factory defaults in `plugin.json`, seeded automatically — no migration.

## What does NOT change

- Ollama's own admission control, context sizing, or the per-call token caps —
  this spec adds one preflight check in front of the existing dispatch path,
  nothing about the request/response translation.
- `AnthropicProvider` / cloud dispatch generally — the guard is local-only.
- The provider interface's public contract — the two new constructor params are
  additive and optional.

## Out of scope

- **Real GPU-utilization measurement.** Detection here is process-presence
  matching (is Xcode/the emulator running at all), not an actual read of Metal
  GPU load. This is a deliberate simplification: the observed pattern is "the
  process being open at all reliably causes the hang," so presence is a
  sufficient signal without needing real GPU telemetry. If a future case shows
  the process can be open *without* causing a hang, this will need revisiting.
- **Automatic remediation** (killing Xcode/the emulator, queuing/retrying the
  request, auto-switching the conversation to a different model). The guard
  only fails fast with a clear, actionable error; a human decides what to do
  about it, same as today.
- **Cross-platform sidecar** (Linux `/proc/meminfo` + process-matching
  equivalent) until a second local-model host exists on non-macOS.
- **Making the guard call itself configurable/tunable** (timeout, retry) —
  hardcoded short timeout is deliberate; a slow guard defeats its own purpose.
- **Auto-detecting which models are GPU-heavy.** `guard_gpu_gated_models` is
  admin-populated, not inferred from the model id or engine (MLX vs GGUF) —
  keeps the sidecar/provider logic simple and avoids guessing wrong about a
  model added later.

## Implementation outline

1. Write the sidecar script + its LaunchAgent on the model host (Mac Studio); verify
   `GET /status` returns sane numbers under healthy conditions, under
   memory-tight conditions, and with Xcode/the Android emulator open
   (reproduce both incidents to confirm each check actually catches its case).
2. Add `guard_url`/`guard_min_free_mb`/`guard_gpu_gated_models` constructor
   params to `OpenAiCompatibleProvider`, wired only from
   `LlmProviderFactory::local()` (the last reads `joinery_ai_local_gpu_gated_models`
   as a comma-separated list, same parsing style as `modelIds()`).
3. Add the preflight checks at the top of `createMessageStreamed()` — GPU-gate
   check first (unconditional on memory), then the memory floor — fail-open on
   any guard-reachability problem for both.
4. Add the `local_gpu_contention` code to `LlmProviderException::classify()` /
   `friendlyMessage()`.
5. Add `joinery_ai_local_memory_guard_url`, `joinery_ai_local_min_free_mb`, and
   `joinery_ai_local_gpu_gated_models` to `plugin.json` settings and
   `settings_form.php` (grouped with the other `local` fields under the
   existing `visibility_rules`).
6. `php -l` + `validate_php_file.php` on every modified PHP file; bump the
   plugin version in `plugin.json`.
7. Manually verify: stop the sidecar (guard unreachable) and confirm chat still
   works; lower the memory threshold below current free RAM and confirm chat
   fails fast with the expected message; open Xcode with `qwen3.5:9b-nvfp4` in
   `joinery_ai_local_gpu_gated_models` and confirm the GPU-contention block
   fires (and that `qwen3:4b` is unaffected); restore normal settings.

## Docs

On implementation, update `plugins/joinery_ai/docs/overview.md` § "LLM
providers" — add both settings to the "Local settings" list alongside
`joinery_ai_local_base_url` etc., and note the fail-open behavior, the
GPU-contention hard block and why it's unconditional on free memory, and the
two `classify()` codes involved (`api_server_error` for the memory floor,
`local_gpu_contention` for the GPU block).
