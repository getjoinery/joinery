# Outgoing Webhooks

A webhook sends a signed HTTPS POST to a URL of the operator's choosing each
time a [signal](signals.md) it subscribes to fires: a sale, a signup, a
comment. It is how another system (a CRM, an automation service, a
self-hosted script) hears about what happens on the site without polling it.

Admins with permission 8 or above manage them at **System › Webhooks**
(`/admin/admin_webhooks`).

## How a signal becomes a request

```
SignalBus::dispatch('purchase.completed', $payload)
  → SignalDeliveryQueue::handle_signal()        (subscriber on "*", inline)
      one sgd_signal_deliveries row per active receiver that wants it
  → SignalDeliveryDrain task, every minute
      → SignalDeliveryQueue::drain()
          signed POST through SafeHttpClient
          2xx → delivered; anything else → retry later, or give up
```

The producing request does one cheap insert per interested receiver and
nothing else, as the [handler cost budget](signals.md#handler-cost-budget)
requires. The network call happens in the drain.

## The message

Messages follow the [Standard Webhooks](https://www.standardwebhooks.com/)
format, so a receiver can verify them with any of that project's libraries.

```
POST /your/endpoint HTTP/1.1
Content-Type: application/json
User-Agent: Joinery-Webhooks/1.0
webhook-id: msg_<24 hex characters>
webhook-timestamp: 1760400000
webhook-signature: v1,<base64 HMAC-SHA256>

{"type":"purchase.completed","timestamp":"2026-10-14T02:41:43Z","data":{"order_id":512,"amount":"25.00",...}}
```

| Part | Meaning |
|---|---|
| `type` | The signal name (see `signals.json` and plugin manifests for the catalog) |
| `timestamp` | When the signal fired, ISO-8601 UTC |
| `data` | The signal's payload exactly as dispatched |
| `webhook-id` | One id per dispatch, the same for every receiver of it and on every retry, so a receiver can drop a repeat |
| `webhook-timestamp` | When this attempt was sent, Unix seconds; a receiver should refuse one more than a few minutes old |
| `webhook-signature` | `v1,` + base64 of HMAC-SHA256 over `{webhook-id}.{webhook-timestamp}.{body}`, keyed with the bytes the secret's base64 part decodes to (the secret is `whsec_` + base64) |

Verifying by hand, in PHP:

```php
$key = base64_decode(substr($secret, strlen('whsec_')));
$signed = $_SERVER['HTTP_WEBHOOK_ID'] . '.' . $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] . '.' . file_get_contents('php://input');
$expected = 'v1,' . base64_encode(hash_hmac('sha256', $signed, $key, true));
$valid = false;
foreach (explode(' ', $_SERVER['HTTP_WEBHOOK_SIGNATURE']) as $candidate) {
    $valid = $valid || hash_equals($expected, $candidate);
}
```

The header may carry several space-separated signatures; one match is enough.

A **test message** (the button on a webhook's page) has the type
`webhook.test` and goes to that one webhook only. It is never dispatched on
the bus.

## Delivery, retries and giving up

- The drain runs every minute (`SignalDeliveryDrain`, activated on install)
  and stops starting new attempts after 45 seconds; what is left waits for
  the next run.
- A 2xx answer is delivered. Anything else, a redirect included, is a failure.
- After a failure the next attempt waits 1 minute, then 5 minutes, 30
  minutes, 2 hours, 6 hours, 12 hours and 24 hours
  (`SignalDelivery::RETRY_DELAYS`). After the eighth failed attempt, about two
  days on, the delivery is given up. A given-up delivery can be sent again
  from the webhook's page, which starts a fresh set of attempts.
- A webhook that cannot be reached at all (connection refused, timeout, a
  refused address) is not tried again in the same run, so one dead endpoint
  cannot spend the whole run.
- Delivery order is not guaranteed.
- A paused webhook gets nothing new queued, and its waiting deliveries hold
  until it is resumed.

Delivered and given-up rows are kept for `signal_delivery_retention_days`
(default 30, under Data retention in settings) and then removed by the
retention sweep. Rows still waiting are never removed.

## Withheld deliveries

A request that has opened sealed content may not send anything long out of
the box (see `SealedEgressGuard`). A signal raised in such a request is
recorded for each interested webhook as **withheld**: the row exists, so the
webhook's page shows that something was held back, but its payload is never
written and it is never sent.

## Security

- **HTTPS only.** A URL that is not `https://`, or that carries a username
  or password, is refused when saved and again when sent.
- **No internal addresses.** Every request goes through `SafeHttpClient`:
  the host is resolved and checked before a socket opens, the connection is
  pinned to the checked address, redirects are never followed, and only port
  443 or an unprivileged port (1024 and up) is allowed. DNS can change after
  a URL is saved, so this is checked on every attempt.
- **The signing secret is sealed** with [SecretBox](secret_box.md), declared
  in `settings.json` as the `sgr_signal_receivers.sgr_signing_secret`
  [sealed secret](sealed_secrets.md) of kind `regenerable-breaks-things`. If
  it can no longer be opened (the database moved to a site with another
  key), nothing is sent unsigned: each attempt fails with a message saying
  so, and the webhook's page offers a new secret.

## For developers

Two models:

- `SignalReceiver` (`sgr_signal_receivers`): where signals go. `sgr_signals`
  is a JSON array of signal names, or `["*"]` for all. `sgr_kind` says what
  kind of receiver it is: `webhook`, or `sandbox`, a sandboxed plugin named by
  `sgr_plugin` (made and removed with the plugin; see
  [Sandboxed Plugins](sandboxed_plugins.md)). The webhook pages show only the
  `webhook` kind.
- `SignalDelivery` (`sgd_signal_deliveries`): one signal owed to one
  receiver, with its status (`pending`, `delivered`, `failed`, `withheld`),
  attempts, last result and next attempt time.

`SignalDeliveryQueue::handle_signal()` queues for every active receiver
whatever its kind; `drain()` sends each due delivery by its receiver's kind: a
signed POST for a `webhook`, a call to the plugin host for a `sandbox`
receiver. Both share the queue, the retry rule and the retention.

`drain()` and `send_test()` take an optional client (anything with
`post($url, $body, $headers)` returning a `SafeHttpResponse`), which is how
`tests/functional/signal_delivery_test.php` drives them without a network.
