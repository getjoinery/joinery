# ScrollDaddy Test Page: Quick Rule Addition

**Status:** Draft

---

## Summary

After a user tests a domain or scans a URL on `/profile/scrolldaddy/test`, each result row should offer a one-click "Add Rule" button to either block an allowed domain or allow a blocked one. This eliminates the need to navigate to the separate Custom Rules page just to act on a test result.

**Gating — both conditions must be true:**
1. The user's subscription tier has the `scrolldaddy_custom_rules` feature enabled.
2. `$device->are_filters_editable()` returns true.

If either condition fails, no buttons are shown anywhere on the result. Buttons are also suppressed for domains that already have a custom rule in effect (`custom_block_rule` / `custom_allow_rule` reason) and for SafeSearch/YouTube rewrites.

---

## User Experience

### Domain test result — ALLOWED domain

```
✓ tiktok.com — Allowed
  Not matched by any filter or rule.
  Active profile: Primary

  [+ Block this domain]
```

### Domain test result — BLOCKED domain

```
✗ facebook.com — Blocked
  Matched category: Social Media
  Active profile: Primary

  [✓ Allow this domain]
```

### URL scan result — per-domain buttons

Each domain row in a scan result gets its own button. Buttons appear inline after the detail line:

```
▼ Blocked (2)
  facebook.com
    Matched category: Social Media          [Allow]
  doubleclick.net
    Matched category: Ads (Light)           [Allow]

▶ Allowed (5)
  google.com
    Not matched                             [Block]
  cdn.example.com
                                            [Block]
```

Buttons use shorter labels in scan context: **Allow** (for blocked rows) and **Block** (for allowed rows).

Domains where the reason is `custom_block_rule` or `custom_allow_rule` do not get a button — the user already set a rule for those. Rewritten domains do not get a button.

### Domain already has a custom rule

When the result reason is `custom_block_rule` or `custom_allow_rule`, skip the button. The user already set a rule intentionally — direct them to the Custom Rules page to remove it.

### After successful rule addition

Replace the button with a short confirmation inline (no page reload):

```
  facebook.com
    Matched category: Social Media     Rule added →
```

(The "→" links to `/profile/scrolldaddy/rules?device_id={id}`.)

If the same scan is used to add multiple rules, each row responds independently.

### Gating

Both conditions must be true for any button to appear:

1. **Plan feature:** `scrolldaddy_custom_rules` must be enabled on the user's subscription tier. Users on lower tiers see no buttons — the result renders identically to how it does now.
2. **Editable day:** `are_filters_editable()` must return true. If the device owner restricts edits to a specific day and this isn't it, no buttons appear.

---

## Architecture

```
Browser                       Web Server
   │                               │
   ├─ POST ───────────────────────>│
   │  /profile/scrolldaddy/rules   │
   │  ajax=1                       │
   │  device_id=X                  │
   │  sdr_hostname=tiktok.com      │
   │  sdr_action=0 (block) / 1     │
   │                               │
   │<───── JSON response ──────────│
   │  { success: true }            │
   │  or { success: false, msg }   │
```

No new file needed — `rules_logic.php` already owns rule addition. An `ajax=1` POST flag switches it from redirect-on-success to JSON response. No DNS server involvement.

---

## Changes Required

### 1. rules_logic.php

**File:** `plugins/scrolldaddy/logic/rules_logic.php`

Add an AJAX branch at the top of the existing `$_POST['sdr_hostname']` block. When `$_POST['ajax'] === '1'` is present, return JSON instead of redirecting. The tier check must be done here (currently it's only in the view):

```php
else if (isset($_POST['sdr_hostname'])) {

    $is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if ($is_ajax) {
        header('Content-Type: application/json');

        if (!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_custom_rules', false)) {
            echo json_encode(['success' => false, 'message' => 'Your plan does not include custom rules.']);
            exit;
        }
    }

    // ... existing device/block load and add_rule() call ...

    if ($is_ajax) {
        echo json_encode(['success' => true]);
        exit;
    }

    return LogicResult::redirect(...); // existing redirect unchanged
}
```

`subscription_tiers_class.php` is not currently required in `rules_logic.php` — add the `require_once` at the top of the function alongside the other requires.

### 2. test_logic.php

**File:** `plugins/scrolldaddy/logic/test_logic.php`

Add tier loading and compute `can_add_rules` so the view doesn't do data fetching:

```php
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

$tier = SubscriptionTier::GetUserTier($session->get_user_id());
$page_vars['tier'] = $tier;
$page_vars['can_add_rules'] = $tier
    && $tier->getFeature('scrolldaddy_custom_rules', false)
    && $device->are_filters_editable();
```

This matches the pattern in `devices_logic.php`, which loads `$tier` via `SubscriptionTier::GetUserTier()` and passes it to the view where it's used as `$tier->getFeature('scrolldaddy_max_devices', 0)`.

### 3. Test page view

**File:** `plugins/scrolldaddy/views/profile/test.php`

Changes:

**a) Extract `$can_add_rules` from page vars and emit it as a JS variable.**  

```php
$can_add_rules = $page_vars['can_add_rules'];
```

```php
<script>
var scdCanAddRules = <?php echo $can_add_rules ? 'true' : 'false'; ?>;
</script>
```

**b) Update `formatDomainResult()` in JavaScript.**  
After building the result HTML, if `scdCanAddRules` is true and the result reason is NOT `custom_block_rule`, `custom_allow_rule`, `safesearch_rewrite`, or `safeyoutube_rewrite`, append a button:

- `result === 'BLOCKED'` or `result === 'REFUSED'` → action = 1 (allow), label = "Allow this domain"
- `result === 'FORWARDED'` → action = 0 (block), label = "Block this domain"

