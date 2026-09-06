# Bounce and complaint handling — finding out when a hosted site's mail fails

**Status:** Draft, 2026-09-06. Owner decisions taken: **one webhook, registered
once against the master account, the same address for every node**; and
**events are stored on receipt and processed afterwards**, not handled inside
the request. Companion to `hosted_trial_provisioning.md` §4.3 and §6, which
this replaces the inline-counting half of.

## 1. What this is for

A hosted customer's site sends email through the operator's mail provider. When
one of those messages bounces, or a recipient marks it as spam, the provider
tells us. Three people care, for different reasons:

- **The customer**, because their mail is not arriving and nobody has told them.
- **The operator**, because a customer sending mail that bounces or gets
  reported is how a shared sending reputation is destroyed — and the operator's
  other customers pay for that.
- **Nobody, most of the time.** The overwhelmingly common case is a handful of
  bounces from mistyped addresses, which is noise and must stay noise.

So this is not a delivery-reporting feature. It is the smallest thing that
notices a customer whose sending has gone wrong, tells them, and can prove
afterwards what it saw. Stopping their sending is the provider's job, not
this platform's (owner, 2026-09-06 evening): SMTP2GO's monthly limit and its
own abuse controls act on the customer's subaccount, and nothing here removes
a credential on the strength of a webhook count.

## 2. Decisions taken

- **One webhook, one address, registered by hand once** (owner, 2026-09-06).
  Not one per customer. The endpoint is shared by every hosted site and works
  out which customer an event belongs to from the event itself.
- **Receipt and processing are separate** (owner, 2026-09-06). The endpoint
  stores and answers; a scheduled task does the work. Reasons in §4.
- **No suppression list.** The provider maintains its own and will not deliver
  to an address it has suppressed. A second list here would have one consumer,
  could disagree with the provider's, and would be the copy that goes stale.
- **Counting is per calendar month**, matching the allowance the customer is
  shown and the window the provider enforces its cap over.

## 3. Receiving

`POST /ajax/smtp2go_webhook`, the same URL for every customer.

The endpoint does four things and nothing else: check the caller, decode the
body, write a row per event, answer 200.

**Checking the caller.** These webhooks are unsigned — the provider offers only
basic-auth on the URL and a published sending address range — so both are
required, and a deployment with no configured secret refuses every delivery
rather than accepting anonymous ones. An open counter looks like evidence and is
not. A refusal is logged with the source address, because the alternative is a
silent 403 and a counter that mysteriously stays at zero.

**Answering 200 once authenticated, always.** A provider that receives an error
retries, and a retry of an event already stored would be a second copy of the
same fact. Storage failures are logged, not returned.

**What a stored event is.** One `wbh_webhook_logs` row per event, provider
`smtp2go`, carrying the provider's own event id, the event type, the whole
payload, and `wbh_processed = FALSE`. The existing model already has every one
of those columns and a duplicate check; this needs no table of its own.

**Duplicates are dropped on the event id.** Where the provider sends no usable
id, the id is a hash of the payload — two identical deliveries are the same
event, and counting one twice moves a customer toward a suspension they did not
earn.

## 4. Processing

A scheduled task claims unprocessed `smtp2go` rows oldest-first, in batches, and
marks each one processed or failed.

**Why this is not done in the request.** Four reasons, each of which has bitten
somebody:

1. A webhook must answer quickly or the provider retries it. Suspending a
   customer, calling the provider to remove an SMTP user and sending an operator
   alert are not fast, and doing them inline makes the retry more likely exactly
   when the work is heaviest.
2. Work done inline is work repeated on every retry. Stored first, an event is
   counted once whatever the provider does.
3. The action a threshold triggers reaches out to the provider and can fail. A
   failure inside the request either loses the event or returns an error that
   causes a redelivery; a failure in the task leaves the row unprocessed and it
   is tried again.
4. The raw payload survives the decision, so "why was this customer suspended"
   is answerable afterwards from what actually arrived.

