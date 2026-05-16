# Agent Instructions

This file provides guidance to AI coding agents (Claude Code, Gemini, etc.)
working in this Joinery installation.

## System Overview

This is a custom PHP membership and event management platform with a modular MVC-like architecture. The system uses PostgreSQL and follows a front-controller pattern with theme-based UI customization and plugin extensibility.

**Key Entry Point:** `serve.php` - All requests are routed through this front controller using RouteHelper.php

## Database Access Rules

**CRITICAL:** Follow these rules for database access:

1. **READ operations** (SELECT, SHOW, DESCRIBE, \d, \dt, etc.) - Safe to execute as needed for investigation
2. **WRITE operations** (INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE) - ALWAYS ask for explicit user confirmation before executing

## Version Control Rules

**CRITICAL:** NEVER commit to git unless explicitly directed to by the user. File changes are allowed, but git commits require explicit user permission.

## Secret Handling Rules

**CRITICAL:** Never echo passwords, API keys, tokens, or other credentials into the chat transcript when avoidable.

- Don't print a secret to "confirm" it — describe its source instead (e.g., "read from Globalvars").
- When passing a password to a command, use stdin, env vars, or password files — not positional arguments that show up in logs.
- If a secret slips into a response, flag it so the user can decide whether to rotate.

The goal is minimizing blast radius if a transcript is shared, archived, or logged.

## File Permissions

When creating new files, match the permissions and ownership of existing files in the same directory (`stat -c '%a %U:%G' otherfile.php`). Do not loosen production permissions; if unsure, ask before chmod'ing.

## File Include Rules

**Core files are pre-loaded — never require them.** Use directly: `PathHelper`, `Globalvars`, `SessionControl`, `ThemeHelper`, `PluginHelper`, `DbConnector`.

For all other files:

- Standard: `require_once(PathHelper::getIncludePath('path/to/file.php'))`
- Theme-overridable: `require_once(PathHelper::getThemeFilePath('filename.php', 'subdirectory'))`

Run `php maintenance_scripts/dev_tools/validate_php_file.php <file>` after edits — it flags incorrect requires, `__DIR__ . '/../'` navigation, `$_SERVER['DOCUMENT_ROOT']` usage, and other anti-patterns.

## Architecture Patterns

### Directory Structure & Responsibilities
- `/data/` - Database model classes using Active Record pattern (`[table]_class.php`)
- `/logic/` - Business logic layer using LogicResult pattern (`[page]_logic.php`) [📖 See Logic Architecture Guide](docs/logic_architecture.md)
- `/views/` - Base presentation templates
- `/adm/` - Complete admin interface (uses PublicPageJoinerySystem theme)
- `/includes/` - Core system classes (Globalvars, DbConnector, FormWriterHTML5, LogicResult, etc.)
- `/theme/` - Multi-theme system; the active theme is configured per deployment
- `/plugins/` - Self-contained modules with own MVC structure
- `/ajax/` - AJAX endpoints and webhook handlers
- `/api/` - REST API with key-based authentication
- `/utils/` - Maintenance scripts and development tools
- `/migrations/` - Version-controlled database schema changes
- `/specs/` - Feature specifications (active and implemented)
- `/docs/` - Documentation and Claude-specific guidance
- `/tests/` - Test suites (email, functional, integration, models)
- `maintenance_scripts/` - Deployment and maintenance scripts
  - `install_tools/` - Installation scripts (install.sh, _site_init.sh, build_dev_from_source.sh, etc.)
  - `sysadmin_tools/` - Maintenance utilities (backup, restore, etc.)
  - `dev_tools/` - Development utilities (PHP validation, etc.)

### Routing & Theme System

**CRITICAL: NEVER edit Apache config files** (`/etc/apache2/`) **unless explicitly directed to by the user.** All routing is handled through `serve.php` for core routes, and through plugin view auto-discovery for plugin routes — those are always the correct places to add or modify routes. See **📖 [Routing Documentation](docs/routing.md)** and **📖 [Plugin Developer Guide](docs/plugin_developer_guide.md)**.

