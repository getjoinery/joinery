# Paid AI credits with private inference

**Status: DRAFT — 2026-09-22. Not built. Q1, Q2, Q5 answered from public docs;
Q3 and Q4 (and follow-ups on Q1, Q2) go to Fireworks.**

## The goal

Members buy AI credits and spend them on cloud models served by Fireworks.
Per-user limits are enforced. The operator's server holds the least possible
information about what members do with the AI, and holds **no conversation
content at all**, so that neither a breach nor a search warrant nor a court
order to intercept going forward can recover what a member asked or was told.

## Threat model

| Threat | In scope | How it is met |
|---|---|---|
| Breach or seizure of the server's database, logs or backups | Yes | Nothing about content is ever written; usage is kept only as balances and coarse totals |
| A warrant for records the operator already holds | Yes | Same: there is nothing to hand over beyond balances and purchases |
| A court order compelling the operator to intercept **future** traffic | Yes | Content never passes through the operator's server, so there is nothing to tap by changing server code |
| A court order compelling the operator to ship modified client code | Partly | Only a signed native client with visible updates resists this (see § The client) |
| Fireworks itself seeing prompts | **No** | Owner handles this contractually with Fireworks |
| The payment processor knowing who bought credits | Accepted | It learns amounts, never content |

**Why a relay is not enough.** The mailbox relay pattern (operator box passes
traffic through) does not meet the third row: any design where plaintext
passes through code the operator runs can be changed, by rebuilding the box or
by order. Only two designs survive that:

1. **The content never touches the operator's server.** The member's device
   talks to Fireworks directly. This spec builds this one.
2. **The device verifies the server's code before trusting it** — an attested
   confidential-computing enclave (the Apple Private Cloud Compute pattern);
   a rebuilt box changes its measurement and devices refuse it. Heavy; recorded
   under § Alternatives, not built.

## What exists today

- `plugins/joinery_ai/includes/llm/FireworksProvider.php` — Fireworks as a
  provider, OpenAI-compatible, streaming. Requests carry only the operator's
  key and the conversation (`OpenAiCompatibleProvider.php`); no user identifier
  is sent. Usage (input/output/cached tokens) is read from the final stream
  chunk.
- The model catalog prices every model; recipe runs record `rcr_cost_estimate`.
- `includes/CostGuard.php` — a plugin-wide monthly token cap and a per-recipe
  cap. No per-member accounting. Zero-cost (local) usage is excluded.
- A member chat at `/profile/joinery_ai/chat` and an admin chat, sharing
  `joinery_ai_chat_page_logic()`. Every turn runs **on the server**
  (`chat_send_logic.php`) and every message is stored in
  `aim_conversation_messages`, optionally sealed with the member's vault key
  (`aim_sealed_*`). Both of those facts are incompatible with this spec's
  goal for the paid private mode.

## Design

### Credits, priced in money

One credit = a fixed amount of actual Fireworks cost × the operator's markup
(e.g. 1 credit = $0.001 of cost). Models differ in price per token and output
costs more than input, so a money-based unit means the same thing whatever
model the member picks. The charge for usage is catalog price × tokens used.

### Member-facing flow

1. The member's client asks the server for a key.
2. The server checks the member's **available** balance (balance minus open
   holds) covers the worst case for one key (below), records a hold for that
   amount, then creates a Fireworks API key via
   `POST /v1/accounts/{account_id}/users/{user_id}/apiKeys` with an
   `expireTime` a short window ahead, and returns it. Keys are created under a
   dedicated Fireworks service account (`firectl user create --service-account`)
   so they are isolated from the operator's own keys.
3. The client calls Fireworks directly with that key. The server never sees a
   prompt or a reply. Fireworks' inference API allows browser calls (CORS
   `Access-Control-Allow-Origin: *`, checked 2026-09-22), so a browser client
   is technically possible.
4. After each reply, the client reports the usage Fireworks returned in the
   response. The server takes that as a **provisional** charge against the
   hold, so an honest member's balance moves in real time.
5. Once Fireworks' per-key cost data for that key arrives (a day or more later,
   below), the server charges the **actual** cost, releases the rest of the
   hold, compares it with what the client reported, and forgets which member
   held the key.

### Enforcing limits

Fireworks keys carry no scope, spend cap or rate limit of their own; the only
fields are `displayName`, `expireTime` and `annotations`. Spend caps and rate
limits are account-wide (serverless: 6,000 requests/minute across the whole
account). So the hard ceiling for one key is:

    worst case = key lifetime × the fastest spend the account's limits allow

