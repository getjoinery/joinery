# Server Manager — node_detail.php structural extraction

**Status:** IMPLEMENTED — built + live-verified on dev 2026-07-23, exercised throughout the 1.0 live-testing round and the pre-commit review (all 18 actions confirmed behind the single CSRF gate). Pulled out of `server_manager_1_0_hardening.md` so the 1.0 security work could land in place first.

**What landed:** `views/admin/node_detail.php` is now a ~130-line shell (node load, tab whitelist, one dispatch call, header/nav, one `require` of the tab partial). The 18 POST handlers moved to `logic/node_detail_actions_logic.php` (`NodeDetailActions::dispatch()`) — CSRF validated once ahead of every action (all 18 forms now carry `SmAdminCsrf`), uniform try/catch (R-3, no 500 from a builder throw), and it returns a redirect URL rather than calling `exit()` (logic-file contract). Six tab partials under `includes/node_detail_tabs/` (not `views/`, so they 404 as URLs). `ManagementJob::latestForNode()` replaced the 7 copy-pasted "newest job of type X" queries (+ db-tier test). Shared `assets/js/server_manager.js` (`smApiPost`/`smEsc`/`smSafeUrl`, emitted via `SmAssets::script_tag()`) replaced the 4 inline copies across node_detail/index/node_add/job_detail; `smApiPost` now rejects with a typed error instead of resolving `{}` (U-3) and the backups poll retries with a cap then shows a reload notice. `smNodeName` is defined once in the shell (it was undefined on the Updates tab in the monolith — latent broken Apply Update button, now fixed). U-5: one `ManagementJob::filterTypes()`/`databaseOpTypes()` source feeds all three job-type lists. New tests: `management_job_latest_for_node` (8), `node_detail_actions_csrf` (7). Full server_manager: safe 36/36, db 503/503; full safe tier 57/57. Live: all 6 tabs render 0-error, partial URLs 404, Check Status click created job #907 and redirected (CSRF accept path end-to-end).

**Why separate:** every Phase 3 *security* fix (CSRF, XSS, secret handling, sort whitelisting) already landed directly in `node_detail.php` and the sibling views — none of them needed the split. What remains here is organizational refactor plus two correctness bugs (R-3, U-3) that are cleanest to fix once the structure exists. It is high-churn on the busiest page in the plugin and every tab/action needs per-flow browser re-verification, so it is isolated from the security package.

## Current state

`views/admin/node_detail.php` is ~2,059 lines and does everything for the node page in one file:

- **18 POST action handlers** inline at the top (`:88`–`:509`): `check_status`, `backup_database`, `backup_project`, `escrow_backup_key`, `copy_database`, `copy_database_local`, `restore_database`, `restore_project`, `apply_update`, `apply_update_all_on_host`, `retry_install`, `provision_ssl`, `run_plugin_installers`, `set_reverse_dns`, `save_api_credential`, `clear_api_credential`, `save_node`, `delete_node`.
- Node loading + tab whitelist + message handling.
- All 6–7 tab renderings (overview, database, backups, updates, jobs, api_keys).
- A large inline `<script>` block.

## Target structure

1. **POST action dispatch → `logic/node_detail_actions_logic.php`**, joining the existing `backup_actions_logic.php` pattern, with a dispatch table `action => [job_type, param_extractor, builder]`.
   - **R-3 (correctness):** error handling is inconsistent today — the `check_status` handler has no try/catch and 500s if the builder throws, while `restore_project` catches. The table gives every action uniform try/catch → user-facing error message, no 500s.
   - **CSRF (closes the 1.0 posture, not just preserves it):** today only 2 of the 18 handlers validate `SmAdminCsrf` — `escrow_backup_key` (`:118`) and `delete_node` (`:510`), the GET→POST conversions from the 1.0 work. The other 16 POST handlers have **no server-side CSRF validation**: FormWriter emits a `_csrf_token` field but `validateCSRF()` is never called by any real handler (SameSite=Lax is the only current mitigation). This was deliberate — the hardening spec's decision 2 defers blanket validation to "a single dispatch point (the Phase 3 logic extraction)", i.e. **here**. The dispatch table validates `SmAdminCsrf` once, before the table lookup, covering all 18 actions; that is what satisfies the 1.0 acceptance "CSRF enforced on every plugin POST". (The other pages' handlers — `targets.php`, `publish_upgrade.php`, `job_detail.php`, etc. — already carry their own `SmAdminCsrf` guards; leave them.)