Button markup (appended inside the result card `<div>`):

```html
<div style="margin-top:10px; padding-left:20px;">
  <button type="button" class="scd-add-rule-btn th-btn"
          data-domain="{domain}" data-device="{deviceId}" data-action="{action}">
    {label}
  </button>
  <span class="scd-rule-feedback" style="display:none; font-size:13px; margin-left:8px;"></span>
</div>
```

**c) Update `renderGroup()` to accept a `ruleAction` parameter and render per-row buttons.**  
`ruleAction` is `1` (allow) for BLOCKED/REFUSED groups, `0` (block) for the ALLOWED group, and `null` for REWRITTEN.

```js
function renderGroup(label, items, color, collapsed, ruleAction) {
    // ... existing header/summary HTML ...
    items.forEach(function (r) {
        html += '<div style="padding:5px 0; border-bottom:1px solid #f0f0f0; display:flex; align-items:baseline; gap:8px;">';
        html += '<div style="flex:1;">';
        html += '<div style="font-size:14px;">' + escHtml(r.domain) + '</div>';
        if (r.detail) html += '<div style="font-size:12px; color:#888;">' + escHtml(r.detail) + '</div>';
        html += '</div>';
        if (scdCanAddRules && ruleAction !== null && r.result !== 'ERROR'
                && r.reason !== 'custom_block_rule' && r.reason !== 'custom_allow_rule') {
            var btnLabel = ruleAction === 1 ? 'Allow' : 'Block';
            html += '<button type="button" class="scd-add-rule-btn th-btn"'
                  + ' data-domain="' + escHtml(r.domain) + '"'
                  + ' data-device="' + deviceId + '"'
                  + ' data-action="' + ruleAction + '">'
                  + escHtml(btnLabel) + '</button>';
            html += '<span class="scd-rule-feedback" style="display:none; font-size:12px;"></span>';
        }
        html += '</div>';
    });
    // ... existing closing HTML ...
}
```

Update `formatScanResult()` to pass `ruleAction` to each `renderGroup` call:

```js
if (grouped.BLOCKED.length > 0)   html += renderGroup('Blocked',   grouped.BLOCKED,   '#dc3545', false, 1);
if (grouped.REFUSED.length > 0)   html += renderGroup('Refused',   grouped.REFUSED,   '#e67e22', false, 1);
if (grouped.REWRITTEN.length > 0) html += renderGroup('Rewritten', grouped.REWRITTEN, '#0d6efd', false, null);
if (grouped.ALLOWED.length > 0)   html += renderGroup('Allowed',   grouped.ALLOWED,   '#198754', true,  0);
```

**d) Add a delegated click handler** (at the bottom of the existing IIFE) for `.scd-add-rule-btn`:

```js
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.scd-add-rule-btn');
    if (!btn) return;
    btn.disabled = true;
    var feedback = btn.parentNode.querySelector('.scd-rule-feedback');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/profile/scrolldaddy/rules');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        var data;
        try { data = JSON.parse(xhr.responseText); } catch (e) {}
        if (data && data.success) {
            btn.style.display = 'none';
            feedback.style.display = 'inline';
            feedback.style.color = '#198754';
            feedback.innerHTML = 'Rule added. <a href="/profile/scrolldaddy/rules?device_id=' + encodeURIComponent(btn.dataset.device) + '">Manage rules \u2192</a>';
        } else {
            btn.disabled = false;
            feedback.style.display = 'inline';
            feedback.style.color = '#dc3545';
            feedback.textContent = (data && data.message) ? data.message : 'Failed to add rule.';
        }
    };
    xhr.onerror = function () {
        btn.disabled = false;
        feedback.style.display = 'inline';
        feedback.style.color = '#dc3545';
        feedback.textContent = 'Network error. Please try again.';
    };
    xhr.send(
        'ajax=1' +
        '&device_id='    + encodeURIComponent(btn.dataset.device) +
        '&sdr_hostname=' + encodeURIComponent(btn.dataset.domain) +
        '&sdr_action='   + encodeURIComponent(btn.dataset.action)
    );
});
```

---

## Edge Cases

- **URL scan results:** Each domain row in both BLOCKED and ALLOWED groups gets its own independent button. Rules are added one at a time; each button responds independently.
- **ERROR results in URL scan:** `scan_url.php` emits `result === 'ERROR'` when the DNS server doesn't respond for a domain. These fall into the ALLOWED group in the JS grouper. The `renderGroup` button condition explicitly checks `r.result !== 'ERROR'` to suppress buttons on these rows.
- **REFUSED results:** Treat the same as BLOCKED — offer an Allow rule.
- **Domain already has a custom rule:** The PHP endpoint rejects the request. The JS shows the error message inline next to the button.
- **Non-editable day:** `are_filters_editable()` returns false → button is never rendered (PHP sets `scdCanAddRules = false`). No API call is possible.
- **No subscription feature:** Same as above — `scdCanAddRules = false`, button never rendered.
- **Race condition (user adds rule, tests again):** Button suppression is client-side only. A determined user could POST twice and create duplicate hostname entries. This matches the existing behaviour of the Custom Rules page, which also has no duplicate check.
- **Rule syncs to DNS:** Rule addition goes through the same `SdProfile::add_rule()` path as the Custom Rules page, so DNS sync behavior is identical.

---

## Out of Scope

- Removing existing custom rules from the test page (use `/profile/scrolldaddy/rules`)
- Adding rules for scheduled block profiles (the existing rules page supports this; the test page always tests the currently active profile which may be primary or secondary, but rule addition here targets the primary profile only)
- Bulk rule creation
- Editing rule action (block ↔ allow) from the test page