**Matching an event to a customer** is by the sending credential the event names
(`auth`, the SMTP username), then by subaccount id. Never by the recipient or
the sender address: in the spoofed case those are attacker-supplied, and the
whole point of matching on the credential is that a customer's events are
attributable to the credential we issued them.

**An event that matches nobody is not an error.** It is stored, marked processed
with a note, and counted. A handful means a customer was deprovisioned while
mail was in flight. A steady stream means something is wrong with the matching
itself, and §6 covers it.

**What processing does per event:** roll the customer's counters if the calendar
month has turned, increment sent / bounce / complaint, and evaluate the
thresholds. Suspension itself — removing the SMTP user, raising the operator
signal, putting the sentence on the customer's banner — is the behaviour already
built in `HostedTrialWatch`; this leg feeds it accurate numbers rather than
reimplementing it.

## 5. Thresholds — removed

~~A complaint rate above 0.1% or a bounce rate above 5%, over a minimum of 100
sends, removed the customer's SMTP user.~~ **Struck 2026-09-06 (owner).** The
platform keeps no threshold of its own and removes nothing. What the stored
events feed instead: the customer's banner (their mail is bouncing, and why),
the operator's view of a subaccount the provider has acted on, and the audit
trail of §7. Webhooks can be spoofed or dropped, and that is now entirely
acceptable because nothing consequential hangs off them.

## 6. The failure this must not have

**No events arriving at all**, because the webhook was never registered, or was
registered in a way that does not cover subaccount sends, or its secret was
rotated on one side only.

Nothing errors. Every customer's usage reads zero, every allowance banner reads
zero, and the complaint threshold never fires — so the abuse control that exists
to stop a spammer never runs, and the first anybody knows is the provider
suspending the master account.

**The detector:** the plane knows independently how many messages a subaccount
has sent, because the provider's own API reports it for the allowance banner. A
site whose provider-reported sends are climbing while no webhook event has ever
been recorded for it is the signature of this failure. One operator alert, once,
naming it — not per site, and not repeated.

The same check catches the opposite: events arriving that match no customer.

## 7. Retention

`wbh_webhook_logs` has no pruning today and grows without limit; at a thousand
customers sending a thousand messages a month that is a million rows a month.
Processed `smtp2go` rows older than 90 days are deleted by the same task.

Ninety days because the shortest question these answer is "why was this customer
suspended last month", and the longest is a provider dispute. Unprocessed rows
are never pruned — one that could not be processed is the thing worth keeping.

## 8. Out of scope, deliberately

- **A delivery log for the customer.** Showing somebody which of their messages
  bounced and why is a real feature and a different one: it needs a page, a
  search, and a per-message record rather than a counter. What the customer gets
  here is the aggregate and the sentence explaining a suspension. Worth building
  when a customer asks; not worth guessing at now.
- **Suppression.** §2.
- **Non-hosted sites.** A site sending through its own provider gets its
  provider's own reporting, and this operator is not in that path.

## 9. What changes

Already built (`hosted_trial_provisioning.md`): the endpoint, its basic-auth and
address checks, the credential-based matching, the counters on `htr_hosted_trials`,
the thresholds, and the suspension behaviour they trigger.

This spec changes:

1. **The endpoint stores instead of counting.** One `wbh_webhook_logs` row per
   event, `wbh_processed = FALSE`; the counting moves out.
2. **A new scheduled task** consumes them, applies the counters, and prunes.
3. **The silent-zero detector** in §6.
4. **`hosted_tier.md`** gains the webhook registration steps as operator setup —
   the URL, the secret, and the fact that it is created once by hand.

## 10. Open

- **Does a master-account webhook receive subaccount events?** The whole design
  above assumes yes, which is what the owner has decided to build for. If the
  provider turns out to require one per subaccount, the endpoint, the storage and
  the processing are all unchanged — what changes is that registration becomes a
  provisioning step (a client method plus one leg state) rather than operator
  setup. Settle it with one test send through a subaccount before the first real
  customer, and the detector in §6 is the backstop if it is missed.
