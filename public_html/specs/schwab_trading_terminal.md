# Schwab Trading Terminal — Specification

**Status:** Specced, executor-ready — decisions resolved with the owner
2026-07-16 (enforcement model, watchdog scope, app shell, per-position rule,
ToS-style watchlist). Every remaining choice carries a builder default in
[§ Defaults](#defaults-owner-may-override); nothing blocks a build. Builder
guardrails in [§ Build contract](#part-iv--build-contract-executor-notes).

## Why this exists

Discretionary trading fails on discipline, not information. Every retail
platform makes it *easier* to override your own rules in the moment — the
confirm dialog is one click, the stop is one drag away from "give it room."
This terminal inverts that: the owner defines hard rules while calm, and the
software executes them without negotiation while emotional. The core product
is **programmable buttons behind an un-negotiable rule engine**: one-keystroke
execution for the trades you planned, and an automatic flatten-and-lockout the
moment a hard limit is hit.

Stated honestly: no desktop app can stop its owner from opening thinkorswim
or schwab.com and trading there. This is a **discipline tool, not a jail** —
it removes the fast-execution surface and the in-the-moment decision, which is
where the damage actually happens.

## Goal

A cross-platform desktop trading terminal (Windows 11 first) for a single
Schwab brokerage account: account panel (balances, margin, day P&L),
streaming watchlist with integrated positions, candle + volume chart, and a
user-configurable grid of trading buttons with optional hotkeys — all order
flow gated through a rule engine whose hard rules flatten the account and
lock the app for an enforced timeout when tripped.

## Non-goals (v1)

- Options, futures, crypto — equities and ETFs only (the order builder keeps
  Schwab's `orderLegCollection` shape so options are additive later).
- Automated strategies / signal-driven entries. Buttons fire on human input
  only; the only autonomous actions are rule enforcement.
- Multi-account or multi-broker portfolio views. One linked account per
  profile (adapter boundary keeps a second broker possible — D6).
- Backtesting, scanners, level 2, options chains, news, drawing tools.
- Mobile.

---

# Architecture overview

```
{repo root}/trader/                   ← new top-level dir, like sync/, ios/
  Cargo.toml                          ← Rust workspace
  jt-broker/     Broker trait + Schwab impl (OAuth, REST, streamer) + types
  jt-engine/     account model, rule engine, lockout, order gateway, audit log
  jt-sim/        mock Broker impl, scripted feeds, scenario tests
  jt-app/        Tauri v2 shell: UI (webview), hotkeys, config UI, packaging
```

**Shell: Tauri v2** (D3). The Rust backend owns everything that matters —
auth, tokens, the rule engine, order routing, lockout state — and the webview
is a rendering surface. The webview communicates only via Tauri commands and
events; **the only command that can create an order is
`press_button(button_id)`** — there is no `place_order(order)` command
exposed to JS, so no UI code path reaches the broker without the engine.

**Chart: TradingView `lightweight-charts`** (Apache-2.0), bundled locally
(no CDN). **Frontend: vanilla TS + vite**, no UI framework (house style).

**Local state: SQLite** (via `rusqlite`, WAL) in the per-user app-data dir.

**Token custody:** Schwab app key/secret and OAuth tokens in the OS
credential store via the `keyring` crate (Windows Credential Manager / macOS
Keychain / libsecret). Nothing secret in config files, SQLite, or logs.

## The Broker trait (`jt-broker`)

The seam everything tests through. Shape (builder may refine signatures, not
responsibilities):

```rust
#[async_trait]
trait Broker: Send + Sync {
    async fn account_snapshot(&self) -> Result<AccountSnapshot>; // balances incl. start-of-day, positions, open orders
    async fn place_order(&self, intent: OrderIntent) -> Result<OrderId>;
    async fn replace_order(&self, id: &OrderId, intent: OrderIntent) -> Result<OrderId>;
    async fn cancel_order(&self, id: &OrderId) -> Result<()>;
    async fn todays_fills(&self) -> Result<Vec<Fill>>;           // for trade-count re-derivation
    async fn candles(&self, req: CandleRequest) -> Result<Vec<Candle>>;
    async fn subscribe(&self, req: StreamRequest) -> Result<BroadcastRx<StreamEvent>>;
}
// StreamEvent: Quote{symbol, bid, ask, last, volume, ts}
//            | MinuteBar{symbol, ohlcv, ts}
//            | OrderActivity{order_id, status, fill: Option<Fill>}
//            | StreamStatus{connected: bool}   ← reconnects surface, never hide
```

`OrderIntent` is the engine's vocabulary (market / limit / bracket / close),
translated to Schwab JSON inside the adapter only. Two implementations:
`SchwabBroker` and `SimBroker` (§II.5).

## Schwab API ground truth (verify, don't invent)

Schwab **Trader API — Individual** (developer.schwab.com; free; request the
key at the *start* of the build — approval takes days and only Phase 1's
live half and Phase 5 need it). Known shape, with gotchas the builder must
confirm against the official docs and captured live responses before
hardcoding (**the `schwabdev` Python library on GitHub is a maintained
working reference for every flow below**):

- **OAuth:** authorization-code flow in the system browser. Callback URL
  must be HTTPS — register `https://127.0.0.1:8443` on the developer-portal
  app and listen locally with a self-signed cert (browser warning on that
  one localhost redirect is expected; schwabdev does the same). Token
  endpoint uses HTTP Basic auth (app key:secret). Access token ~30 min
  (auto-refresh); **refresh token hard-expires at 7 days** — the app shows
  a countdown warning from T-24h and offers one-click re-auth.
- **Account-number hash gotcha:** trading endpoints take a *hashed* account
  number, not the real one. `GET /trader/v1/accounts/accountNumbers` returns
  the mapping; resolve once at link time and store the hash.
- **Balances:** `GET /trader/v1/accounts/{hash}?fields=positions` →
  `securitiesAccount` with `initialBalances` (start-of-day; the day-P&L
  anchor, §I.1), `currentBalances` (liquidationValue, cashBalance,
  buyingPower, margin fields), and `positions[]` (quantity,
  averagePrice, instrument.symbol).
- **Orders:** `POST /trader/v1/accounts/{hash}/orders`. Strategy types
  `SINGLE`, `OCO`, `TRIGGER` (bracket = TRIGGER parent with OCO children —
  server-side, survives the app dying). Capture the exact JSON of each
  shape as fixtures in Phase 1.
- **Fills today:** `GET /trader/v1/accounts/{hash}/orders` filtered to
  today (or `/transactions`) — powers trade-count re-derivation.
- **Price history:** `GET /marketdata/v1/pricehistory` (periodType /
  frequencyType / frequency / startDate / endDate epoch-ms,
  `needExtendedHoursData=false` for v1).
- **Streamer:** WebSocket; connection parameters come from
  `GET /trader/v1/userPreference` (streamer URL + customer/client ids);
  login frame carries the access token. Services used: level-one equity
  quotes (watchlist + position marks), chart-equity minute bars (live
  candle), account activity (order status/fills as push). Field lists are
  numeric-keyed — map them in one place in the adapter with named
  constants, verified from docs + schwabdev.

Rate limits are far above human-driven order flow; on any 429/`Retry-After`
back off and surface a status-bar warning.

---

# Part I — The rule engine (`jt-engine`)

## I.1 Account model

One continuously updated model: cash, buying power, positions (marked at
streaming quotes), open orders, current equity (liquidation value = cash +
marked positions), day P&L, and today's fill count. Inputs: the account-
activity stream (fast hints) + level-one quotes for held symbols, reconciled
against a REST `account_snapshot` on start and every 60 s — **REST is truth
on disagreement** (log the divergence).

**Day P&L anchors on the broker, not the app:** Schwab's `initialBalances`
*is* the start-of-day equity, so day P&L = current equity −
`initialBalances.liquidationValue`. No local snapshot to get wrong when the
app first launches mid-day, and nothing local to delete (§I.4). Trading day
= ET calendar date; deposits/withdrawals intraday are a documented known
simplification (personal tool; rare event).

## I.2 Rule inventory

Full vocabulary, decided up front. Dollar amounts; percent-of-equity forms
convert to dollars against `initialBalances` at first evaluation each day
and freeze for the day.

**Hard rules** — monitored continuously; tripping executes §I.3:

| Rule | Trips when | Semantics pinned | v1 |
|---|---|---|---|
| Max daily loss | day P&L ≤ −limit | includes unrealized + fees; anything done outside the app counts (broker-anchored) | ✅ |
| Equity target | current equity ≥ target | lock in the day; default lockout = rest of day | ✅ |
| Max daily trade count | order fills today > limit | one *order* fully/partially filled = 1 trade (partial fills of one order are 1); enforcement orders don't count | ✅ |
| Max open loss per position | position unrealized P&L ≤ −limit | position-scoped enforcement (§I.3 exception) | ✅ |
| Trading window | position open / order live outside configured hours | — | later (default off) |

**Gate rules** — checked synchronously at button press; violating orders are
refused with the reason shown, nothing else happens:

| Rule | Refuses | v1 |
|---|---|---|
| Max position size (shares or notional, per symbol; default applies to all) | any order whose fill would exceed it (resting orders count toward exposure) | ✅ |
| Max single-order size (fat-finger, notional) | any order above the cap | ✅ |
| Entries-only-with-stop | any position-opening button not configured as a bracket (toggleable; default on) | ✅ |
| No entries while day P&L below soft threshold | entry orders only — exits always allowed | later |

## I.3 Enforcement sequence (hard-rule trip)

Owner-decided: **flatten + app lockout** (D1). A state machine, persisted at
every transition: `Normal → Enforcing → Locked → Normal`.

1. **Enter `Enforcing`** (buttons/hotkeys already refused from here on).
2. **Cancel** all open orders, bracket children included.
3. **Flatten** every position with market orders. Await fills on the
   account stream; anything unconfirmed after 10 s → re-check via REST,
   re-submit remainders (partial fills included). Market closed / order
   rejected → surface loudly in the UI, retry every 60 s until flat.
   Flatten never silently gives up; `Enforcing` cannot be exited except to
   `Locked`.
4. **Enter `Locked`:** full-screen overlay — which rule, the numbers, a
   countdown. Default timeout 30 min per rule (configurable); equity-target
   default: rest of trading day. Account data, watchlist, chart stay live —
   watch, not touch. Unlock = countdown reaching zero. **No override
   control exists.** Not hidden, not confirm-three-times. None.
5. **Audit log** rows for the trip, the numbers, every enforcement order.

**Scope exception — per-position rule:** max-open-loss-per-position enforces
against its position only: cancel that symbol's orders, market-close that
position, log. No account flatten, no lockout — a catastrophic backstop
stop, not a day-ender. Account-level rules always run the full sequence.

## I.4 Making enforcement stick (honest tamper model)

Watchdog is **app-only** (D2): rules evaluate while the app runs. Within
that scope, tampering is made pointless by **re-deriving trigger state from
Schwab rather than trusting local files**:

- On every start: day P&L from `initialBalances` vs current, fill count from
  today's orders. A tripped daily-loss / equity-target / trade-count
  condition therefore **re-trips immediately on restart** — killing the app
  or deleting the SQLite file clears nothing, because the lockout is a
  consequence of account state. The persisted lockout row exists only to
  carry the countdown across restarts (its absence is not trusted: trip
  conditions are re-evaluated first thing, before the UI enables anything).
- **Rule changes are asymmetric (D4):** tightening applies immediately;
  loosening or deleting takes effect at the next trading day, never
  intraday (implemented as `effective_date` on the new revision; the engine
  always evaluates the strictest of today's-effective revisions).
- Outside the model, stated plainly in the README: Schwab's own apps keep
  working. The tool makes the disciplined path the fast path.

## I.5 Evaluation cadence

Hard rules evaluate on every account event (fill, order status, quote tick
on a held symbol) and on a 1 s timer floor. Evaluation is pure (state in →
verdict out) and shares a lock with order gating: a button press during a
trip-in-flight is refused, and two simultaneous trips enforce once (state
machine, not flags).

---

# Part II — The terminal UI (`jt-app`)

Single window, four regions (splitter-draggable, layout persisted): account
panel + rule strip across the top; watchlist left; chart center; button grid
bottom (spanning). Status bar: stream health, auth countdown (< 24 h),
sim/live mode.

## II.1 Account panel + rule status strip

Equity, cash, buying power, margin balance/requirement, day P&L. The rule
strip shows live distance to each armed hard rule ("−$180 / −$500 daily
loss", "trades 4/10"), amber ≥ 80 % consumed. Rules are ambient all day,
not a surprise at trip time.

## II.2 Watchlist (positions integrated, thinkorswim-style)

Streaming last/bid/ask, net change, volume; add/remove/re-order; click
selects the chart symbol; persisted. **Any held symbol appears
automatically, pinned in a positions band at the top**, carrying position
columns — quantity, average price, open P&L, day P&L — updating on every
quote tick. Closing a position drops the symbol back to (or out of) the
plain list; a held symbol never needs adding by hand.

## II.3 Chart

Candles + volume histogram for the selected symbol; timeframes 1m / 5m /
15m / 1D. Backfill via price-history REST on symbol/timeframe change; live
forming candle from streamed minute bars, aggregated locally for 5m/15m
(bucket by ET wall clock; 1D updates the daily bar from quotes). On stream
reconnect, re-fetch backfill to heal the gap before resuming live updates.
Regular session only in v1. No drawing tools, no indicators (defaults list
carries the first candidates).

## II.4 Programmable buttons + hotkeys

A button = named action + parameters + optional hotkey + optional per-button
confirm. **No scripting language, deliberately** — buttons are data
interpreted by the Rust order builder; a closed vocabulary is what keeps
every press one gate-checked order intent (no loops, no conditions, no path
around the engine). Full action vocabulary (all v1, one order builder):

| Action | Parameters |
|---|---|
| Buy / Sell market | symbol (fixed or chart-selected); qty (shares / dollars / % of buying power) |
| Buy / Sell limit | + limit price: offset from bid/ask/mark, or absolute |
| Bracket entry | entry (market or limit) + stop offset + optional target offset → Schwab TRIGGER+OCO |
| Close position | symbol; percentage (25/50/100) |
| Flatten all | cancel all orders + close all positions (manual §I.3 steps 2–3; never locks) |
| Cancel all orders | optional per-symbol |

Stored shape (SQLite `buttons.config` JSON):
`{action, symbol_mode: "fixed"|"chart", symbol?, qty: {kind: "shares"|"dollars"|"pct_bp", value}, limit?: {ref: "bid"|"ask"|"mark"|"abs", offset}, stop_offset?, target_offset?, close_pct?, confirm: bool, hotkey?, global_hotkey: bool, color?, position: {row, col}}`.
Created/edited in a dialog (no JSON editing), drag re-order, color labels.
Sell-side of a bracket mirrors symmetrically (short entry, stop above).

**Hotkeys:** press-to-record in the editor; active while the window is
focused. Optional **global** (Tauri global-shortcut plugin) per button —
intended for defensive buttons (Flatten all, Cancel all); making an *entry*
hotkey global requires ticking a clearly worded checkbox. All hotkeys dead
during `Enforcing`/`Locked` and while any confirm dialog is open. Conflicts
refused at record time.

**Press path (the only order path):** webview sends
`press_button(button_id)` → engine loads config → resolves symbol/prices
from live state → builds `OrderIntent` → gate rules → lockout check →
optional confirm (native dialog) → `Broker::place_order` → audit log.

## II.5 Simulation mode

`SimBroker` is reachable from the real UI, not just tests: paper account
(configurable starting cash) filled against live streamed quotes when
Schwab market-data auth exists, or **replayed JSONL sessions** (recorded
from the live stream; several ship in-repo) when it doesn't. Fill model:
market fills at opposing quote (ask for buys) up to displayed size, limit
fills when the quote crosses, brackets honored locally. Mode is loud —
colored window border + persistent badge; sim and live never share an
account model or database file. Schwab has no paper-trading API; this is
the rehearsal surface for buttons, hotkeys, and rule trips.

---

# Part III — Persistence (SQLite)

One database (`trader.db`), plus `sim.db` for sim mode. Tables (builder may
add columns, not drop):

- `settings` — key/value (layout, watchlist order, chart prefs, account
  hash, sim config).
- `rules` — `rule_id, kind, params_json, enabled, created_at,
  effective_date, superseded_by` — append-only revisions; loosening writes
  a revision with tomorrow's `effective_date` (§I.4).
- `buttons` — `button_id, config_json (§II.4), created_at, updated_at`.
- `watchlist` — `symbol, sort_order`.
- `lockouts` — `lockout_id, rule_id, tripped_at, until, context_json`
  (carries countdown across restarts; never trusted as the *only* trip
  signal — §I.4).
- `audit_log` — append-only: `ts, kind, detail_json` for every button
  press, gate refusal, order, fill, rule evaluation trip, enforcement
  action, auth event. No secrets ever.

---

# Verification

The rule engine is the product claim, so it gets the harness treatment.

**`jt-sim` scenario tests** (`cargo test -p jt-sim`; scripted quote/fill
feeds + controlled clock; no wall-clock, no network). Required scenarios:
every hard-rule trip; every gate refusal; partial-fill flatten with
re-submit; rejected flatten order (market closed) retry-until-flat;
restart during `Locked` (countdown persists); restart after loss with DB
**deleted** (re-trips from broker truth); rule-loosening deferred to next
day / tightening immediate; day rollover resets; REST-vs-stream
disagreement (REST wins); stream drop mid-enforcement; two rules tripping
in the same tick (one enforcement); button press during `Enforcing`
(refused); per-position trip closes only its position; sim fill model
(market, limit cross, bracket). Two invariants asserted in every scenario:
**no `OrderIntent` reaches the Broker without passing the gate**, and **a
tripped hard rule always ends in flat+locked or a loudly surfaced,
still-retrying failure** — never a silent partial state.

**Broker contract tests** (`cargo test -p jt-broker`): serializers/parsers
against fixtures — order JSON for every §II.4 action (incl. both bracket
directions), account/positions/initialBalances parse, streamer frame
decode, token refresh, account-hash resolution. Fixtures start from the
API docs, get replaced by captured live responses in Phase 1.

**Live gate** (owner-run checklist, 1-share orders): auth ceremony;
forced 7-day re-auth path; bracket entry lands as TRIGGER+OCO at Schwab;
one forced daily-loss trip with real flatten; lockout survives app
restart.

**Repo gate:** `tests/functional/trader/trader_sim_gate.sh` — tier `safe`,
env `any`, needs `[rust]`, skip-if-no-toolchain (same pattern as
`sync_sim_gate.sh`) — runs the jt-sim + jt-broker suites.

---

# Phases (each independently useful, each with a done-bar)

- **Phase 1 — broker plumbing.** OAuth ceremony + keyring custody +
  auto-refresh; account/positions/balances; quotes, price history,
  streamer subscribe; CLI proof (`jt account`, `jt quote SPY`, `jt stream
  SPY`). *Done when:* CLI shows live account + streaming quotes from the
  real key; contract tests green on captured fixtures. (Fixture-first: all
  serializer work proceeds keyless while approval is pending.)
- **Phase 2 — engine + sim.** Account model, full v1 rule set, enforcement
  state machine, lockout, audit log, `SimBroker` + replay. *Done when:*
  every §Verification scenario passes; the gate script is green.
- **Phase 3 — terminal shell (read-only).** Tauri app: account panel +
  rule strip, watchlist with integrated positions, chart with live edge.
  *Done when:* a full market session runs against the live account with
  correct P&L, healing chart, no stalls — a daily-usable read-only
  terminal.
- **Phase 4 — buttons + hotkeys, sim-wired.** Grid, editor, order builder,
  gate integration, per-button + global hotkeys, sim mode in the UI.
  *Done when:* every action type and a forced rule trip is rehearsed
  end-to-end in sim from the real UI.
- **Phase 5 — live enforcement + packaging.** Order routing to the real
  account; owner-run live gate; Windows installer (NSIS via Tauri
  bundler). *Done when:* the live-gate checklist is signed off.
  macOS/Linux builds ride the same codebase when wanted.

---

# Part IV — Build contract (executor notes)

Rules for whoever (whatever) builds this:

1. **Verify, don't invent, Schwab specifics.** Endpoint paths, JSON field
   names, streamer field numbers, OAuth quirks: confirm against the
   official docs and the `schwabdev` reference implementation, then freeze
   as fixtures. Never hardcode a field name that hasn't been seen in a doc
   or a captured response. When docs and reality disagree, reality wins
   and the fixture records it.
2. **Request the developer key on day one.** Approval latency is days;
   Phases 1 (fixture half) and 2 proceed keyless meanwhile.
3. **The engine owns all order flow.** If a change would let the webview,
   a test hook, or a CLI flag reach `Broker::place_order` without the gate
   + lockout check, it is wrong — restructure instead. The two
   §Verification invariants are non-negotiable and must stay asserted in
   every scenario.
4. **No scripting surface on buttons** — expressiveness gaps are solved by
   adding a parameter or verb to the vocabulary (and a scenario test), not
   by evaluating user input.
5. **Secrets hygiene:** tokens/keys only in the OS credential store; never
   in SQLite, config, logs, fixtures, or test snapshots. Captured fixtures
   must be scrubbed of account numbers (use the hash) and tokens.
6. **Simulation-first development:** every engine behavior lands with its
   sim scenario in the same change; UI work happens in sim mode by
   default. Live-account testing is 1-share sized and owner-supervised.
7. **Money-math discipline:** prices and P&L in `rust_decimal` (or integer
   cents), never `f64`, from the wire inward.
8. **All timestamps UTC internally; ET only at display and day-boundary
   logic** (single `market_calendar` module owns "trading day", 09:30–16:00
   ET, weekend awareness; holidays deliberately not modeled in v1 — a
   holiday is just a day where nothing ticks).

# Decisions (resolved)

- **D1 — Flatten + app lockout**, no override path; equity-target defaults
  to rest-of-day. (Owner, 2026-07-16.)
- **D2 — App-only watchdog**; lockout survives restarts via re-derivation
  from broker truth (`initialBalances`, today's fills), not a trusted local
  flag. (Owner, 2026-07-16.)
- **D3 — Tauri v2 / Rust core**; the webview can only trade through
  `press_button`. (Owner, 2026-07-16.)
- **D4 — Rule-change asymmetry:** tighten immediately, loosen next trading
  day (append-only rule revisions with `effective_date`).
- **D5 — Brackets are server-side Schwab orders** (TRIGGER+OCO); protective
  stops outlive the app.
- **D6 — Broker behind a trait**; Schwab and sim are the two v1
  implementations (house rule: build the abstraction).
- **D7 — Sim mode ships in the UI** — the rehearsal surface, same mock the
  tests run.
- **D8 — Positions integrate into the watchlist** thinkorswim-style, pinned
  band with qty/avg/open-P&L/day-P&L; per-position max-loss rule is v1 with
  position-scoped enforcement. (Owner, 2026-07-16.)
- **D9 — Day P&L anchors on Schwab `initialBalances`**, not an app-side
  snapshot — correct on mid-day first launch and nothing local to tamper
  with.

# Defaults (owner may override)

Builder proceeds with these; each is a one-line change later:

- App name **"Trader Terminal"**, binary/CLI **`jt`**, bundle id
  `com.joinery.trader`.
- Trading-window hard rule: **not in v1** (inventoried, default off).
- Chart extras: **none in v1**; first candidates when asked are VWAP and
  prior-day high/low lines.
- Max-daily-loss lockout default: **30 minutes** (equity target:
  rest-of-day). Both per-rule configurable.
- Entries-only-with-stop gate: **on** by default.
- Sim starting cash: $100,000.

# Out of scope (deliberate)

Options/futures/crypto; automation and signals; multi-account; multi-broker
UI (adapter keeps it possible); backtesting; level 2; news; extended-hours
data; holiday calendar; mobile; any pretense of preventing trading through
Schwab's own surfaces.
