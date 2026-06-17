# Inbound Email — Full-Text Search (GIN Index)

## Problem

The Mailbox Reader's search box runs four independent `ILIKE '%term%'` scans
(`iem_sender`, `iem_subject`, `iem_body_plain`, `iem_body_html`) in
`MailboxService::listThreads()` (`plugins/inbound_email/includes/MailboxService.php:417`).
A leading-wildcard `ILIKE` cannot use a B-tree index, so every search is a
sequential scan of the message table, including the two `text` body columns.
This is acceptable at current volume but degrades linearly as stored mail grows,
and it is the one hot path in the reader with no index behind it.

PostgreSQL full-text search fixes this with a GIN index over a `to_tsvector(...)`
expression. The platform already uses FTS at query time elsewhere
(`data/users_class.php` filters on `to_tsvector('english', ...) @@ to_tsquery(...)`),
so this is an established pattern, not new infrastructure.

## Approach: expression index, no schema-system changes

The index is an **expression GIN index created in a migration** — the same
mechanism every other non-unique index in this plugin uses (`iem_001`, `iem_005`
in `plugins/inbound_email/migrations/migrations.php`). Deliberately **not** a
materialized `tsvector` column, because:

- An expression index is computed from columns that already exist, so there is
  **no new column, no backfill, and no write-path change**.
- The auto-updater (`update_database`) never sees a `tsvector` type. A physical
  `tsvector` column would force a change to `DatabaseUpdater::translateDataTypes()`
  (`includes/DatabaseUpdater.php:1703`), which has no `tsvector` case and would
  print `ERROR: Unrecognized data type` on the next sync when it introspects the
  column. The expression-index route avoids that entirely.

The cost of the expression-index route is the one hard rule below.

### The hard rule: the index expression and the query expression must be byte-identical

PostgreSQL only uses an expression index when the query's predicate uses the
**exact same expression** — same column order, same `coalesce()` wrapping, same
`'english'` regconfig literal (it must be a constant, never a column). The
single source of truth for this expression is defined once in this spec and used
verbatim in both the migration and `MailboxService`.

**The canonical search expression:**

```sql
to_tsvector('english',
       coalesce(iem_sender, '')      || ' ' ||
       coalesce(iem_subject, '')     || ' ' ||
       coalesce(iem_body_plain, '')  || ' ' ||
       coalesce(iem_body_html, ''))
```

All four searchable fields are folded into one vector so a single GIN index
covers the whole search box and the planner always has an index to use. (Sender
becomes lexeme-matched rather than substring-matched; per the feature owner,
search semantics are explicitly out of scope for this change.)

## Goals

- A GIN expression index accelerates the reader's text search, replacing the
  four sequential `ILIKE` scans with one indexed `@@` predicate.
- Zero changes to `update_database` / `DatabaseUpdater`. The index is created in
  a migration, consistent with `iem_001`/`iem_005`.
- The search box behaves as one unified query over sender + subject + both
  bodies, matching the current "one box searches everything" UX.

## Non-Goals

- **No materialized `tsvector` column.** Reconsider only if relevance ranking
  (`ts_rank`) per query becomes a measured cost; that is a separate spec.
- **No schema-system capability work.** No declarative index support, no
  `tsvector` type mapping, no generated-column support is added here.
- **No search-semantics tuning.** Stemming language, prefix matching, and
  ranking are out of scope. The change preserves "type a term, get matching
  threads"; exact match nuances (substring vs. lexeme) are accepted as-is.
- **No encryption-at-rest interaction.** A plaintext `tsvector` index would leak
  body content; this spec assumes bodies remain stored in plaintext. If
  encrypted-at-rest storage is later adopted, this index must be revisited (a
  blind index, not FTS).

## Work

### 1. Migration — create the GIN index

Append `iem_007` to `plugins/inbound_email/migrations/migrations.php`, version
`1.17.0`, mirroring the `iem_001`/`iem_005` index migrations (plain
`CREATE INDEX IF NOT EXISTS`, no `CONCURRENTLY` — consistent with the existing
index migrations and safe at current table size):