**CRITICAL: NEVER use .php extension in URLs or links! All requests go through the routing system.**
- ❌ WRONG: `<a href="/admin/admin_user_edit.php?id=1">` - Query parameters will be lost!
- ✅ CORRECT: `<a href="/admin/admin_user_edit?id=1">` - Routes properly with parameters

**Three things to know about routing:**
1. **Adding a page requires no route config.** Create `views/foo.php` and `/foo` works automatically. Add `logic/foo_logic.php` for business logic — it's auto-loaded.
2. **You only need a serve.php route** for URL placeholders (`/post/{slug}`), feature flags (`check_setting`), permission gates (`min_permission`), wildcards (`/admin/*`), or custom logic.
3. **Views resolve through the theme chain:** `theme/{theme}/views/` → `plugins/{plugin}/views/` → `views/` → 404.

**📖 [Routing Documentation](docs/routing.md)** — Full guide with route options, common patterns, and debugging

For themes and plugins: **📖 [Plugin Developer Guide](/docs/plugin_developer_guide.md)**

### Documentation Index

See `/docs/` for detailed guides on specific subsystems:

- [Admin Pages](docs/admin_pages.md) - Admin interface development patterns
- [Analytics](docs/analytics.md) - Visitor events, conversion tracking, and attribution reporting
- [API](docs/api.md) - REST API authentication, endpoints, and usage
- [Cloud Storage](docs/cloud_storage.md) - S3-compatible cloud bucket for public uploaded files
- [Component System](docs/component_system.md) - Reusable component architecture
- [Creating Components from Themes](docs/creating_components_from_themes.md) - Theme component extraction
- [Deletion System](docs/deletion_system.md) - Soft delete and permanent delete patterns
- [Deploy and Upgrade](docs/deploy_and_upgrade.md) - Deployment and upgrade procedures
- [Email Forwarding Plugin](plugins/email_forwarding/docs/overview.md) - Self-hosted email forwarding with virtual mailboxes
- [Email System](docs/email_system.md) - Email sending and templates
- [FormWriter](docs/formwriter.md) - Form generation system
- [Logic Architecture](docs/logic_architecture.md) - Business logic layer patterns
- [Photo System](docs/photo_system.md) - Photo management and uploads
- [Plugin Developer Guide](docs/plugin_developer_guide.md) - Plugin development, routing, themes
- [Product Requirements](docs/product_requirements.md) - Collecting data from buyers at checkout (built-in and custom requirement types)
- [Routing](docs/routing.md) - URL routing, view fallback, route configuration
- [Product Purchase Hooks](docs/product_purchase_hooks.md) - Purchase event hooks
- [Publish/Upgrade System Analysis](docs/publish_upgrade_system_analysis.md) - Publishing workflow
- [Recurring Events](docs/recurring_events.md) - Recurring event architecture and virtual/materialized instances
- [Scheduled Tasks](docs/scheduled_tasks.md) - Scheduled task system, cron runner, and task development
- [ScrollDaddy Plugin](plugins/dns_filtering/docs/overview.md) - DNS filtering service: unified block model, tier gating, editor UI, and resolver flow
- [SEO Metadata](docs/seo_metadata.md) - SEO, Open Graph, and Twitter Card tag conventions for public views
- [Server Manager](plugins/server_manager/docs/overview.md) - Remote server management plugin and Go agent
- [Settings](docs/settings.md) - System settings management
- [Social Features](docs/social_features.md) - Like/favorite system, block system, report system, messaging/conversations
- [Subscription Tiers](docs/subscription_tiers.md) - Subscription and tier system
- [Theme Integration Instructions](docs/theme_integration_instructions.md) - Theme setup and integration
- [Questions & Surveys](docs/questions_surveys.md) - Built-in questionnaire system: question types, surveys, answer storage
- [Validation](docs/validation.md) - Input validation patterns

