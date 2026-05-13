# Joinery Platform Documentation

Welcome to the Joinery platform documentation. Select a topic from the sidebar, or browse the categories below.

## Getting Started

- [Quick Start](quickstart.md) -- **New? Start here.** Rent a server, point your domain, and install Joinery in about 15 minutes — no prior experience required
- [Installation](installation.md) -- Full reference: Docker or bare-metal, SSL, domain management, multi-site, cloning

## Core Systems

- [Routing](routing.md) -- How pages are created and served, URL patterns, and the theme override chain
- [Logic Architecture](logic_architecture.md) -- Business logic layer, LogicResult pattern, and page flow
- [Admin Pages](admin_pages.md) -- Building admin interface pages with AdminPage, tables, and forms
- [FormWriter](formwriter.md) -- Form generation, validation integration, and field types
- [Validation](validation.md) -- Three-layer validation system (client, server, model)
- [Settings](settings.md) -- System settings management and auto-creating settings
- [Email System](email_system.md) -- Composing and sending email with templates, service fallback, and batch operations
- [Email Forwarding](/plugins/email_forwarding/docs/overview.md) -- Self-hosted email forwarding with virtual mailboxes
- [Photo System](photo_system.md) -- Multi-photo management, uploads, and image sizing
- [Cloud Storage](cloud_storage.md) -- S3-compatible cloud bucket for public uploaded files
- [Deletion System](deletion_system.md) -- Soft delete, permanent delete, cascading, and foreign key actions
- [SEO Metadata](seo_metadata.md) -- SEO, Open Graph, and Twitter Card conventions for public views

## Features

- [Analytics](analytics.md) -- Visitor events, conversion tracking, and attribution reporting
- [A/B Testing](ab_testing.md) -- Experiments, variant assignment, and conversion measurement
- [Recurring Events](recurring_events.md) -- Virtual/materialized instance pattern for recurring events
- [Subscription Tiers](subscription_tiers.md) -- Subscription management and feature-based access control
- [Product Purchase Hooks](product_purchase_hooks.md) -- Plugin hooks triggered on product purchase
- [Product Requirements](product_requirements.md) -- Collecting data from buyers at checkout
- [Questions & Surveys](questions_surveys.md) -- Built-in questionnaire system: question types, surveys, answer storage
- [Social Features](social_features.md) -- Like/favorite, block, report, and messaging systems
- [Scheduled Tasks](scheduled_tasks.md) -- Cron-based task runner and task development
- [REST API](api.md) -- API authentication, endpoints, and usage
- [Joinery AI Plugin](/plugins/joinery_ai/docs/overview.md) -- LLM-driven recipes and the generic `query_model` read surface
- [ScrollDaddy Plugin](/plugins/dns_filtering/docs/overview.md) -- DNS filtering: unified block model, tier gating, editor UI, resolver flow

## Extensibility

- [Plugin and Theme Developer Guide](plugin_developer_guide.md) -- Plugin architecture, routing, and theme overrides
- [Component System](component_system.md) -- Reusable page components with admin configuration
- [Creating Components from Themes](creating_components_from_themes.md) -- Extracting theme sections into components
- [Theme Integration Instructions](theme_integration_instructions.md) -- Step-by-step theme setup and integration

## Operations

- [Deploy and Upgrade](deploy_and_upgrade.md) -- Deployment, upgrades, and installation
- [Publish/Upgrade System Analysis](publish_upgrade_system_analysis.md) -- How upgrade packages are built and distributed
- [Server Manager](/plugins/server_manager/docs/overview.md) -- Remote server management and the Go agent