```php
[
    // Full-text search index for the Mailbox Reader (specs/inbound_email_fulltext_search.md).
    // The auto-updater does not create non-unique indexes, so the GIN index over
    // the canonical search expression is created here. The expression MUST stay
    // byte-identical to the one MailboxService::listThreads() filters on, or the
    // planner will not use the index.
    'id' => 'iem_007_fulltext_search_index',
    'version' => '1.17.0',
    'up' => function($dbconnector) {
        $dblink = $dbconnector->get_db_link();
        $dblink->exec(
            "CREATE INDEX IF NOT EXISTS iem_fulltext_idx
             ON iem_inbound_email_messages
             USING GIN (to_tsvector('english',
                    coalesce(iem_sender, '')      || ' ' ||
                    coalesce(iem_subject, '')     || ' ' ||
                    coalesce(iem_body_plain, '')  || ' ' ||
                    coalesce(iem_body_html, '')))"
        );
    },
],
```

Bump the file header `@version` in `migrations.php` and the `version` in
`plugins/inbound_email/plugin.json` (`1.16.0` → `1.17.0`).

### 2. Query rewrite — `MailboxService::listThreads()`

Replace the four `ILIKE` branches at
`plugins/inbound_email/includes/MailboxService.php:416-430` with a single FTS
predicate driven by one `q` filter. Use `websearch_to_tsquery` (tolerant of
arbitrary user input — quotes/operators won't raise), and skip the predicate
entirely when `q` is empty (preserving today's "no term = no filter" behavior):

```php
// Row-level full-text filter: a thread shows if any message matches.
// Expression MUST match iem_007's index expression byte-for-byte.
if (!empty($filters['q'])) {
    $where[] = "to_tsvector('english',
            coalesce(iem_sender, '')      || ' ' ||
            coalesce(iem_subject, '')     || ' ' ||
            coalesce(iem_body_plain, '')  || ' ' ||
            coalesce(iem_body_html, ''))
        @@ websearch_to_tsquery('english', ?)";
    $params[] = $filters['q'];
}
```

The `likeEscape()` helper is no longer needed for search (it stays if used
elsewhere; otherwise remove it). The thread aggregation, `HAVING`
(unread/starred), folder dimension, and read-scope SQL are unchanged — this only
swaps the text-match branch.

### 3. Endpoint — collapse the three params to one

In `plugins/inbound_email/ajax/mailbox_list.php:28`, replace the separate
`sender`/`subject`/`body` filter keys with a single trimmed `q`:

```php
'q'            => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
```

Keep `unread_only` / `starred_only` as-is.

### 4. Frontend — send one `q`

In `plugins/inbound_email/assets/mailbox_reader.js:194`, replace the three
param sets with one:

```javascript
if (state.search) { p.set('q', state.search); }
```

The 300ms debounce and the rest of the reader are unchanged.

### 5. Test

`listThreads()` has exactly two callers: `ajax/mailbox_list.php` (production) and
`plugins/inbound_email/tests/mailbox_reader_test.php`. The test's search case
(`mailbox_reader_test.php:303`) passes the now-removed `subject` filter key:

```php
$res = $svc->listThreads(null, array('subject' => 'invoice'), 1, 50);
```

Update it to the single `q` key (`array('q' => 'invoice')`). Without this change
the test would run against an unfiltered result set and silently stop exercising
search. No other caller references the old `sender`/`subject`/`body` keys, so
collapsing to `q` is otherwise safe.

### 6. Docs

Update the Mailbox Reader search description in
`plugins/inbound_email/docs/overview.md` (current state only — no "previously
used ILIKE" narration per the docs rule): describe search as a PostgreSQL
full-text query over sender, subject, and both body fields, backed by the
`iem_fulltext_idx` GIN index.

## Verification

- Run `update_database` from admin utilities (its final step syncs plugins and
  runs pending migrations); confirm `iem_007` applies and `iem_fulltext_idx`
  exists: `\d iem_inbound_email_messages`.
- Confirm the planner uses it: `EXPLAIN` a reader search and verify a
  `Bitmap Index Scan on iem_fulltext_idx` rather than a `Seq Scan`. (On a tiny
  dev table the planner may still pick a seq scan; test against a populated
  mailbox or `SET enable_seqscan = off` to confirm the index is usable.)
- Exercise the reader search box: a term present only in a body, only in a
  subject, and only in a sender each returns the expected thread.
- `php -l` and `validate_php_file.php` on the modified `MailboxService.php`,
  `mailbox_list.php`, and `migrations.php`.