## Database & Configuration

**Database:** PostgreSQL with PDO prepared statements

**Configuration:**
- File-based config for core settings: `Globalvars_site.php` (in `{site root}/config/`, the directory alongside `public_html`)
- Additional, database-stored settings in `stg_settings` table, accessed with settings singleton
- `$settings = Globalvars::get_instance()` - Get settings singleton
- `$settings->get_setting('setting_name')` - Get configuration value
- **Note:** There is no `set_setting()` method - see `/adm/admin_settings.php` for how to change settings
- Plugin-owned settings with factory defaults are declared in the plugin's `plugin.json` under `settings`. Core settings with factory defaults are declared in `settings.json` at the `public_html/` root. Both are seeded into `stg_settings` automatically; no migrations needed. See `docs/plugin_developer_guide.md#plugin-settings-declarative`.

### Important Settings
- **composerAutoLoad**: Path to the vendor directory, e.g., `../vendor/` (relative to `public_html`). The setting is the directory path, NOT the full path to `autoload.php` — code appends `autoload.php` when using it.

### Data Model Classes

Models follow an Active Record pattern. Single-row classes extend `SystemBase`; collection classes extend `SystemMultiBase`. For the full method surface and conventions, read `includes/SystemBase.php` and `includes/SystemMultiBase.php` directly, or skim a representative concrete class like `data/users_class.php` or `data/coupon_codes_class.php`.

Two non-obvious rules worth knowing up front:

**1. Constructors require a parameter.** New: `new Product(NULL)`. Load: `new Product($id, TRUE)`. Calling `new Product()` raises "Too few arguments."

**2. Multi-class filter keys differ from db column names.** Each Multi class defines its option keys in `getMultiResults()` — don't pass raw prefixed column names. Example:

```php
// ❌ WRONG - raw column names
$groups = new MultiGroup(['grp_name' => 'Basic Plan']);

// ✅ CORRECT - option keys from MultiGroup::getMultiResults()
$groups = new MultiGroup(['group_name' => 'Basic Plan']);
```

### DbConnector Usage

**Prefer models over raw DbConnector.** Use `User`, `MultiUser`, `Product`, `MultiProduct`, etc. when they exist — they handle prepared statements, validation, and lifecycle correctly. Fall back to `DbConnector::get_instance()->get_db_link()` only for queries that don't fit a model (complex joins, aggregations, ad-hoc reporting).

When using DbConnector directly:
- Always use prepared statements; never concatenate user input into SQL.
- Use `PDO::FETCH_ASSOC` for arrays, `PDO::FETCH_OBJ` for objects.

## Model Querying Patterns

`getMultiResults()` in each Multi class accepts filter values in three formats:

1. **Parameterized array** for safe value binding: `$filters['evr_evt_event_id'] = [$event_id, PDO::PARAM_INT];` → produces `evr_evt_event_id = ?`.
2. **String condition** appended literally: `$filters['evr_delete_time'] = "IS NULL";` → `evr_delete_time IS NULL`.
3. **Split-parenthesis OR conditions** for grouped clauses with precedence: `$filters['(evr_expires_time'] = ">= now() OR evr_expires_time IS NULL)";` → `(evr_expires_time >= now() OR evr_expires_time IS NULL)`.

When in doubt about a Multi class's accepted options, read its `getMultiResults()` method directly.

## Admin Page Development

For complete guidance on creating admin interface pages, including required setup, table patterns, form handling, and best practices, see:

**📖 [Admin Pages Documentation](/docs/admin_pages.md)**

## Common Tasks & Quick Reference

### Time/Date Handling

**All times in the database are stored in UTC.** Use `convert_time()` for all display formatting:

