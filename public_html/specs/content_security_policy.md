---
title: Content-Security-Policy (CSP) Implementation
status: planned
priority: medium
---

# Content-Security-Policy (CSP) Implementation

## Overview

Content-Security-Policy (CSP) is a security header that tells the browser exactly which sources of content are allowed to load. It's one of the most effective defenses against XSS (Cross-Site Scripting) attacks.

## What CSP Does

CSP allows you to define a whitelist of trusted sources for scripts, styles, images, fonts, and other resources. If a resource doesn't match the policy, the browser blocks it.

**Example policy:**
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'
```

This means:
- `default-src 'self'` — Only load resources from the same domain by default
- `script-src 'self' 'unsafe-inline'` — Allow scripts from same domain + inline scripts
- `style-src 'self' 'unsafe-inline'` — Allow styles from same domain + inline styles

## Security Benefits

1. **XSS Prevention** — If an attacker injects `<script>alert('hacked')</script>`, it won't execute
2. **Data Exfiltration Prevention** — Attacker cannot send stolen data to `evil.com`
3. **Iframe Embedding Control** — Controls what external sites can iframe your content
4. **Resource Loading Restrictions** — Only whitelisted CDNs, fonts, and APIs are allowed

## Implementation Challenges

CSP is powerful but fragile. This codebase has significant inline content that would need refactoring.

### Problem 1: Inline Scripts

**Current approach (breaks with strict CSP):**
```php
<script>
  document.addEventListener('click', function() { ... });
</script>
```

**Strict CSP requires nonces:**
```php
<script nonce="abc123def456">
  document.addEventListener('click', function() { ... });
</script>
```

Every inline script needs a unique nonce generated on every page load.

### Problem 2: Inline Event Handlers

**Current approach (breaks with strict CSP):**
```html
<button onclick="doSomething()">Click me</button>
<input onchange="updateValue()">
```

**Strict CSP requires:**
- Move to external event listeners in JavaScript files, OR
- Add nonce to script tag with `setAttribute('onclick', ...)`

This is pervasive in the codebase.

### Problem 3: Inline Styles

**Current approach:**
```html
<div style="color: red; margin: 10px;">Content</div>
```

**Strict CSP requires:**
- Move all inline styles to CSS classes/files, OR
- Use nonces (less common for styles)

### Problem 4: FormWriter-Generated Content

The FormWriter system likely generates inline event handlers and styles. This would require:
- Modifying FormWriter to not use inline attributes
- Updating all form generation calls
- Testing extensively across all forms

### Problem 5: External Service Whitelisting

Each external service needs explicit allowlisting:
- Google Fonts: `style-src https://fonts.googleapis.com`
- jQuery (if used): `script-src https://code.jquery.com`
- Stripe/PayPal: Multiple directives for scripts and iframes
- Analytics: `connect-src` for data endpoints
- Mailgun webhooks: `connect-src` for API endpoints

### Problem 6: Plugin System

Plugins may:
- Load external scripts
- Use inline styles
- Create dynamic content

Each plugin's CSP requirements would need documentation and testing.

## Codebase-Specific Issues

1. **High inline script count** — Throughout view templates and logic files
2. **Inline event handlers** — From FormWriter and manual HTML
3. **Multiple template layers** — Theme system with nested includes
4. **Plugin architecture** — Each plugin could have different CSP needs
5. **Admin interface** — Heavy JavaScript usage in admin pages

## Severity vs. Complexity Trade-off

| Policy Strictness | Security Level | Implementation Effort | Risk of Breaking |
|------------------|----------------|----------------------|-----------------|
| `default-src 'self'; script-src 'self' 'unsafe-inline'` | Low | Easy (1 day) | None |
| `default-src 'self'; script-src 'nonce-...'` | High | Hard (2-3 weeks) | High |
| `default-src 'self'; script-src 'self'` (no inline) | Highest | Very hard (4+ weeks) | Very high |

## Recommended Approach

### Phase 1: Setup with Permissive Policy (1 day)

Add CSP as a configurable setting with a permissive default:

```php
if ($settings->get_setting('enable_csp', false, true)) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https:; connect-src 'self' https:");
}
```

This provides:
- ✅ Good defense against many XSS attacks
- ✅ Allows external fonts, CDNs, analytics
- ✅ No code changes needed
- ⚠️ Less strict than possible (still allows inline scripts/styles)

### Phase 2: Inventory Current State (1-2 days)

1. Find all inline scripts and event handlers
2. Find all inline styles
3. Find all external resources being loaded
4. Document which plugins use what resources
5. Create list of FormWriter-generated inline content

### Phase 3: Strict Implementation (2-4 weeks)

1. Refactor FormWriter to not generate inline handlers
2. Move inline styles to CSS classes
3. Consolidate inline scripts into external files
4. Implement nonce system for any remaining inline code
5. Update all plugins to follow CSP guidelines
6. Thoroughly test all features

### Phase 4: Report-Only Testing (1 week)

Deploy CSP in "report-only" mode before enforcing:

```php
header("Content-Security-Policy-Report-Only: ...");
```

This logs violations without blocking content, catching issues before enforcement.

## Implementation Options

### Option A: Start with Permissive Policy (Recommended)

```php
// In settings with a checkbox to enable CSP
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https:; connect-src 'self' https:");
```

**Pros:**
- Provides real security against many attacks
- Zero code changes needed
- Can be toggled on/off
- Safe to test on production

**Cons:**
- Less strict than possible
- Still allows some XSS vectors

### Option B: Strict Policy with Nonces (Long-term)

Implement after major refactoring:
- Generate nonce on every request
- Add nonce to all inline scripts: `<script nonce="...">...</script>`
- Add nonce to FormWriter output
- No `'unsafe-inline'` in policy

**Pros:**
- Maximum security
- Best practice

**Cons:**
- Extensive refactoring required
- Risk of breaking functionality
- Nonce generation adds processing

## Configuration Settings Needed

Add to admin_settings.php:

1. **Enable CSP** — Checkbox (default: off)
2. **CSP Report URI** (optional) — Where to send violation reports
3. **CSP Report-Only Mode** — Boolean to log violations without blocking

## Testing Strategy

1. **Unit tests** — Verify CSP header is sent correctly
2. **Manual testing** — Check all forms, interactive elements, external resources
3. **Browser DevTools** — Check console for CSP violations
4. **CSP Report-Only** — Deploy in report-only mode for 1-2 weeks
5. **Monitoring** — Watch logs for reported violations before enforcement

## Known External Resources to Whitelist

When CSP is implemented, these will need allowlisting:

- Google Fonts (if used): `https://fonts.googleapis.com`, `https://fonts.gstatic.com`
- Any CDN-hosted libraries
- Mailgun (webhooks): Check domain
- Stripe/PayPal (if used): Check their CSP requirements
- Analytics services: Check their CSP requirements
- Plugin-specific APIs

## Future Enhancements

- CSP violation reporting endpoint (report-uri)
- Analytics on CSP violations
- Per-section CSP policies
- Automatic nonce generation framework

## References

- [MDN: Content-Security-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)
- [OWASP CSP Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- [CSP Evaluator](https://csp-evaluator.withgoogle.com/)