2. **One tab = one partial** under `includes/node_detail_tabs/{overview,database,backups,updates,jobs,api_keys}.php`, included by the shell via `PathHelper::getIncludePath()`. A thin shell (`views/admin/node_detail.php`) owns node loading, the tab whitelist, and messages.
   - **Partials must NOT live under `views/`.** Plugin view auto-discovery routes multi-segment URLs to nested files (`RouteHelper.php:1366-1373`), so a partial at `views/admin/node_detail/overview.php` would be directly reachable at `/admin/server_manager/node_detail/overview` — bypassing the shell's node loading and its `check_permission(10)` gate (the serve.php `/admin/*` route only enforces permission 5). Placing them under `includes/` keeps them out of the routable tree entirely; no per-file guards needed.
3. **`ManagementJob::latestForNode($node_id, $type[, $columns])`** replaces the copy-pasted "newest job of type X" query — 7 near-identical copies in this file (`:292, :593, :603, :624, :951, :963, :1553`; the `MultiManagementJob` list uses at `:1122, :1774` are recent-jobs lists, not this pattern — leave them). Unit-testable in isolation; safe to land first, independent of the rest.
4. **Shared plugin JS asset.** `smApiPost` is duplicated 4× (`node_detail.php:1560`, `index.php:487`, `node_add.php:172`, `job_detail.php:280`); the HTML-escape helper exists inconsistently under two names (`smEsc` at `node_detail.php:1543`, `esc` at `node_add.php:180` — `index.php` and `job_detail.php` have none). Move both to one asset, one name.
   - **U-3 (correctness):** change the JS error contract to reject/return a **typed error object** instead of `{}` (the soft-failure shape at `node_detail.php:1561-1563`). That `{}` is the root cause of the backups tab's frozen polling and reload-loop (it fakes "Scan complete" + reload, minting duplicate `list_backups` jobs) and the literal `"undefined"` UI strings rendered from `data.message` at `node_detail.php:1579`, `:1685`, `node_add.php:159`. On API/transport error: retry with capped backoff (~5) then show a visible "polling stopped — reload" notice.

## Coupled cleanups to fold in

- **U-5:** the job-type lists are hardcoded in three places — the recent-DB-ops PHP filter (`node_detail.php:1786`, an `in_array` over a loaded jobs list) and the two filter dropdowns (`node_detail.php:1903`, `jobs.php:93`, which also has `publish_upgrade`). Generate all of them from one shared PHP array (with the per-context subsets derived from it, not re-listed).

## Out of scope (stay in the hardening spec / other phases)

- U-2 (HTML-escaped filename reused as posted data), U-7 (`new ManagementJob`/`ManagedHost` redirect-on-missing) — standalone minors, no dependency on the split.

## Acceptance

- Every tab renders and every one of the 18 actions works, re-verified in the browser on dev (hostile-name node still inert — already covered by the 1.0 injection suite).
- Tab partial URLs do not resolve: `/admin/server_manager/node_detail/overview` (and each other tab name) returns 404, not a rendered fragment.
- The three sibling pages that switch to the shared JS asset — `index.php`, `node_add.php`, `job_detail.php` — get a smoke check of their polling/action flows.
- `ManagementJob::latestForNode()` unit test (db tier): returns the newest non-deleted job of a type for a node, null when none.
- Builder-throw on any action yields a user-facing error, never a 500 (R-3).
- POST without a valid CSRF token is rejected for every one of the 18 actions; with the token, accepted (harness test against the dispatch — this is the 1.0 hardening's "CSRF enforced on every plugin POST" acceptance).
- Backups-tab polling on a transport error shows the reload notice and does not mint duplicate `list_backups` jobs (U-3).