Fireworks' own cost data per key (`POST /v1/accounts/{account_id}/usageCosts:query`,
grouped by `API_KEY`) is in dollars but **lags live traffic by a day or more**
and is aggregated into daily buckets. It is the authoritative charge, not a
real-time control. Real-time control therefore rests on:

- **Short key lifetimes** (1–2 minutes; the client refreshes silently). This is
  the main lever, since it bounds what one key can spend.
- **Holds** sized to the worst case, kept until the actual cost arrives. They
  must survive a restart, so they are database rows keyed by Fireworks key ID,
  deleted at settlement.
- **Client-reported usage** as the provisional charge. Untrusted, but a client
  that under-reports is caught at settlement: its member is blocked from new keys
  when the actual cost exceeds what was reported beyond a tolerance.
- **Early revocation.** When a member's provisional usage reaches the balance,
  the server deletes the key (`POST .../apiKeys:delete` with `keyId`). Whether a
  deleted key stops working immediately is undocumented (Q2).
- **A per-member cap on keys issued per hour** stands in for a per-key rate limit,
  so one member cannot monopolise the account-wide rate limit.
- **The account-wide Fireworks spend cap** as the backstop against an accounting
  bug.

**The residual risk.** A malicious member with one valid key can fire parallel
requests at the account-wide rate until the key expires, and the true cost is
known only a day later. The operator's loss per such member is bounded by one
key lifetime of maximum-rate spend, less their prepaid balance. Short lifetimes
keep this small; only a per-key cap from Fireworks (Q4) removes it.

### What the server stores

| Data | Kept | Form |
|---|---|---|
| Balance | Yes | One number per member |
| Purchases | Yes | Needed for refunds and tax; the payment processor has them anyway |
| Usage history | Monthly total per member only | No per-call rows: a row per call is a trail of when and how much someone used the AI |
| Key → member mapping and hold | Until Fireworks' actual cost for the key arrives (a day or more) | One row per key: key ID, member, hold amount, provisional usage; deleted at settlement |
| Rate counters | Memory only (APCu/Redis with TTL) | Never in the database |
| Conversations, prompts, replies, tool results | **Never** | Held on the member's device |
| Web-server access log lines for the key endpoint | No | Access logging off for that endpoint, or the IP dropped |

The per-key rows are the one place usage timing is kept, and only for the day
or two before settlement. Fireworks' own records map each key to its cost
indefinitely, but not to a member: only the deleted mapping row did that.

Backups inherit all of this: what is never stored is never backed up.

### The client

The conversation history, the system prompt, memories and the agent loop all
move to the member's device. Consequences:

- **History lives on the device.** No second-device history and no recovery if
  the device is wiped. Cross-device sync, if wanted, is ciphertext the device
  encrypts with the member's own key; the server then holds unreadable blobs
  plus their timestamps and sizes.
- **Server-side tools leak by design.** A tool that runs on the server (Drive,
  mailbox, notes, memories) receives the model's tool call and returns a result
  through the server, so the server sees those arguments and results. In the
  private mode, either those tools are off, or they are accepted as the
  documented exception (the server already holds that member's mail and files,
  so it learns which item was asked for, not the conversation).
- **Web search** (Brave) needs a key the client must not hold, so it runs via
  the server and the server sees queries. Either off in the private mode, or
  charged to the same balance and disclosed as the exception.
- **The client code is the remaining weak point.** A web page the server
  delivers can be replaced by a version that copies what the member types. The
  guarantee holds only for a native app (or browser extension) with signed
  builds whose updates the member sees. A browser client is a weaker tier and
  must be labelled as such.

### Buying credits

A store product with a purchase hook
(`plugins/joinery_ai/hooks/product_purchase.php`, see
`plugins/store/docs/product_purchase_hooks.md`) adds credits to the balance.
A subscription tier may grant a monthly allowance through the same balance.
Unused-allowance rules are an owner decision (D2).

## Server-mode fallback

For members who do not use the private client (browser, or before the native
client ships), chat keeps running through the server with minimisation:

- Messages sealed with the member's vault key, required for paid use.
- Balance charged per call with the hold-then-settle flow: set aside the
  worst-case cost (prompt + reply-length cap × output price), cap the reply
  length to what the balance affords, charge real usage from the final stream
  chunk, release the rest; charge the whole hold if the stream breaks with no
  usage. Check before **every** model call in an agent loop, not once per
  message. Lock the member's balance row while placing a hold so two tabs
  cannot spend the same credit.
- No prompt or reply text in any log, including provider error paths that echo
  the request.

This mode does **not** meet the warrant goal and the UI must say so.

## Alternatives

