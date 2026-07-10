# Signal Bus

The signal bus is the platform's canonical "something happened" primitive.
`SignalBus::dispatch($signal, $payload)` records a structured fact at the point
it occurs and fans it out to every registered subscriber — notifications,
(future) outgoing webhooks, email workflows, analytics, and plugin handlers.
Each subscriber derives its own presentation and side effects from the payload.

Notifications are the first subscriber; see [Notifications](notifications.md)
for that consumer specifically.

## Concepts

- **Signal** — a named fact that already happened, e.g. `purchase.completed`.
  Names follow a `noun.verb` taxonomy. Declared once in `signals.json`, dispatched
  wherever the fact occurs. Signals are immutable facts: a subscriber cannot veto
  a signal or mutate its payload for other subscribers.
- **Structured payload** — a flat associative array of JSON-serializable scalars
  (entity ids, names, amounts, ISO-8601 UTC times). Never objects, resources, or
  pre-rendered HTML. The payload is the machine-readable record; every consumer
  reads it directly.
- **Subscriber** — a class with a static handler `($signal, $payload)`,
  registered in `signal_subscribers.json` (core) or a plugin's `signalSubscribers`
  key. A subscriber matches exact signal names or `*` (everything).

## The dispatch contract

`SignalBus::dispatch(string $signal, array $payload = array()): void`

- **Never throws into the caller.** The whole dispatch and each subscriber are
  wrapped in try/catch; one failing subscriber is logged and the rest still run.
- **Synchronous and ordered.** Handlers run inline — core subscribers in
  `signal_subscribers.json` file order, then plugin subscribers in active-plugin
  load order. No priority system.
- **Session-less safe.** Dispatch happens from web requests, webhook endpoints,
  and CLI scheduled tasks. Neither the bus nor any handler reads the session;
  everything a handler needs is in the payload (`source_user_id` is an explicit
  payload key, never `$_SESSION`).
- **Post-commit only.** Where the producing operation runs inside a DB
  transaction (checkout), dispatch after the transaction commits. Subscribers may
  assume the fact is final.
- **Undeclared signals dispatch anyway.** Dispatching a signal missing from the
  catalog logs a warning and still invokes matching subscribers. The catalog is
  the contract for *consumers enumerating signals*, not a dispatch gate.
- **Lazy loading.** A subscriber's file is `require_once`d only when one of its
  signals actually fires.

### Handler cost budget

Handlers run inline in the producing request — including checkout. Inline work
must be bounded to cheap local writes (an insert into your own queue/work table).
Network calls, bulk sends, and heavy queries belong in a scheduled-task drain.
Notify models this: it inserts the in-app notification row inline and enqueues
email to `equ_queued_emails` for the `SendQueuedEmails` task to deliver.

### Debugging

The core setting `signal_bus_debug` (default off) makes the bus log each dispatch
(signal name + JSON payload) to the error log and flag any non-JSON-serializable
payload value. It is debug-only, so production dispatch never pays for the check.
Keeping payloads JSON-serializable is the single most important discipline —
it is what lets webhooks and email workflows ship the payload as-is.

## Payload conventions

- Entity references are `<entity>_id` integers: `user_id`, `order_id`,
  `product_id`, `event_id`, `comment_id`. A handler needing more loads the model
  by id.
- `source_user_id` — the user whose action caused the signal, when there is one.
  Consumers use it for "don't notify the actor" logic and attribution.
- Times are ISO-8601 UTC strings (`gmdate('Y-m-d H:i:s')`).
- Human-derived convenience fields (`buyer_name`, `product_name`,
  `comment_excerpt`) are encouraged where every consumer would otherwise
  re-derive them, but they supplement the ids, never replace them. Compute them
  at the call site, which already holds the loaded models.
- Money is a decimal-string `amount` plus a separate `currency`.

## Declaring signals: `signals.json`

`signals.json` at the `public_html/` root declares every core signal. Plugins
declare their own signals under a `signals` key in `plugin.json`, same shape.
`SignalBus::signals()` merges core + active plugins and caches per request.

Each entry declares identity (`label`, `description`, `category`), the payload
schema, and optionally a `notify` block (read only by the Notify subscriber — the
bus never looks at it):

```json
"purchase.completed": {
    "label": "Sale completed",
    "description": "A purchase or order was completed successfully.",
    "category": "Orders",
    "payload": {
        "order_id": "Order id",
        "user_id": "Buyer user id",
        "product_name": "Product display name",
        "amount": "Order total, decimal string",
        "currency": "Configured site currency"
    },
    "notify": {
        "ntf_type": "order",
        "supports_topic": true,
        "default_email": true,
        "title_template": "Sale completed: {product_name}",
        "body_template": "Order #{order_id} completed.",
        "link_template": "/plugins/store/admin/admin_orders"
    }
}
```

- **`payload`** is an advisory schema: field name → one-line description. It is
  documentation and a merge-field source for consumer UIs. It is not validated at
  dispatch except under `signal_bus_debug`.
- **`notify`** is consumed only by Notify — see
  [Notifications](notifications.md). A signal with no `notify` block produces no
  notifications; it exists for other subscribers.

**Never rename a signal in place** — its name is the contract with subscriber
declarations, stored `ntp_signal_name` preference rows, and future webhook
subscriptions. To rename, introduce the new name and deprecate the old.

## Registering subscribers

### Core: `signal_subscribers.json`

An ordered map of subscriber name → declaration at the `public_html/` root:

```json
{
    "notify": {
        "file": "includes/Notify.php",
        "class": "Notify",
        "method": "handle_signal",
        "signals": ["*"]
    }
}
```

- `file` — path relative to `public_html/`, resolved through
  `PathHelper::getIncludePath()` and required lazily on first matching dispatch.
- `class` / `method` — a static method `(string $signal, array $payload): void`.
  `method` defaults to `handle`.
- `signals` — array of exact names or `"*"` (everything).

### Plugins: `signalSubscribers` in `plugin.json`

Same shape, with `file` relative to the plugin directory. Read for active plugins
only, merged after core. This is the plugin extension seam: a plugin reacts to a
core signal by declaring a subscriber — no core edits. Plugins may also declare
and dispatch their own signals.

The signal catalog and subscriber registry are **code-bound developer config** —
they ship in the repo, are edited in source, and deploy through the upgrade
pipeline (like `admin_menus.json`, `theme.json`, `plugin.json`). Never hand-edit
`signals.json` / `signal_subscribers.json` on a deployed site; a site needing its
own signals or subscribers ships a plugin. Runtime-mutable state (per-user
preferences, future webhook URLs) lives in DB tables, never in these files.

## How to add a signal

1. **Declare** it in `signals.json` (identity + `payload` schema).
2. **Dispatch** it where the fact occurs:

   ```php
   require_once(PathHelper::getIncludePath('includes/SignalBus.php'));

   SignalBus::dispatch('comment.posted', array(
       'comment_id'      => $comment->key,
       'post_id'         => $post->key,
       'post_title'      => $post->get('pst_title'),
       'post_url'        => $post->get_url(),
       'comment_excerpt' => mb_substr(strip_tags($comment_body), 0, 180),
       'author_name'     => $comment->get('cmt_author_name'),
       'source_user_id'  => $comment->get('cmt_usr_user_id'),
   ));
   ```

3. **Optionally make it notifiable** by adding a `notify` block to its entry — see
   [Notifications](notifications.md).