```php
// Display a DB time in user's timezone
LibraryFunctions::convert_time($obj->get('field'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T')

// Shift a time by any interval (accepts any DateTime::modify string)
LibraryFunctions::time_shift($time_string, '7 days', $format)  // also: '30 minutes', '-2 hours', '1 year'

// Compare times — use string comparison (DB times are ISO-formatted UTC)
$now_utc = gmdate('Y-m-d H:i:s');
if ($obj->get('start_time') > $now_utc) { /* future */ }
```

**Do NOT use `new DateTime()` directly** except when a third-party library requires DateTime objects (e.g., Spatie calendar-links).

### Handling Logic Function Results

```php
// ✅ CORRECT - Always wrap logic calls with process_logic():
$page_vars = process_logic(profile_logic($_GET, $_POST));

// Automatically handles redirects, errors, and data extraction
```

### Theme Framework Rules

**CRITICAL: Vanilla CSS and vanilla JS is the default for all themes and plugins. Never introduce Bootstrap, jQuery, or any other external JS/CSS framework unless the theme or plugin explicitly declares one (via `cssFramework` in `theme.json`) or one is specifically requested.**

The admin interface runs the Joinery System theme (`joinery-system`), which is vanilla HTML5 and JavaScript — no Bootstrap, jQuery, or other frameworks.

To confirm what a theme uses, check `theme.json`: `"cssFramework": "html5"` (or absent) means vanilla only; `"cssFramework": "bootstrap"` means Bootstrap is available; `"cssFramework": "tailwind"` means Tailwind is available.

### Getting FormWriter Instances

```php
// In views with PublicPage available:
$formwriter = $page->getFormWriter('form1');

// In admin pages and utilities (where AdminPage is available):
$formwriter = $page->getFormWriter('form1');

// In logic files or other contexts without a page object:
// Use the class that matches the current theme's CSS framework:
//   HTML5 themes → FormWriterV2HTML5
//   Bootstrap themes → FormWriterV2Bootstrap
//   Admin/utility scripts → FormWriterBootstrap (Bootstrap is always available in admin)
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
$formwriter = new FormWriterV2HTML5('form1');

// The page->getFormWriter() method automatically detects the correct FormWriter for the theme
```

### Session Check (Admin Pages)
```php
$session = SessionControl::get_instance();
$session->check_permission(5); // Requires permission level 5 (admin minimum)
// Permission levels: 5 = admin, 10 = superadmin
// check_permission() automatically redirects to login if not authorized
```

### Tests
- `/tests/email/` - Email sending and authentication patterns
- `/tests/functional/products/` - Product-related functionality
- `/tests/integration/` - External services (Mailgun, PHPMailer, routing)
- `/tests/models/` - Data model CRUD operations and validation
- **Inbound email testing:** When Mailgun inbound routing is configured, received emails are stored in `iem_inbound_emails` — query that table to inspect messages: `SELECT * FROM iem_inbound_emails WHERE iem_recipient LIKE '%address%' ORDER BY iem_received_time DESC LIMIT 1;`

### Deployment Scripts
Located in `maintenance_scripts/install_tools/`.

### Receiving Upgrades
Apply pending upgrades with `php utils/upgrade.php --verbose`.

For multi-site setups, the Server Manager dashboard at `/admin/server_manager` provides a UI for managing remote nodes and publishing upgrades between them.

## Development Workflow

