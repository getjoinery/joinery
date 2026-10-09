# Schwab Trading Terminal — Specification

**Status:** Specced — decisions resolved with the owner 2026-07-16
(enforcement model, watchdog scope, app shell, per-position rule, ToS-style
watchlist). Schwab key approved 2026-10-09; pre-build review the same day
fixed after-hours flatten, oversell on re-submit, auth expiry, transfers in
day P&L, the profit-target definition, Schwab order-id/fill-query details
and short-side instructions, made max daily loss two-step (D10) and the
trade limit a gate on new entries (D11). A second review (reviewer2,
R1–R33) the same evening hardened enforcement against overselling
(unknown order ids, async cancels, restarts mid-enforcement), the day
roll, stuck orders, halts, stale data and clock tampering. R14 became
rules frozen per day (§I.6, D14): each day's rules live in the OS
credential store, so deleting or editing the app's data cannot loosen
today; R20 became D15 (an exit button never flips
a position). No open owner decisions. Every other choice carries a
builder default in
[§ Defaults](#defaults-owner-may-override). Builder guardrails in
[§ Build contract](#part-iv--build-contract-executor-notes).

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

A cross-platform desktop trading terminal (Windows 11 first; macOS and
Linux from the same code, §Platforms) for **several Schwab accounts behind
one login, picked with a thinkorswim-style account switcher** (§II.0):
account panel (balances, margin, day P&L),
streaming watchlist with integrated positions, candle + volume chart, and a
user-configurable grid of trading buttons with optional hotkeys — all order
flow gated through a rule engine whose hard rules flatten the account and
lock it for an enforced timeout when tripped. Every linked account is
watched by its own rules all the time, whichever one is on screen.

## Non-goals (v1)

- Options, futures, crypto — equities and ETFs only (the order builder keeps
  Schwab's `orderLegCollection` shape so options are additive later).
- Automated strategies / signal-driven entries. Buttons fire on human input
  only; the only autonomous actions are rule enforcement.
- A combined all-accounts view (one positions list or P&L across
  accounts) and multi-broker support (the adapter boundary keeps a second
  broker possible — D6). Switching between accounts is in scope (§II.0).
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
  jt-platform/   every per-OS piece: credential store, app-data paths,
                 opening the browser — no C dependencies, so the dev box
                 cross-checks it for Windows and macOS (§Platforms)
  jt-app/        Tauri v2 shell: UI (webview), hotkeys, config UI, packaging
```

**Shell: Tauri v2** (D3). The Rust backend owns everything that matters —
auth, tokens, the rule engine, order routing, lockout state — and the webview
is a rendering surface. The webview communicates only via Tauri commands and
events; **the only command that can create an order is
`press_button(button_id, account_hash)`** — there is no `place_order(order)` command
exposed to JS, so no UI code path reaches the broker without the engine.

**Chart: TradingView `lightweight-charts`** (Apache-2.0), bundled locally
(no CDN). **Frontend: vanilla TS + vite**, no UI framework (house style).

**Local state: SQLite** (via `rusqlite`, WAL) in the per-user app-data dir.

## Platforms

**Windows 11 is the first target** — x64 installer for the owner's PC, plus
ARM64 (the Windows test VM is ARM64; Tauri builds both). Windows 11 ships
the WebView2 runtime Tauri renders with, so the installer carries no
runtime bootstrapper. **Windows 10 is not supported** and nothing is built
for it. **macOS and Linux must stay one build command away**, which the
code earns by these rules:

- Every per-OS call lives in `jt-platform`, behind one function per need
  with a `cfg` branch per OS — no `cfg(windows)` anywhere else.
- Paths come from Tauri's app-data API, never hardcoded.
- Eastern Time comes from `chrono-tz` (time-zone rules compiled in), never
  the OS time-zone database — Windows does not use IANA zone names.
- The Linux keyutils backend (headless dev box) forgets its secrets at
  reboot: a reboot there means signing in again. Said in the README.
- Global hotkeys (§II.4) depend on the OS: on Linux under Wayland the
  global-shortcut plugin cannot register them, so there the editor says
  "this hotkey works only while the window is focused" instead of failing
  silently. On every OS, a global hotkey another program already holds
  (thinkorswim's own, say) fails at registration, and the editor says so.
- The repo gate cross-checks `jt-platform` and `jt-app` for
  `x86_64-pc-windows-gnu` and `aarch64-apple-darwin` on every safe-tier run
  (same pattern as `sync_cross_build_gate.sh`), so a change that breaks an
  OS nobody is running fails at once, not at release time.

**Token custody:** Schwab app key/secret and OAuth tokens in the OS
credential store via the `keyring` crate (Windows Credential Manager / macOS
Keychain / libsecret; on a headless Linux dev box with no D-Bus session, the
kernel keyutils backend). Nothing secret in config files, SQLite, or logs.
Windows Credential Manager caps one secret at 2,560 bytes, so the app key,
app secret, access token and refresh token are **four separate entries**;
the token response's `id_token` is not stored at all. Each account's
rules (§I.6) live in the same store, in entries of their own.

## The Broker trait (`jt-broker`)

The seam everything tests through. Shape (builder may refine signatures, not
responsibilities):

```rust
#[async_trait]
trait Broker: Send + Sync {
    async fn snapshots(&self) -> Result<Vec<AccountSnapshot>>;   // every granted account, one call: balances incl. start-of-day, positions
    async fn open_orders(&self) -> Result<Vec<Order>>;           // every account, one call; child orders flattened in
    async fn place_order(&self, intent: GatedIntent) -> Result<PlaceOutcome>;
    async fn replace_order(&self, id: &OrderId, intent: GatedIntent) -> Result<PlaceOutcome>;
    async fn cancel_order(&self, acct: &AccountHash, id: &OrderId) -> Result<()>;  // a request; the order may still fill
    async fn todays_executions(&self, acct: &AccountHash) -> Result<Vec<Execution>>; // trade count (§I.2)
    async fn transactions(&self, acct: &AccountHash, kind: TxnType, day: TradingDay) -> Result<Vec<Txn>>;
    async fn candles(&self, req: CandleRequest) -> Result<Vec<Candle>>;
    async fn subscribe(&self, req: StreamRequest) -> Result<BroadcastRx<StreamEvent>>;
}
// PlaceOutcome: Placed(OrderId) | AcceptedIdUnknown   ← 201 without Location, §I.3 step 3d
// StreamEvent: Quote{symbol, bid, ask, last, volume, halted, ts}
//            | MinuteBar{symbol, ohlcv, ts}
//            | OrderActivity{account, order_id, status, fill: Option<Fill>}
//            | StreamStatus{connected: bool}   ← reconnects surface, never hide
```

`OrderIntent` is the engine's vocabulary (market / limit / bracket / close),
translated to Schwab JSON inside the adapter only. **`place_order` takes a
`GatedIntent`, not an `OrderIntent`, and `GatedIntent`'s only constructor
is private to the gate + lockout module** — so "nothing reaches the broker
without the gate" is enforced by the compiler for the webview, the CLI,
enforcement and tests alike, not by a runtime assertion a test hook could
satisfy. Enforcement orders get theirs from the same module through an
enforcement-only path. Two implementations: `SchwabBroker` and `SimBroker`
(§II.5).

## Schwab API ground truth (verify, don't invent)

Schwab **Trader API — Individual** (developer.schwab.com; free; the owner's
key was approved 2026-10-09). Known shape, with gotchas the builder must
confirm against the official docs and captured live responses before
hardcoding (**the `schwabdev` Python library on GitHub is a maintained
working reference for every flow below**):

- **The developer-portal app** carries both API products — *Accounts and
  Trading Production* (balances, orders) and *Market Data Production*
  (quotes, price history, streaming) — and the callback URL below. A new
  app waits in "Approved – Pending" until Schwab marks it "Ready For Use";
  changing its settings later can send it back to waiting.
- **OAuth:** authorization-code flow in the system browser. Callback URL
  must be HTTPS — register `https://127.0.0.1:8443` on the developer-portal
  app (a port above 1024, so listening needs no admin rights on macOS or
  Linux) and listen locally with a self-signed cert (browser warning on that
  one localhost redirect is expected; schwabdev does the same). Token
  endpoint uses HTTP Basic auth (app key:secret). Access token ~30 min
  (auto-refresh); **refresh token hard-expires at 7 days** — the app shows
  a countdown warning from T-24h and offers one-click re-auth. An expired
  login means the engine cannot cancel or flatten, so order buttons are
  armed only while the login outlives the session (the *auth runway* gate,
  §I.2). **The 7 days is a ceiling, not a promise:** a refresh token can
  die early (signing in elsewhere with the same app key, an
  `invalid_client` from Schwab). So the runway also requires that the
  last refresh succeeded within one access-token lifetime; any refresh
  failure disarms every button, turns the status bar red, and puts up a
  notice listing the open positions the engine can no longer protect. The
  refresh token's issue time is stored in the credential store beside the
  token (not SQLite); when it is missing, the app assumes it has expired.
  If port 8443 is taken, the listener fails loudly and offers the
  paste-the-URL flow below. Headless login (a CLI on a machine whose browser is elsewhere):
  print the authorize URL, the owner signs in on any browser, the redirect
  page fails to load, and the owner pastes that page's URL back — the
  `code` is in its query string (schwabdev's manual flow).
- **Account type:** `securitiesAccount.type` is `MARGIN` or `CASH`. Short
  entries exist only on margin (§II.4). Cash accounts trade on settled
  money (§I.2 settled-cash gate): confirm in Phase 1 which
  `currentBalances` fields give settled cash on a cash account (candidates:
  `cashAvailableForTrading`, `unsettledCash`) from a captured response.
- **Account-number hash gotcha:** trading endpoints take a *hashed* account
  number, not the real one. `GET /trader/v1/accounts/accountNumbers` returns
  the mapping; resolve at link time and store the hashes. One Schwab login
  sees several accounts (the owner's: three cash, one margin), and
  Schwab's own consent screen during OAuth may let the owner choose which
  accounts the app is granted (verify). Linking ends with a list of every
  granted account — last four digits, `type`, liquidation value — where
  the owner ticks the ones to link and can give each a nickname.
  Unlinked accounts arrive in the all-accounts responses anyway; their
  data is discarded on parse — never modeled, logged or stored.
  Re-linking (re-auth, or adding an account later) re-reads the mapping; a
  linked account that disappears from it is shown as "no longer granted",
  never silently dropped with its rules.
- **Re-auth can drop an account mid-trouble.** Schwab's consent page may
  ask which accounts to grant on every sign-in (verify). After every
  re-auth the engine compares the granted accounts with every account
  that is `Enforcing`, `Locked`, or has enforcement orders working; any
  missing one raises a blocking red notice ("sign in again and include
  Roth") and **nothing arms on any account** until it is back.
- **Unlinking is a loosening.** Unlinking an account that is `Enforcing`
  or `Locked` is refused; otherwise it takes effect at the next trading
  day like any loosening (D4). An account's rules and lockouts stay keyed
  by its hash after unlinking, and relinking it reuses them — a relink
  never starts from § Defaults when the account has rules on file.
- **The account-activity stream names accounts by their real number**
  (field 1), not the hash. The number → hash map is built from
  `accountNumbers` and **kept in memory only**; real account numbers are
  never persisted, logged, or written into fixtures.
- **One login, all accounts, one call:** `GET /trader/v1/accounts?fields=positions`
  returns every granted account; the 60 s reconcile (§I.1) uses it, so
  more accounts do not mean more requests. Likewise `GET /trader/v1/orders`
  (no account in the path) lists orders across all accounts in one call —
  the order poller (§I.3) uses it. One streamer session per login carries
  quotes for the union of every linked account's held symbols, and
  account activity for all of them. Quote subscriptions are
  reference-counted: a symbol is unsubscribed only when no linked account
  holds it and it is not on the watchlist or chart.
- **One streamer session per user (reported, verify):** a second session
  for the same login may be refused (`LOGIN_DENIED`) or bounce the first —
  possibly including thinkorswim running at the same time. Phase 1 tests
  this with thinkorswim open. The app never fights for the session: on a
  bounce it backs off, shows "stream unavailable", and runs on REST
  (§I.1).
- **Pattern-day-trader rule: not modeled.** It applies only to margin
  accounts under $25,000; the owner's margin account is above that, and
  cash accounts are exempt.
- **Balances:** `GET /trader/v1/accounts/{hash}?fields=positions` →
  `securitiesAccount` with `initialBalances` (start-of-day; the day-P&L
  anchor, §I.1), `currentBalances` (liquidationValue, cashBalance,
  buyingPower, margin fields), and `positions[]` (quantity,
  averagePrice, instrument.symbol).
- **Orders:** `POST /trader/v1/accounts/{hash}/orders`. Strategy types
  `SINGLE`, `OCO`, `TRIGGER` (bracket = TRIGGER parent with OCO children —
  server-side, survives the app dying). Equity instructions: `BUY` opens
  long, `SELL` closes long, `SELL_SHORT` opens short, `BUY_TO_COVER` closes
  short — every close/flatten picks its instruction from the position's
  sign. Capture the exact JSON of each shape as fixtures in Phase 1.
- **The new order's id is usually in the `Location` header — not
  always.** Place returns 201 with an empty body and the id in the
  `Location` header (`…/orders/{orderId}`); replace (`PUT
  …/orders/{orderId}`) does the same for the new order. **schwabdev
  documents the header as absent when the order fills immediately** — a
  regular-session market order, i.e. exactly a flatten. So a 201 without
  `Location` is `AcceptedIdUnknown`, never an error and never a reason to
  submit again; §I.3 step 3d adopts the id from the order list. Phase 1
  confirms with a 1-share market buy in session.
- **Order statuses** (schwab-py's enum; freeze from captured responses):
  terminal = `FILLED`, `CANCELED` (one L), `REJECTED`, `EXPIRED`,
  `REPLACED`; everything else is non-terminal and can still fill —
  including `PENDING_CANCEL`, `AWAITING_MANUAL_REVIEW`,
  `PENDING_ACTIVATION`, `AWAITING_UR_OUT`, `QUEUED`, `ACCEPTED`,
  `WORKING`, `NEW`, `PENDING_ACKNOWLEDGEMENT`. **A cancel is a request:**
  the order can fill while `PENDING_CANCEL` ("too late to cancel").
- **After-hours market orders queue, they don't fail — in the normal
  session only.** A `MARKET` order with session `NORMAL` and duration
  `DAY` placed outside the regular session is expected to be accepted and
  held for the next open (confirm the status Schwab reports and freeze it
  as a fixture). Extended sessions (`AM`, `PM`, `SEAMLESS`) accept limit
  orders only, so a market order sent with them is rejected. §I.3 relies
  on this.
- **Bracket children and partial fills (verify):** Phase 1 checks whether
  a TRIGGER's OCO children release sized to the filled quantity on a
  partial fill, or wait for the full fill. Either way, an entry that ends
  partially filled (cancelled, or expired) must not leave children sized
  for the full quantity: the engine replaces them to the held quantity.
  Children sit nested in `childOrderStrategies`; every order parser
  recurses into it.
- **Today's executions come from transactions, not orders.**
  `GET /trader/v1/accounts/{hash}/transactions?types=TRADE` for today lists
  every execution regardless of when its order was entered — a resting
  GTC stop entered weeks ago that fills today included. (The orders
  endpoint's date window is 60 days per schwab-py and 1 year per
  schwabdev, and GTC orders live up to 180 days, so the orders list cannot
  be trusted to contain every order that filled today.) Each execution
  carries its order id and whether it opened or closed a position
  (`positionEffect` / the instruction in `transferItems`) — confirm both
  are present on orders placed outside the API (thinkorswim,
  schwab.com). Powers the daily trade count (§I.2). The orders endpoint
  is used only for working orders.
- **Cash moved in or out today:** the same transactions endpoint, one
  `types` value per call (the parameter takes a single type). Transfers
  span about nine types — `ACH_RECEIPT`, `ACH_DISBURSEMENT`,
  `CASH_RECEIPT`, `CASH_DISBURSEMENT`, `ELECTRONIC_FUND`, `WIRE_IN`,
  `WIRE_OUT`, `JOURNAL`, `RECEIVE_AND_DELIVER` (verified from the docs and
  frozen in one constant); dividends and interest are not transfers (they
  are P&L). Because that is nine calls per account, transfers are fetched
  once at start and then only when a balance change appears that no fill
  explains (§I.1) — never on a timer. A withdrawal may reduce cash before
  its transaction row posts; §I.1 holds such a change out of the rules
  until it is explained.
- **Price history:** `GET /marketdata/v1/pricehistory` (periodType /
  frequencyType / frequency / startDate / endDate epoch-ms,
  `needExtendedHoursData=false` for v1).
- **Streamer:** WebSocket; connection parameters come from
  `GET /trader/v1/userPreference` (streamer URL + customer/client ids);
  login frame carries the access token. Services used: level-one equity
  quotes (watchlist + position marks), chart-equity minute bars (live
  candle), account activity (order status/fills as push). Field lists are
  numeric-keyed — map them in one place in the adapter with named
  constants, verified from docs + schwabdev. **The account-activity
  service is a hint, not a source of truth** — it is reported sparse and
  irregular in Schwab's version; nothing waits on it alone (fill
  confirmation polls REST, §I.3).

**Rate limits** are undocumented for Schwab (TD Ameritrade's were 120
requests a minute). Human order flow is nowhere near that; machine flow
can be — four accounts enforcing at once on a gap. So all polling is
app-wide, not per account: one order poller (`GET /trader/v1/orders`,
every 2 s while any account is `Enforcing`, otherwise every 10 s), one
snapshot call for all accounts. On any 429/`Retry-After` back off and
surface a status-bar warning; inside `Enforcing` a 429 never changes what
the engine believes about an order and is never a reason to submit one
(§I.3).

**Server time:** every Schwab response carries an HTTP `Date` header. The
engine's clock is the server's — the local clock plus the offset measured
from those headers — so lockout deadlines, the trading day and the auth
runway cannot be moved by changing the PC's clock. A local clock more than
two minutes off is shown in the status bar.

---

# Part I — The rule engine (`jt-engine`)

## I.0 Accounts

The engine runs **one account model, one rule set and one enforcement
state machine per linked account**, all the time. Which account is on
screen (§II.0) changes only what is displayed and which account a button
press goes to — it never pauses an account's rules. A trip on an account
that is not on screen enforces exactly as it would on screen, and the
switcher shows it at once (§II.0). Accounts enforce independently: two
accounts tripping in the same second run two enforcements, neither
waiting on the other. **Lockouts are per account (D13):** a locked
account refuses its own buttons and nothing else — switching to another
account and trading there is allowed. Rules are set per account (a cash account and a
margin account rarely want the same numbers); a new account starts with
a copy of the § Defaults values.

## I.1 Account model

One continuously updated model per account: cash, buying power, positions (marked at
streaming quotes), open orders, current equity (liquidation value = cash +
marked positions), day P&L, and today's trade count. Inputs: the account-
activity stream (fast hints) + level-one quotes for held symbols, reconciled
against the REST snapshot on start and every 60 s — **REST is truth
on disagreement** (log the divergence).

- **Marks for the rules:** longs at the bid, shorts at the ask, never the
  last trade. A one-sided or crossed quote (bid 0, ask below bid) is not a
  mark; the position keeps its previous one.
- **Stream down or stale:** in session, no frame for 15 s marks the stream
  stale even if the socket looks open (a PC waking from sleep holds a dead
  socket that still looks connected). While the stream is down or stale,
  hard-rule equity is Schwab's `liquidationValue` from a REST snapshot
  every 10 s instead of 60, and the status bar says "rules running on
  10-second balances".
- **Waking from sleep:** a gap in the monotonic clock, or the OS resume
  event, triggers a full reconcile (snapshots, orders, executions) before
  any button is enabled again.

**Day P&L anchors on the broker, not the app:** Schwab's `initialBalances`
*is* the start-of-day equity, so

```
day P&L = current equity − initialBalances.liquidationValue − net transfers today − unexplained changes
```

where *net transfers today* is the signed sum of today's deposits,
withdrawals, journals and security transfers from the transactions
endpoint (deposits positive). Without that term a $1,000 withdrawal reads
as a $1,000 loss and trips the daily-loss rule — a forced flatten caused by
moving money.

*Unexplained changes:* on each reconcile, the engine compares the change
in cash with the net proceeds of the executions since the last reconcile,
and each position's quantity change with its executions. A difference no
execution explains — a cash step, or shares appearing or leaving — is a
transfer not yet posted (or a dividend). It triggers a transactions fetch
(§ Ground truth) and, until a transaction explains it, is held out of the
day P&L the hard rules use and shown in the account panel as "unexplained
change −$1,000". A trading loss never arrives as a cash step without an
execution, so holding these out hides no loss.

No local snapshot to get wrong when the app first launches mid-day, and
nothing local to delete (§I.4).

**The trading day runs from day roll to day roll, not midnight to
midnight.** Schwab replaces `initialBalances` in an overnight batch, not
at 00:00 ET; between midnight and the batch, `initialBalances` is still
yesterday's. `market_calendar` owns a **day roll** — 04:00 ET on weekdays
until Phase 1 pins the real batch time by sampling `initialBalances`
overnight — and the trading day is the span from one weekday roll to the
next (a weekend belongs to Friday's day). Rest-of-day lockouts end at the
next roll; percent-of-equity limits re-freeze at it; no lockout row is
ever written for a trading day before its roll.

## I.2 Rule inventory

Full vocabulary, decided up front. **Every limit, threshold and lockout
duration below is owner-set in the rules editor (§II.6)**; § Defaults only
supplies starting values. Dollar amounts; percent-of-equity forms convert
to dollars against `initialBalances` at first evaluation each day and
freeze for the day.

**Hard rules** — monitored continuously; tripping executes §I.3:

| Rule | Trips when | Semantics pinned | v1 |
|---|---|---|---|
| Max daily loss — two steps | first limit: day P&L ≤ −first limit → lockout for the timeout; second limit: day P&L ≤ −second limit → lockout for the rest of the day | includes unrealized + fees; anything done outside the app counts (broker-anchored); see below | ✅ |
| Daily profit target | day P&L ≥ +target | lock in the day; same day-P&L figure as max daily loss (transfers excluded); default lockout = rest of day | ✅ |
| Max open loss per position | position unrealized P&L ≤ −limit | position-scoped enforcement (§I.3 exception) | ✅ |
| Trading window | position open / order live outside configured hours | — | later (default off) |

**Max daily loss, two steps (D10).** Three owner-set values: the first
limit, the timeout, and the second limit (a larger loss than the first).

1. Day P&L reaches −first limit → flatten, lock for the timeout.
2. The timeout ends → trading resumes. The first step has fired and never
   fires again today, even if day P&L recovers above the first limit and
   falls back through it.
3. Day P&L reaches −second limit → flatten, lock for the rest of the
   trading day. Done.

A move straight through both limits (a gap) skips to step 3. The second
limit is optional: without one, the first limit locks for the rest of the
day. Both limits are measured on the same day-P&L figure (§I.1), not from
the point of the first trip — so "−$500 then −$750" means the day ends at
−$750 total, i.e. $250 more after the break.

**Gate rules** — checked synchronously at button press; violating orders are
refused with the reason shown, nothing else happens:

**What counts as a trade:** one order that opens or adds to a position
(`BUY`, `SELL_SHORT`) and filled today, fully or partly — partial fills of
one order are one trade. Counted by order id, once: an entry that is
partly filled and still working is one trade, not a trade plus a
slot-holding working order. Exits never count: a bracket's stop or target
filling, a close, and every enforcement order are closes. Trades made in
Schwab's own apps count toward the limit; if they push the count past it,
the rule strip shows the overage ("trades 12/10") and the app enforces
nothing beyond refusing entries.

**Gates check fresh state:** if the account's last REST snapshot is more
than 5 s old (a position opened in thinkorswim is otherwise invisible for
up to a minute), the press first takes one snapshot (~200 ms), then
gates.

| Rule | Refuses | v1 |
|---|---|---|
| Max position size (shares or notional, per symbol; default applies to all) | any order whose fill would exceed it (resting orders count toward exposure) | ✅ |
| Max single-order size (fat-finger, notional) | any order above the cap | ✅ |
| Max daily trades (D11) | any position-opening order once today's trades **plus working entry orders** reach the limit — a resting entry limit order holds its slot, so two presses at 9/10 cannot both fill. Closes, flatten, cancels and bracket exits are always allowed | ✅ |
| Entries-only-with-stop | any position-opening button not configured as a bracket (toggleable; default on) | ✅ |
| Auth runway (not configurable) | every order button, when the refresh token expires before the end of the current regular session (or, outside the session, the next one), or the last token refresh failed or is older than one access-token lifetime. The status bar names the fix: sign in again | ✅ |
| Short entries need margin (not configurable) | any short-opening order on a `CASH` account | ✅ |
| Buy with settled cash only (cash accounts; toggleable, default on) | any buy on a `CASH` account costing more than its settled cash minus the cost of its working buy orders. Buying with today's sale proceeds and selling before they settle is a good-faith violation at Schwab; enough of them restrict the account for 90 days. Dollar and %-of-buying-power quantities size against settled cash on these accounts | ✅ |
| No entries while day P&L below soft threshold | entry orders only — exits always allowed | later |

## I.3 Enforcement sequence (hard-rule trip)

Owner-decided: **flatten + lockout** (D1). A state machine per account,
persisted at every transition: `Normal → Enforcing → Locked → Normal`.
`Enforcing` can show a `Stalled` condition (step 3); `Locked → Enforcing`
happens when a stricter rule trips during a lockout (step 4).

**The one rule that keeps the account from ending up short:** a new
closing order is submitted on a symbol only when a fresh REST read shows
**no working order of any kind on that symbol, from any source**, and
the quantity it closes is the held quantity from that same read. Cancels
are requests (an order can fill while `PENDING_CANCEL`), an order placed
in thinkorswim is as real as ours, and an order whose id we never learned
is still live. Every step below obeys this rule; nothing else is a
reason to submit.

0. **Confirm the trip.** A trip computed from streamed marks is
   confirmed by one immediate REST snapshot (~300 ms) before anything
   happens; if Schwab's own `liquidationValue` does not confirm it, the
   divergence is logged and nothing trips. One bad print or a one-sided
   quote at 09:30 must not flatten the account. A trip computed from a
   REST snapshot needs no second read.
1. **Enter `Enforcing`** (buttons/hotkeys on this account refused from
   here on).
2. **Cancel** every working order on the account — bracket children,
   orders placed in thinkorswim, everything. A non-enforcement order
   that appears on the account later while `Enforcing` is cancelled too.
3. **Flatten** each symbol, independently:
   - a. Wait until the order poller shows no working order on the symbol
     (all cancels from step 2 terminal).
   - b. Read the held quantity from that same poll's snapshot. Zero →
     the symbol is done.
   - c. **Write ahead:** persist "placing SELL 100 SPY at {time}" (or
     `BUY_TO_COVER` for a short) before the HTTP call.
   - d. Submit a market order for the held quantity. `Placed(id)` →
     record the id. `AcceptedIdUnknown` (no `Location` — Schwab omits it
     when the order fills at once), a timeout or a network error → the
     order **may exist**: submit nothing more on this symbol; read the
     order list and adopt the order matching symbol, instruction and
     quantity entered since the write-ahead time. Until it is adopted, or
     two successive polls show neither the order nor any change in the
     held quantity, the symbol is blocked.
   - e. Watch the order through the poller. Filled → back to (b) (a
     partial fill leaves a remainder). Still working after 10 s while
     the symbol is trading → cancel it, back to (a). Rejected → surface
     loudly, retry from (a) every 60 s until flat.
   - **Halted symbol:** while level-one quotes show the symbol halted
     (field verified in Phase 1), the 10 s rule is suspended — the single
     order waits for the reopen, and the overlay says "XYZ halted, sell
     order waits for reopen". Cancelling into a halt would only lose the
     order's place in line.
   - **Stalled:** an enforcement order or a cancel whose status has not
     changed for 30 s (parked in `AWAITING_MANUAL_REVIEW`,
     `PENDING_CANCEL`, or a cancel that returned 200 but never took)
     puts the account in `Stalled`: red overlay and a sound, naming the
     order and its status. Polling continues, nothing new is submitted
     on that symbol, and the condition clears when the status moves.
   - **REST trouble** (429, 5xx, unreachable) keeps the last known order
     state, backs off per `Retry-After`, and stays loud. An error is
     never a reason to submit; only a successful read proving (a) and
     (b) is. When REST returns and shows an earlier order filled, the
     engine moves on — never a duplicate.
   - **Outside the regular session** (09:30–16:00 ET, per
     `market_calendar`), orders go in with session `NORMAL`, duration
     `DAY` (an extended-session market order is rejected); Schwab holds
     them for the next open. An order accepted and queued counts as
     *flatten pending at open* — not cancelled, re-submitted or retried;
     the engine watches it until it fills at the open. If Schwab instead
     rejects it, the engine retries each minute and, failing that,
     submits at 09:30.
   - Flatten never silently gives up; `Enforcing` cannot be exited except
     to `Locked`, and only once the account is flat **or** every remaining
     position has an accepted, queued flatten order.
4. **Enter `Locked`:** an overlay covering the locked account's view
   (the switcher stays usable; other accounts trade normally) — which
   rule, the numbers, a countdown; with orders queued for the open, the
   overlay lists them ("SPY −100, sells at the 09:30 open"). Lockout
   length is per rule and owner-set (§I.2): the daily-loss first step
   locks for its timeout, its second step and the profit target until
   the next day roll (§I.1). The countdown is a deadline in server time
   (§ Ground truth), so the PC's clock cannot shorten it. Account data,
   watchlist, chart stay live — watch, not touch. Unlock = deadline
   reached **and** no enforcement order still working; a lockout never
   ends with its own flatten orders live. **No override control
   exists.** Not hidden, not confirm-three-times. None. Enforcement
   orders are recorded by id in the lockout row and are excluded from the
   Cancel-all button.
   - **Hard rules keep evaluating while `Locked`.** A stricter trip — the
     daily loss's second limit reached during the first step's timeout
     by a late fill, a fee, or a queued flatten filling on an opening gap
     — re-enters `Enforcing` (usually nothing left to flatten) and
     replaces the lockout with the stricter one.
   - **A trip after the close** with a short timeout holds until the
     queued flatten fills at the next open. That fill lands in the new
     trading day, whose day P&L starts from the new `initialBalances`
     and can trip again on the opening gap; the overlay says both
     ("sells at the open; an opening gap can lock this account again").
   - **Cash accounts:** flattening can sell shares bought today with
     unsettled money — a good-faith violation at Schwab. Enforcement
     still sells; the overlay marks those symbols "good-faith violation
     likely", and the README says so.
5. **Audit log** rows for the trip, the numbers, every enforcement order,
   every write-ahead and how it resolved.

**Scope exception — per-position rule:** max-open-loss-per-position enforces
against its position only: cancel that symbol's orders, market-close that
position, log. No account flatten, no lockout — a catastrophic backstop
stop, not a day-ender. Account-level rules always run the full sequence.
Steps 0 and 3 apply to it unchanged (confirmation, the no-working-order
rule, write-ahead, halts, stalls, after-hours). The manual Flatten-all
button runs steps 2–3 the same way.

## I.4 Making enforcement stick (honest tamper model)

Watchdog is **app-only** (D2): rules evaluate while the app runs. Within
that scope, tampering is made pointless by **re-deriving trigger state from
Schwab rather than trusting local files**:

- On every start: day P&L from `initialBalances`, current equity and
  today's transfers; today's trades from today's executions (so the
  trade gate is right on the first press after a restart). A tripped
  daily-loss / profit-target condition therefore **re-trips immediately on restart** — killing the app
  clears nothing, because the lockout is a consequence of account state.
  The persisted lockout row carries the countdown across restarts and
  records that the daily-loss first step already fired today; trip
  conditions are still re-evaluated first thing, before the UI enables
  anything. Day P&L past the second limit locks until the next day roll on
  any start. Deleting or editing the database to lose that row blocks the
  whole app until the next roll (§I.6), so it never buys an early unlock.
  All of this runs on the current trading day as `market_calendar` defines it
  (§I.1): a start at 00:30 ET after a losing day still reads yesterday's
  `initialBalances` as yesterday's, and writes nothing for the new day.
- **A restart during `Enforcing`** (crash, kill, power loss) resumes
  enforcement before anything else: read the order list and today's
  executions, resolve every unresolved write-ahead row by matching it to
  an order, adopt any working closing order on a symbol as that symbol's
  flatten order, then continue at §I.3 step 3a. The no-working-order
  rule makes this safe even with the database gone (today's rules are
  not in it, §I.6): the re-tripped rule cancels everything working,
  waits for terminal states, and sells only what is still held.
- **Rule changes are asymmetric (D4):** tightening applies immediately;
  loosening or deleting takes effect at the next day roll, never
  intraday — held as today's and next day's rule sets (§I.6). Compared
  **value by value**: a change that lowers the first limit and shortens
  the timeout takes the lower limit today and the shorter timeout
  tomorrow. For the two-step daily loss: a smaller limit, a longer
  timeout, or removing the second limit (which makes the first step end
  the day) is tightening; a larger limit, a shorter timeout, or adding a
  second limit is loosening.
- **Today's rules are not in the app's data folder** (§I.6), so deleting
  or editing that folder cannot loosen today. Deleting the database during
  a first-step lockout loses its row; the rule re-trips with a fresh
  timeout — a second break, never an early unlock.
- Outside the model, stated plainly in the README: Schwab's own apps keep
  working. The tool makes the disciplined path the fast path.

## I.5 Evaluation cadence

Hard rules evaluate on every account event (fill, order status, quote tick
on a held symbol) and on a 1 s timer floor; a trip from streamed marks is
confirmed by REST before it acts (§I.3 step 0). Evaluation is pure (state in →
verdict out) and shares a lock with order gating: a button press during a
trip-in-flight is refused, and two simultaneous trips enforce once (state
machine, not flags).

## I.6 Rules are frozen per day (D14)

The rule is the owner's: **rules are set for the day; any change takes
effect at the next day roll** — except tightening, which may apply at
once (D4). The one thing code alone cannot do is protect *where today's
rules are stored*: anything in the app's data folder can be deleted or
edited, and then there is nothing left to enforce. So **rules do not live
in the app's data folder at all.** They live in the OS credential store,
two entries per account:

- `rules.today.{account}` — the rules in force for the current trading
  day. The engine enforces this and nothing else.
- `rules.next.{account}` — the rules that take effect at the next day
  roll.

**Writing:** the rules editor (§II.6) is the only writer. Tightening a
value writes it to both entries (applies now). Loosening writes it to
`next` only (applies tomorrow). `today` can therefore only ever become
stricter during the day.

**At the day roll** (or at the first start after it, if the app was not
running at 04:00 ET), `today` ← `next`. Only the app does this, and only
across a roll in server time (§ Ground truth), so changing the PC's clock
does not promote a loosening early.

**What deleting or editing does:**

| What happens | Effect |
|---|---|
| The rules are changed in the editor | tighter: now; looser: next day roll |
| The app's data folder is deleted | today's rules are untouched — the engine keeps enforcing them, and buttons can be rebuilt and used under them. Buttons, watchlist and layout are lost (they lived there) |
| The database is edited by hand | no effect on rules — they are not in it |
| An account's credential-store rules are deleted | that account has no rules: it cannot trade until rules are set and a day roll passes (same as a new account) |
| Credential store unavailable | nothing arms |

**New installs and new accounts arm at the next day roll.** An account
with no `today` entry has no rules in force, so it cannot trade; rules
set for it go into `next` and take effect at the next roll. On day one
the owner links accounts, sets rules, builds buttons, watches the
market, and rehearses in sim — buttons on live accounts show "arms at
4:00 a.m. ET". Relinking an account whose entries still exist uses them
and needs no wait.

**Honest limit.** The credential-store entries can be deleted or edited
by the owner's own Windows account. Deleting them only stops trading
until tomorrow; editing them by hand (through PowerShell, say) is
deliberate work, not an in-the-moment click — the tool's job is to make
the disciplined path the fast one. The audit log records every rule
change; sim mode keeps its own rules in `sim.db`.

---

# Part II — The terminal UI (`jt-app`)

Single window, four regions (splitter-draggable, layout persisted): the
account switcher, account panel and rule strip across the top; watchlist left; chart center; button grid
bottom (spanning). Status bar: stream health, auth countdown (< 24 h),
sim/live mode.

## II.0 Account switcher

A dropdown at the top left, as in thinkorswim. Each row: nickname (or
last four digits), `CASH`/`MARGIN`, day P&L, and a status badge —
**Locked 14:32 left**, **Enforcing**, **Done for today**, or nothing.
Choosing an account changes the whole window to it: account panel, rule
strip, positions band, open orders, and the account every button press
and hotkey goes to. The chart and the plain watchlist are shared across
accounts; the positions band is the selected account's.

- **The selected account is never in doubt:** its nickname and type sit
  in the button grid's header and in every confirm dialog, and each
  account can carry a color that tints the switcher and grid header.
- **A trip elsewhere is loud:** an account that trips while not selected
  flashes its badge in the switcher and raises a non-blocking banner
  ("Roth — daily loss, flattening"). The window does not switch by itself
  (a hotkey mid-press must not land on a different account).
- Switching is refused while a confirm dialog is open, and during a
  switch every button is disabled until the new account's state is
  loaded — a press can never go to a half-switched window.
- The selected account is remembered across restarts.

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
| Close position | symbol; percentage (25/50/100) of the held quantity **minus closing orders already working on it** — two fast "Close 100%" presses send one order, and a press with nothing left to close is refused; `SELL` or `BUY_TO_COVER` by the position's sign |
| Flatten all | cancel all orders + close all positions (manual §I.3 steps 2–3; never locks) |
| Cancel all orders | optional per-symbol. **Keeps the stops protecting held positions** (bracket children of a filled entry — cancelling either OCO child cancels both) and says "3 stops kept"; Flatten all is the way out of a position |

**Which way a Buy or Sell goes depends on the position, and it never
flips one (D15).** Against an open position, the button is an exit: a
Sell while long sends `SELL`, a Buy while short sends `BUY_TO_COVER`,
for at most the held quantity minus closing orders already working on
it. Anything beyond that is dropped and said plainly — "Sold 100 of 200
— that's all you held". With no position, the button is an entry (`BUY`,
or `SELL_SHORT` for a Sell) and passes every entry gate: margin only for
shorts, stop required, trade limit, sizes. One press never closes a
position and opens the opposite one.

Stored shape (SQLite `buttons.config` JSON):
`{action, symbol_mode: "fixed"|"chart", symbol?, qty: {kind: "shares"|"dollars"|"pct_bp", value}, limit?: {ref: "bid"|"ask"|"mark"|"abs", offset}, stop_offset?, target_offset?, close_pct?, confirm: bool, hotkey?, global_hotkey: bool, color?, position: {row, col}}`.
Created/edited in a dialog (no JSON editing), drag re-order, color labels.
Sell-side of a bracket mirrors symmetrically: `SELL_SHORT` entry, stop and
target children `BUY_TO_COVER`, stop above. Short entries are refused on a
`CASH` account (§I.2); a symbol Schwab will not lend is Schwab's rejection,
surfaced as-is.

**Hotkeys:** press-to-record in the editor; active while the window is
focused. Optional **global** (Tauri global-shortcut plugin) per button —
intended for defensive buttons (Flatten all, Cancel all); making an *entry*
hotkey global requires ticking a clearly worded checkbox. A global hotkey
pressed while another program has focus brings the window forward and
shows the account it acted on ("Flatten all — Roth") — it always acts on
the account selected in the switcher. All hotkeys dead
while the selected account is `Enforcing`/`Locked` and while any confirm
dialog is open. Conflicts
refused at record time.

**Buttons are shared across accounts**: one grid, and a press acts on the
account selected in the switcher, gated by that account's rules. A
button's quantity in dollars or % of buying power sizes against the
selected account.

**Press path (the only order path):** webview sends
`press_button(button_id, account_hash, chart_symbol)` — the account and
chart symbol the window showed when the press happened; the engine
refuses the press if either no longer matches (a switch or a watchlist
click raced the press) → engine loads config → resolves symbol/prices
from live state → builds `OrderIntent` → gate rules → lockout check →
optional confirm (native dialog) → **after OK, gate, lockout and prices
are run again on fresh state**; if the account locked meanwhile, a gate
now refuses, or the price moved past the button's offset, the press is
refused and the confirm is shown again with the new numbers — a confirm
left open for minutes never sends a stale order → `Broker::place_order`
(a `GatedIntent`) → audit log.

## II.5 Simulation mode

`SimBroker` is reachable from the real UI, not just tests: paper accounts
— by default one margin and one cash account, so the switcher and the
cash-account rules are rehearsable (configurable count, type and starting
cash) filled against live streamed quotes when
Schwab market-data auth exists, or **replayed JSONL sessions** (recorded
from the live stream; several ship in-repo) when it doesn't. Fill model:
market fills at opposing quote (ask for buys) up to displayed size, limit
fills when the quote crosses, brackets honored locally. Mode is loud —
colored window border + persistent badge; sim and live never share an
account model or database file. Schwab has no paper-trading API; this is
the rehearsal surface for buttons, hotkeys, and rule trips.

## II.6 Rules editor

Opens on the selected account (an account picker at its top switches);
rules are per account (§I.0). One dialog lists every §I.2 rule with its values and an on/off switch
(rules marked "not configurable" are shown, not editable). Values are
typed in dollars or percent of start-of-day equity; durations in minutes
or "rest of day". On save, each changed value is labeled before it is
written: **"applies now"** (tightening) or **"applies tomorrow"**
(loosening), and pending loosenings stay listed with their date until
they take effect. Invalid combinations are refused at save with the
reason — a second daily-loss limit that is not a larger loss than the
first, a zero or negative limit. The editor is open during `Locked` (it
can only tighten anything that matters today) and is the one place rules
change; there is no config file.

---

# Part III — Persistence (SQLite)

One database (`trader.db`), plus `sim.db` for sim mode. Live rules are
**not** in the database — they are in the credential store (§I.6).
Tables (builder may add columns, not drop):

- `settings` — key/value (layout, watchlist order, chart prefs, selected
  account, sim config).
- `accounts` — `account_hash, last4, type, nickname, color, sort_order,
  linked, linked_at, unlink_effective_date`. The full account number is
  never persisted; it exists only in memory (§ Ground truth).
- `pending_orders` — the write-ahead rows of §I.3 step 3c: `account_hash,
  symbol, instruction, qty, written_at, resolved_order_id, resolved_at`.
- `buttons` — `button_id, config_json (§II.4), created_at, updated_at`.
- `watchlist` — `symbol, sort_order`.
- `lockouts` — `lockout_id, account_hash, rule_id, step, trading_day, tripped_at,
  until, enforcement_order_ids_json, context_json` (`step` = 1 or 2 for
  the two-step daily loss; carries the countdown, the fact that step 1
  fired today, and the enforcement orders across restarts; never trusted
  as the *only* trip signal — §I.4).
- `audit_log` — append-only: `ts, account_hash, kind, detail_json` for every button
  press, gate refusal, order, fill, rule evaluation trip, enforcement
  action, auth event. No secrets ever.

---

# Verification

The rule engine is the product claim, so it gets the harness treatment.

**`jt-sim` scenario tests** (`cargo test -p jt-sim`; scripted quote/fill
feeds + controlled clock; no wall-clock, no network). Required scenarios:
every hard-rule trip; every gate refusal; multiple accounts (a trip on
an account not on screen enforces fully; two accounts tripping together
enforce independently; a press goes only to the selected account and a
press racing a switch is refused; one account's lockout leaves the
others' buttons, hotkeys and positions untouched); settled-cash
gate on a cash account (a buy with today's sale proceeds refused); two-step daily loss (first
limit locks for the timeout, trading resumes, a recovery above and a fall
back through the first limit does not re-trip, the second limit ends the
day, a gap through both goes straight to rest-of-day, no second limit =
first ends the day); rules frozen per day (database deleted mid-day →
today's rules still enforced, including a sell-off under way; database
edited → rules unchanged; loosening → `next` only, applied at the roll;
tightening → both, applied now; app not running at 04:00 → promoted at
first start; clock moved forward → no early promotion; an account's
credential-store rules deleted → no trading until rules are set and a
roll passes; a newly linked account arms at the next roll, a relinked
one with entries on file arms at once; credential store unavailable →
nothing arms); trade limit (entry refused at the
limit, a working entry order holds a slot, exits and stop-outs allowed and
uncounted, partial fills of one order = one trade, outside-app overage
shown and not enforced); rule edits value by value
(tighter now, looser tomorrow); partial-fill flatten with
re-submit; a flatten order still working at 10 s is cancelled before its
remainder is sent (never two live flatten orders on one symbol — the
account never ends short); rejected flatten order retry-until-flat;
after-hours trip (orders accepted and queued, not re-submitted, lock
holds until they fill at the open); withdrawal and deposit intraday
(neither trips a rule); auth runway (buttons refused when the login
expires before the close); short position flattened with `BUY_TO_COVER`;
restart during `Locked` (countdown persists); restart after loss with DB
**deleted** (re-trips from broker truth); rule-loosening deferred to next
day / tightening immediate; day rollover resets; REST-vs-stream
disagreement (REST wins); stream drop mid-enforcement; two rules tripping
in the same tick (one enforcement); button press during `Enforcing`
(refused); per-position trip closes only its position; sim fill model
(market, limit cross, bracket).

Added by the 2026-10-09 review, each naming the bug it catches: 201
without `Location` on an instant fill — id adopted, no second order;
cancel returns 200 but the order fills anyway ("too late to cancel") —
no re-submit, no short; REST 429 / 5xx / unreachable during `Enforcing`,
then returning to show the earlier order filled — no duplicate; a
working order placed in thinkorswim during `Enforcing` — cancelled before
any flatten on that symbol; restart during `Enforcing`, and a crash
between submit and persisting the id — write-ahead resolved, order
adopted, nothing doubled; an order stuck in `AWAITING_MANUAL_REVIEW` —
`Stalled` shown, nothing new submitted; halted symbol — one order held
through the halt; second limit reached while the first step is
`Locked` — re-enforces, lock becomes rest-of-day; app running across
midnight ET with `initialBalances` unchanged until the roll — no new-day
lockout, no false trip; a bad print / one-sided quote through the limit —
REST disagrees, no trip; withdrawal whose transaction posts minutes after
the cash leaves — held out as unexplained, no trip; stream stale with the
socket open — rules move to 10 s REST; clock moved forward during
`Locked` — no early unlock; refresh failure mid-session — buttons
disarmed; re-auth that drops a locked account — nothing arms; unlink
while `Locked` — refused; cash-account gate with a working buy order —
its cost counted; partly filled working entry — counted once; two fast
Close 100% — one order; Sell 200 while holding 100 — sells 100, says
so, no short; Sell with no position — a short entry through every entry
gate; Cancel all — stops kept; confirm left open while
the account locks — refused on OK; chart symbol changed between press
and resolution — refused.

**The "DB deleted" scenarios keep the `SimBroker`'s own state across the
simulated restart** — only the app's state is wiped, or the scenario
proves nothing. Rule-storage tests run against an in-memory credential
store, the same trait the real one implements.

Two invariants hold in every scenario: **no order reaches the Broker
without passing the gate** — enforced by the `GatedIntent` type (§ Broker
trait), and asserted by the sim as a backstop — and **a tripped hard rule
always ends in flat+locked, locked with flatten orders queued for the
open, or a loudly surfaced, still-retrying (or `Stalled`) failure** —
never a silent partial state. A third is asserted after every step: **no
account ever holds a position the opposite way from before enforcement
began** (the oversell check).

**Broker contract tests** (`cargo test -p jt-broker`): serializers/parsers
against fixtures — order JSON for every §II.4 action (incl. both bracket
directions and close-short), order id from the `Location` header (and a
201 without one is `AcceptedIdUnknown`), every order status including
`CANCELED` spelled Schwab's way, nested `childOrderStrategies`,
account/positions/initialBalances parse, transactions parse and
transfer-type classification, executions from `TRADE` transactions with
order id and opening/closing, queued after-hours order status (session
`NORMAL`), streamer frame decode including the halted flag and the
account-number field, token refresh, account-hash resolution. Fixtures start from the
API docs, get replaced by captured live responses in Phase 1.

**Live gate** (owner-run checklist, 1-share orders): auth ceremony with
account picking; switching between a cash and the margin account with a
1-share order in each;
forced 7-day re-auth path; bracket entry lands as TRIGGER+OCO at Schwab;
one forced daily-loss first-step trip with real flatten; lockout survives app
restart; one after-hours 1-share market order to confirm Schwab queues it
(then cancel it); a 1-share market buy in session to see whether the 201
carries `Location`; thinkorswim open while the app streams; overnight
sampling of `initialBalances` to pin the day roll.

**Repo gate:** `tests/functional/trader/trader_sim_gate.sh` — tier `safe`,
env `any`, needs `[rust]`, skip-if-no-toolchain (same pattern as
`sync_sim_gate.sh`) — runs the jt-sim + jt-broker suites.

---

# Phases (each independently useful, each with a done-bar)

- **Phase 1 — broker plumbing.** OAuth ceremony + keyring custody +
  auto-refresh; account/positions/balances; quotes, price history,
  streamer subscribe; CLI proof (`jt accounts`, `jt account <nickname>`,
  `jt quote SPY`, `jt stream SPY`). *Done when:* CLI shows live account + streaming quotes from the
  real key; contract tests green on captured fixtures. (Fixture-first: all
  serializer work proceeds keyless while approval is pending.)
- **Phase 2 — engine + sim.** Account model, full v1 rule set, enforcement
  state machine, lockout, audit log, per-day rule storage (§I.6),
  `SimBroker` + replay. *Done when:*
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
  accounts; owner-run live gate; Windows 11 installers, x64 and ARM64
  (NSIS via the Tauri bundler). *Done when:* the live-gate checklist is
  signed off. macOS/Linux installers are one bundler run each when wanted
  (§Platforms keeps them compiling throughout).

---

# Part IV — Build contract (executor notes)

Rules for whoever (whatever) builds this:

1. **Verify, don't invent, Schwab specifics.** Endpoint paths, JSON field
   names, streamer field numbers, OAuth quirks: confirm against the
   official docs and the `schwabdev` reference implementation, then freeze
   as fixtures. Never hardcode a field name that hasn't been seen in a doc
   or a captured response. When docs and reality disagree, reality wins
   and the fixture records it.
2. **Keyless work never waits on the key.** Phase 1's fixture half and
   Phase 2 need no Schwab access (the key was approved 2026-10-09).
3. **The engine owns all order flow.** `Broker::place_order` takes only a
   `GatedIntent`, constructible only inside the gate + lockout module. If a
   change would let the webview, a test hook, or a CLI flag build one any
   other way, it is wrong — restructure instead. The §Verification
   invariants are non-negotiable and must stay asserted in every
   scenario.
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
8. **All timestamps UTC internally, in server time (§ Ground truth); ET
   only at display and day-boundary logic** (single `market_calendar`
   module owns the day roll and "trading day" (§I.1), 09:30–16:00 ET,
   weekend awareness, via `chrono-tz`; holidays deliberately not modeled
   in v1 — a holiday is just a day where nothing ticks).

# Decisions (resolved)

- **D1 — Flatten + app lockout**, no override path; profit-target defaults
  to rest-of-day. (Owner, 2026-07-16.)
- **D2 — App-only watchdog**; lockout survives restarts via re-derivation
  from broker truth (`initialBalances`, today's fills), not a trusted local
  flag. (Owner, 2026-07-16.)
- **D3 — Tauri v2 / Rust core**; the webview can only trade through
  `press_button`. (Owner, 2026-07-16.)
- **D4 — Rule-change asymmetry:** tighten immediately, loosen next trading
  day (today's and next day's rule sets, §I.6).
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
  with. Today's deposits, withdrawals and transfers are subtracted, so
  moving money never trips a rule.
- **D10 — Max daily loss has two steps**, every value owner-set: the
  first limit locks for a timeout, then trading resumes; the second limit
  (measured on the same day P&L) ends the day. (Owner, 2026-10-09.)
- **D11 — The daily trade limit refuses new entries; it never flattens or
  locks.** A trade is an order that opens or adds to a position; exits
  never count. (Owner, 2026-10-09.)
- **D12 — Multiple accounts behind one login, thinkorswim-style
  switcher;** every linked account's rules run all the time; buttons are
  shared and act on the selected account. **Windows 11 first, no
  Windows 10; macOS/Linux kept one build away.** (Owner, 2026-10-09.)
- **D13 — Lockouts are per account:** a trip sells off and locks only the
  account that tripped; the others keep trading. (Owner, 2026-10-09.)
- **D14 — Rules are frozen per day and kept outside the app's data
  folder:** today's and next day's rules per account live in the OS
  credential store; deleting or editing the app's data cannot loosen
  today; a new install or new account arms at the next day roll.
  (Owner, 2026-10-09; replaces a heavier "data seal" design the same
  evening.)
- **D15 — An exit never flips a position:** a Sell while long (or Buy while
  short) sells at most what is held and reports any excess as dropped;
  with no position the same button is a gated entry. (Owner, 2026-10-09.)

# Defaults (owner may override)

Builder proceeds with these; each is a one-line change later:

- App name **"Trader Terminal"**, binary/CLI **`jt`**, bundle id
  `com.joinery.trader`.
- Trading-window hard rule: **not in v1** (inventoried, default off).
- Chart extras: **none in v1**; first candidates when asked are VWAP and
  prior-day high/low lines.
- Max daily loss: second limit **on**, starting at 1.5× the first;
  first-step timeout **30 minutes**. All three owner-set (§II.6).
- Profit-target lockout: **rest of day**, owner-set.
- Entries-only-with-stop gate: **on** by default.
- Sim starting cash: $100,000.

# Out of scope (deliberate)

Options/futures/crypto; automation and signals; a combined all-accounts
view; Windows 10; multi-broker UI (adapter keeps it possible); backtesting; level 2; news; extended-hours
data; holiday calendar; mobile; any pretense of preventing trading through
Schwab's own surfaces.
