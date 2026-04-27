# Specification: Upgrade All Sites on This Host

## Overview
Adds a single button to the Server Manager node detail page (Updates tab) that fans out an `apply_update` job to every sibling node sharing the same `mgn_host`. Reuses the existing per-site upgrade machinery; no new infrastructure.

## Why
Today, upgrading all sites on docker-prod means clicking "Apply update" on each of ~8 node pages individually. Operators want one click that does the whole host.

## Scope

**In scope:**
- One new action handler in `views/admin/node_detail.php`
- One new button on the Updates tab
- Reuse of `JobCommandBuilder::build_apply_update($node)` per sibling node
- Reuse of `ManagementJob::createJob()` per sibling node
- Redirect to a Jobs view filtered to the just-created jobs

**Out of scope:**
- Any new builder method (we call the existing one in a loop)
- Any change to `JobCommandBuilder.php` or the Go agent
- Consolidated single multi-step job (we create N independent jobs — simpler)
- Per-host include/exclude filters (operator can enable/disable specific nodes via existing `mgn_enabled` flag if they need to skip one)
- Bulk-run summary view (existing Jobs page is enough; revisit if it feels rough)
- Any change to bare-metal/non-docker hosts — sites that aren't a node aren't touched
- A bash CLI wrapper (operators can SSH in and run `docker exec <site> php utils/upgrade.php` per site for the rare recovery case)

## File changes

**Only one file touched:** `plugins/server_manager/views/admin/node_detail.php`

### Action handler

Alongside the existing `action === 'apply_update'` block (around line 179), add:

```php
if ($action === 'apply_update_all_on_host') {
    $params = ['dry_run' => !empty($_POST['dry_run'])];

    // Resolve sibling nodes: same host, enabled, not deleted.
    $siblings = new MultiManagedNode(
        ['host' => $node->get('mgn_host'), 'enabled' => true, 'deleted' => false],
        ['mgn_slug' => 'ASC']
    );
    $siblings->load();

    if ($siblings->count() === 0) {
        // Should never happen — at minimum the current node qualifies.
        $session->save_message(new DisplayMessage(
            'No eligible sites found on this host.', 'Error', $page_regex,
            DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
        ));
        header('Location: ' . $base_url . '&tab=updates');
        exit;
    }

    $job_ids = [];
    foreach ($siblings as $sibling) {
        try {
            $steps = JobCommandBuilder::build_apply_update($sibling, $params);
            $job = ManagementJob::createJob(
                $sibling->key, 'apply_update', $steps, $params, $session->get_user_id()
            );
            $job_ids[] = $job->key;
        } catch (Exception $e) {
            // Don't abort the whole fan-out on a single failed createJob.
            // Surface what we got and keep going.
            error_log("apply_update_all_on_host: failed to queue node {$sibling->key}: " . $e->getMessage());
        }
    }

    if (empty($job_ids)) {
        $session->save_message(new DisplayMessage(
            'No jobs were queued.', 'Error', $page_regex,
            DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
        ));
        header('Location: ' . $base_url . '&tab=updates');
        exit;
    }

    // Redirect to the Jobs index. The just-queued jobs will be at the top.
    header('Location: /admin/server_manager?tab=jobs');
    exit;
}
```

### Button

In the Updates tab section (near the existing `apply_update` form around line 1291), add a second form:

```html
<form method="POST" action="<?= htmlspecialchars($base_url) ?>"
      onsubmit="return confirm('Queue an upgrade job for every site on this host?');">
    <input type="hidden" name="action" value="apply_update_all_on_host">
    <button type="submit" class="btn btn-warning">
        Upgrade All Sites on This Host
    </button>
    <p class="text-muted small">
        Creates one upgrade job per enabled site that shares this host
        (<code><?= htmlspecialchars($node->get('mgn_host')) ?></code>).
        Jobs run independently and in parallel as the agent picks them up;
        a failure in one site does not affect others.
    </p>
</form>
```

The button sits just below the existing single-site "Apply update" form. Visual distinction: warning color (vs. the standard primary color of the single-site button) so operators don't mash it by reflex.

## Behavior

- **Eligibility:** Any node with `mgn_host = <this node's host>`, `mgn_enabled = true`, and `mgn_delete_time IS NULL`. The current node is included.
- **Skipping a site:** Disable it (`mgn_enabled = false`) on its node detail page, then run "Upgrade all". Re-enable when done. Existing UI; no new affordance.
- **Job execution:** Each job is a normal `apply_update` job. The Go agent picks them up off the queue and runs them per its existing concurrency rules. No multi-step coordination, no new state.
- **Failure semantics:** Each job succeeds or fails independently and shows up in the Jobs page with its normal status. There is no aggregate "did the bulk run succeed" indicator — operators read the Jobs list. Adding a roll-up is a future enhancement if it's needed.
- **Dry run:** If a `dry_run` checkbox is present alongside the button (mirroring the single-site form), each fan-out job inherits `--dry-run`. Defer to v1.5 if the form is getting cluttered.

## What this does NOT do

- Does **not** add a builder method. We call the existing `build_apply_update` in a loop. Any change to per-site upgrade behavior should still happen in `build_apply_update`, and the bulk path inherits it for free.
- Does **not** modify the Go agent. Each job is a normal single-step job from the agent's perspective.
- Does **not** add a `mgn_managed_nodes` column or any new schema. State lives in `mjb_management_jobs` rows like any other job.
- Does **not** filter sites by name patterns at the UI level. If an operator needs surgical targeting, the right tool is the per-node "Apply update" button on each node, not this one.
- Does **not** create a CLI/bash wrapper. The dashboard is the only surface.

## Acceptance criteria

- [ ] On a node detail page where the host has multiple sibling nodes, the "Upgrade All Sites on This Host" button is visible on the Updates tab.
- [ ] Clicking the button (after confirming) creates one `apply_update` job per eligible sibling node and redirects to the Jobs page.
- [ ] Disabling a sibling node (`mgn_enabled = false`) excludes it from the fan-out.
- [ ] Soft-deleted sibling nodes are not included.
- [ ] If a single `createJob` call throws, the others still get queued and the Jobs page reflects the partial fan-out.
- [ ] On a host with only one node, the button still works and queues one job (the same as clicking the single-site button).
- [ ] `php -l` clean on the modified `node_detail.php`.
- [ ] `validate_php_file.php` returns no new flags on `node_detail.php`.

## Documentation

When implemented, add a short paragraph to `docs/deploy_and_upgrade.md` under the Server Manager dashboard section:

> **Upgrade all sites on a host:** On any node detail page, the Updates tab includes "Upgrade All Sites on This Host". This queues one independent `apply_update` job per enabled, non-deleted node that shares the host. Jobs run as the agent picks them up; check the Jobs page for per-site status. Disable a node first to skip it.

Do not create a new doc file.