### Adding New Features
1. Create data class: `/data/[feature]_class.php` (defines database schema automatically)
2. Add business logic: `/logic/[feature]_logic.php`
3. Create view template: `/views/[feature].php`
4. Add admin interface: `/adm/admin_[feature].php`
5. Add route to `serve.php` if needed (most pages don't — see [Routing](docs/routing.md#when-you-do-need-a-servephp-route))
6. Add data migrations in `/migrations/` if needed (for settings, data updates only)
7. Define deletion strategy (`$foreign_key_actions`, soft-delete cascading) — see [Deletion System](docs/deletion_system.md)

**Plugin lifecycle operations go through PluginManager, not the Plugin model.** Plugin models are pure CRUD — never call activate/deactivate/install/uninstall on them directly. See [Plugin Developer Guide](docs/plugin_developer_guide.md).

**Plugin views auto-discover under namespaced URLs.** Creating `plugins/myplugin/views/profile/settings.php` makes `/profile/myplugin/settings` work immediately — no serve.php entry needed. Plugin internal links must use namespaced URLs (e.g. `/profile/myplugin/settings`, not `/profile/settings`). Use serve.php only for routes needing model binding, permission gates, or feature flags. Plugin names must be distinctive and not conflict with system paths — see [Plugin Developer Guide](docs/plugin_developer_guide.md).

**Schema changes to plugin data classes** — modify `$field_specifications`, then run "Sync with Filesystem" from the admin Plugins page, or run `update_database` from admin utilities (its final step syncs plugins). Deploys also sync automatically via `upgrade.php`. The core `update_database` pipeline excludes plugins, but `PluginManager::sync()` handles plugin tables as a post-core step.

**Helper Class Integration:** Use RouteHelper for custom routing, ThemeHelper for asset management, and PathHelper for file operations instead of manual path handling.

### Specifications Management

Place feature specifications in `specs/` (active) and move them to `specs/implemented/` when complete.

**🚨 CRITICAL RULE: NEVER MODIFY FILES IN `specs/implemented/`** — they are historical record. Consult them and `git log` for context on past work, but treat them as immutable.

### Database Schema Management

**IMPORTANT:** Database tables, columns, and constraints are managed automatically by the `update_database` system based on data class specifications. **DO NOT add columns or table structure changes via migrations.**

#### Automatic Database Updates
The system automatically:
- Creates tables based on data class `$tablename` and `$field_specifications`
- Adds missing columns from `$field_specifications`
- Updates column types and constraints
- Creates indexes and foreign keys as needed

Simply define fields in your data class and the database will be updated automatically:

```php
// In data/example_class.php
public static $field_specifications = array(
    'new_column' => array('type'=>'varchar(255)', 'is_nullable'=>false),
);
```

#### When to Use Migrations
Migrations are executed by running `update_database` from the admin utilities page — they do not run automatically on page load. Migrations are ONLY for data changes (settings insertions, data updates) — never for schema changes. See **📖 [Deploy and Upgrade](docs/deploy_and_upgrade.md)** for syntax and rules.

## Development Environment

### Production Servers
For managing deployment nodes (IPs, containers, SSH details), use the Server Manager dashboard at `/admin/server_manager`.

**Log In As Another User:**
Navigate to `/admin/admin_user_login_as?usr_user_id={id}` while logged in as a permission-10 admin. This switches the session to that user and redirects to `/`. To find a user's ID, go to `/admin/admin_users` and click the user — the URL will show `?usr_user_id=N`.

### Browser Testing (MCP)
If a Playwright browser is available via MCP, use it for visual testing — verifying page rendering, layouts, form interactions, and theme changes.

**Common browser commands:**
```
# Navigate to a page
mcp__browser__browser_navigate with url: "https://yoursite/path"

# Get page snapshot (accessibility tree - preferred for understanding page structure)
mcp__browser__browser_snapshot

# Take a screenshot (for visual verification)
# ALWAYS specify filename in /tmp/
mcp__browser__browser_take_screenshot with filename: "/tmp/description.png"

# Click an element (use ref from snapshot)
mcp__browser__browser_click with element: "description" and ref: "e123"
```

**CRITICAL: Always save screenshots to `/tmp/`** with an explicit filename. Omitting the filename or using a bare name saves to the wrong directory.

### Local Development Setup
For local development you typically have:
- PHP Runtime for syntax checking (`php -l filename.php`)
- File system access and basic bash commands
- PostgreSQL database access via `psql`
- Web server (Apache, nginx, or built-in)

**CRITICAL REQUIREMENTS for PHP Development:**

1. **Syntax Validation**: Always check PHP files for syntax errors using `php -l filename.php` before declaring any PHP development task complete.

2. **Method Existence Validation**: Run the PHP file validator on all PHP files created or modified:
   ```bash
   php maintenance_scripts/dev_tools/validate_php_file.php /path/to/modified/file.php
   ```
   - Investigate any missing methods flagged by the script
   - Only report task completion after all flags are investigated and resolved
   - The script identifies calls to non-existent functions and methods
   - Whitelisted common methods (SystemBase, PDO, etc.) are automatically skipped

### Common Development Commands
```bash
# PHP Syntax Validation
php -l filename.php

# PHP File Validation
php maintenance_scripts/dev_tools/validate_php_file.php /path/to/file.php

# Database access (credentials in config/Globalvars_site.php)
psql -U <user> -d <database>

# Apache service management (if using Apache)
sudo systemctl status apache2
sudo systemctl restart apache2
```

### Workflow
1. Make code changes
2. Run syntax validation (`php -l filename.php`)
3. Run method existence validation on modified files
4. Test changes locally (web server available)
5. Check the platform error log for issues. Logs are typically verbose (routing debug output); `grep` for specific keywords (e.g., `Fatal`, `error`) rather than scanning `tail` output directly.

## Plugin Development

Plugins provide backend functionality with admin interfaces at `/plugins/{plugin}/admin/*`.
See **📖 [Plugin Developer Guide](/docs/plugin_developer_guide.md)** for complete details.

## Best Practices

1. **Syntax Validation**: ALWAYS run `php -l filename.php` on all PHP files before completing any task
2. **Method Existence Validation**: ALWAYS run validate_php_file.php on created/modified PHP files and investigate any flagged issues before completion
3. **Method Verification**: NEVER assume available functions - always check class definitions first
4. **Security**: Always validate and sanitize user input
5. **FormWriter**: NEVER write hand-rolled HTML forms. Always use FormWriter for every form in the platform — it handles validation styling, CSRF, `novalidate`/`is-invalid` integration, and consistent UX automatically. See **📖 [FormWriter Documentation](docs/formwriter.md)**. The only exception is single-button action forms (a `<form>` with only hidden inputs and a submit button) that trigger a server action with no user-entered fields.
6. **Data Collection**: NEVER write custom scripts or ad-hoc forms to collect data from users. Use **Questions/Surveys** (`/admin/admin_questions`, `/admin/admin_surveys`) for standalone questionnaires, and **Product Requirements** (attached to products via the admin product edit page) for data collected at purchase time. See **📖 [Questions & Surveys](docs/questions_surveys.md)** and **📖 [Product Requirements](docs/product_requirements.md)**. Only reach for custom code if these systems genuinely cannot accommodate the use case.
7. **Follow Existing Patterns**: Look at similar files in the codebase before creating new ones
8. **Version Numbers**: ALWAYS look for version numbers in files when making changes and increment them appropriately

## Security Notes

- All database queries use PDO prepared statements
- Session-based authentication with role management
- Input validation on all forms (client + server side)
- Check permissions early in admin scripts

## Key Integration Points

**Payment Processing:** `StripeHelper`, `PaypalHelper` classes
**Email:** `SystemMailer` with template support
**External APIs:** Webhooks in `/ajax/` for Stripe
**File Management:** Secure upload handling in `/includes/UploadHandler.php`

### Helper Classes

- **RouteHelper** — URL routing and static file serving for the front controller
- **ThemeHelper** — Theme metadata, asset URLs with cache busting, theme configuration lookup
- **PluginHelper** — Plugin metadata and helper functions
- **PathHelper** — Path resolution and file operations
- **ComponentBase** — Base class shared by `PluginHelper` and `ThemeHelper`

For exact method surfaces, read the corresponding files in `/includes/`.