- **Relay through the operator's box** (the mailbox relay pattern). Rejected:
  the operator controls the code, so a rebuild or an order defeats it.
- **Attested enclave** (AMD SEV-SNP / Intel TDX confidential VM running the
  relay; the client verifies the attestation before sending). Meets the
  warrant goal even for server-side tools, but is a large build with its own
  hosting constraints. Deferred.
- **Anonymous credit codes** (the Mullvad account-number pattern): credits are
  bought against the member account but spent through a random bearer code
  the AI endpoint knows only by balance. Unlinks usage from identity on the
  operator's side entirely. Catch: a lost code is lost credit, and it is a
  second system beside membership. Optional, only if anonymity is a selling
  point.
- **Local models** (the Mac Studio). The operator's own hardware sees content,
  so they are outside the warrant guarantee; they cost nothing per call.

## Questions for Fireworks

Researched 2026-09-22 against the public docs; what is still open goes to
Fireworks.

- **Q1 — Per-key usage through an API.** ANSWERED, with a catch:
  `POST /v1/accounts/{account_id}/usageCosts:query` returns dollar subtotals
  grouped by up to two of HOUR, DAY, MODEL, USER, API_KEY, over at most 31 days.
  But usage is aggregated into daily buckets and "can lag live traffic by a day
  or more". **Still to ask:** is there any near-real-time per-key usage (a
  stream, webhook, or a fresher endpoint)? A yes would replace client-reported
  provisional usage.
- **Q2 — Revoke a key early.** ANSWERED: `POST
  /v1/accounts/{account_id}/users/{user_id}/apiKeys:delete` with `{"keyId": …}`.
  **Still to ask:** does a deleted key stop working immediately, or after a
  cache interval?
- **Q3 — Limits on the number of keys or rate of creating them.** OPEN: nothing
  documented. At 1–2 minute lifetimes an active member needs ~30–60 keys an hour,
  so this must be asked.
- **Q4 — Per-key spend caps or rate limits.** OPEN: none in the public docs;
  keys have no scope, cap or rate limit. **Ask whether enterprise accounts can
  set them.** A yes removes the residual risk in § Enforcing limits.
- **Q5 — Browser calls (CORS).** ANSWERED by testing 2026-09-22: a preflight to
  `api.fireworks.ai/inference/v1/chat/completions` returns
  `Access-Control-Allow-Origin: *`, allowing `Authorization` and `Content-Type`.

With Q1 and Q2 answered the design works, carrying the residual risk above.
Q4 yes would remove the risk.

## Owner decisions

- **D1 — How members pay.** Prepaid packs (no loss on heavy users; some
  dislike topping up), a monthly allowance per tier (predictable revenue; needs
  unused-credit rules), or both (recommended: both are just additions to one
  balance).
- **D2 — Unused allowance.** Expires monthly, rolls over, or rolls over to a
  cap.
- **D3 — Server-side tools in the private mode.** Off, or on as a disclosed
  exception.
- **D4 — Whether a browser client is offered at all** for the private mode,
  given it cannot meet the guarantee.

## Work packages (after Q3, Q4)

- **WP1** — Balance and monthly-total storage, the purchase hook, the store
  product.
- **WP2** — Key issuance endpoint (`/api/v1`, session credential): balance
  check against the worst case, Fireworks key creation, per-member issuance
  limit, in-memory holds, access logging off for the endpoint.
- **WP3** — Settlement: provisional charges from client-reported usage, the daily
  `usageCosts:query` reconciliation by API_KEY, under-report detection, revoke
  on exhaustion, delete the key → member mapping.
- **WP4** — Private client: on-device history, system prompt, agent loop and
  key refresh; signed native build.
- **WP5** — Server-mode fallback metering (hold-then-settle, per-call checks,
  row lock) and the log audit for prompt text.
- **WP6** — Docs: `plugins/joinery_ai/docs/` page describing what the server
  holds in each mode, written for members as well as developers.

## Sources

- [Create API Key — Fireworks AI Docs](https://docs.fireworks.ai/api-reference/create-api-key)
- [firectl api-key create — Fireworks AI Docs](https://docs.fireworks.ai/tools-sdks/firectl/commands/api-key-create)
- [Account quotas — Fireworks AI Docs](https://docs.fireworks.ai/guides/quotas_usage/rate-limits)
- [Fireworks AI token spend integration — Ramp](https://support.ramp.com/connect-fireworks-ai-token-spend)
- [Delete API Key — Fireworks AI Docs](https://docs.fireworks.ai/api-reference/delete-api-key)
- [Fireworks AI Docs, full text (usageCosts:query, service accounts)](https://docs.fireworks.ai/llms-full.txt)
