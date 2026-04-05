# Joinery

Joinery is a self-hosted membership and event management platform built in PHP with PostgreSQL. It provides a complete system for managing members, events, registrations, payments, and communications -- designed for organizations that want full control over their data and infrastructure.

## Features

- **Membership management** with profiles, permissions, and subscription tiers
- **Event system** with registration, recurring events, and capacity management
- **Payment processing** via Stripe and PayPal
- **Email system** with templates, Mailgun integration, and self-hosted email forwarding
- **Content management** with posts, pages, and photo galleries
- **Plugin architecture** for extending functionality
- **Theme system** with customizable templates
- **Admin interface** for site management
- **REST API** with key-based authentication
- **Social features** including messaging, likes, and moderation tools

## Architecture

Joinery uses a front-controller pattern with a modular MVC-like structure:

```
public_html/
  serve.php          # Front controller - all requests route through here
  data/              # Database model classes (Active Record pattern)
  logic/             # Business logic layer
  views/             # Presentation templates
  adm/               # Admin interface
  includes/          # Core system classes
  theme/             # Multi-theme system
  plugins/           # Self-contained plugin modules
  ajax/              # AJAX endpoints and webhooks
  api/               # REST API
  migrations/        # Version-controlled data migrations
```

## Requirements

- PHP 8.x
- PostgreSQL
- Apache with mod_rewrite
- Composer

## Installation

Run the interactive setup wizard:

```bash
bash maintenance_scripts/install_tools/install.sh
```

This handles database creation, configuration, dependency installation, and initial site setup.

## Documentation

Detailed documentation is available in [`public_html/docs/`](public_html/docs/):

- [Routing](public_html/docs/routing.md) - URL routing and view resolution
- [Logic Architecture](public_html/docs/logic_architecture.md) - Business logic patterns
- [Admin Pages](public_html/docs/admin_pages.md) - Admin interface development
- [Plugin Developer Guide](public_html/docs/plugin_developer_guide.md) - Building plugins
- [API](public_html/docs/api.md) - REST API authentication and endpoints
- [FormWriter](public_html/docs/formwriter.md) - Form generation system
- [Component System](public_html/docs/component_system.md) - Reusable components
- [Theme Integration](public_html/docs/theme_integration_instructions.md) - Theme setup
- [Email System](public_html/docs/email_system.md) - Email sending and templates
- [Photo System](public_html/docs/photo_system.md) - Photo management
- [Settings](public_html/docs/settings.md) - System settings
- [Validation](public_html/docs/validation.md) - Input validation
- [Deletion System](public_html/docs/deletion_system.md) - Soft and permanent delete
- [Recurring Events](public_html/docs/recurring_events.md) - Recurring event architecture
- [Scheduled Tasks](public_html/docs/scheduled_tasks.md) - Cron and task system
- [Social Features](public_html/docs/social_features.md) - Messaging, likes, moderation
- [Subscription Tiers](public_html/docs/subscription_tiers.md) - Membership tiers
- [Deploy and Upgrade](public_html/docs/deploy_and_upgrade.md) - Deployment procedures

## License

This project is licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE.md). Free for personal and noncommercial use. For commercial licensing, contact [Joinery](https://getjoinery.com).
