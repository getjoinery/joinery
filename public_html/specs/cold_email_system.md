# Cold Email Outreach System — Spec

## Reference Inspiration

> "I've been running cold email campaigns for clients for 3 years and the biggest shift I've seen isn't the tools. It's what actually gets a reply.
> Personalization used to mean scraping a name and company from LinkedIn, dropping it in the first line, and hitting send.
> "Hey {FirstName}, I noticed {Company} and thought..."
> That worked in 2022. It's dead now. Everyone's doing it and prospects can spot a mail merge from the subject line.
> What changed for me was treating personalization like actual research instead of a data field.
> Here's what I started doing:
> → I scrape the prospect's entire website. Not just the homepage. Blog posts, service pages, case studies, about page, even their contact form if it's there.
> → Then I feed all of that into OpenAI and have it analyze what they actually do, who they serve, and what problems they're likely dealing with.
> The AI doesn't just summarize. It finds the specific details nobody mentions in generic outreach.
> So instead of "I saw you work in logistics," the email opens with "Noticed you handle cross border freight into Mexico. Your blog mentioned customs delays eating 15% of delivery windows."
> That's the kind of line that gets opened because it doesn't sound like 500 other emails they got that week.
> The reply rates went from 2-3% with generic personalization to 8-10% with actual research.
> One prospect replied last week: "Your email won because you actually read our site. Everyone else sent the same template."
> The system I built does this automatically. Scrapes the website. Analyzes every page. Generates icebreakers that reference non-obvious details.
> It writes openers like a human who spent 20 minutes studying their business, except it does it for 1,000 prospects in an hour.
> Here's what I learned building this:
> Small prompt details make a massive difference. Having OpenAI shorten company names naturally (say "Stripe" not "Stripe Inc.") and reference specific pages beyond the homepage makes it feel real.
> The difference between "I saw your website" and "I saw your freight tracking dashboard lets customers get ETAs without calling" is everything.
> One feels like spam. The other feels like someone did their homework.
> I built the entire workflow in n8n with OpenAI handling the research and personalization.
> It's how I'm booking 15-20 qualified calls weekly for clients without hiring SDRs or spending hours writing emails.
> If you're still doing mail merge personalization and wondering why your reply rates are stuck at 2%, this is why.
> Prospects don't respond to fields. They respond to relevance."

---

## Overview

A plugin that enables AI-powered cold email campaigns where personalization is generated from real research — scraping each prospect's website, analyzing the content with an LLM, and producing icebreakers that reference non-obvious, specific details. The goal is reply rates of 8–10% vs. the 2–3% typical of mail-merge personalization.

This is a platform-level plugin, not product-specific. Operators running the Joinery platform can install it to offer cold outreach as a feature for their users or use it internally.

---

## Core Concepts

### Prospect
A target contact for outreach. Has at minimum:
- Name (first/last)
- Email address
- Company name
- Website URL

Additional enrichment fields (job title, LinkedIn URL, notes) are stored but optional.

### Campaign
A named outreach sequence with:
- A set of prospects
- One or more email templates (the "sequence steps")
- Sending schedule / throttle settings
- Status: draft, active, paused, complete

### Scrape Job
An async task that crawls a prospect's website and stores the raw text content of each page discovered. Triggered when a prospect is added to a campaign or manually requested.

### Research Summary
An LLM-generated structured analysis of a prospect's scraped content. Includes:
- What the company does (in plain language)
- Who they serve (target customer profile)
- Likely pain points
- Non-obvious specific details (quotes, stats, product features, niche claims)
- Candidate icebreaker lines (2–4 options ranked by specificity)

### Icebreaker
A single opening sentence (1–2 sentences max) referencing a specific, non-generic detail from the prospect's site. Replaces the `{icebreaker}` variable in email templates.

### Email Template
A message body with merge variables. At minimum: `{first_name}`, `{company}`, `{icebreaker}`. Supports multi-step sequences (follow-up emails if no reply).

---

## Plugin Architecture

**Plugin name:** `cold_email`

**Plugin directory structure:**
```
plugins/cold_email/
  plugin.json
  data/
    prospect_class.php          -- Individual prospect record
    multi_prospect_class.php
    campaign_class.php          -- Campaign record
    multi_campaign_class.php
    campaign_prospect_class.php -- Join: prospect ↔ campaign with status + generated content
    multi_campaign_prospect_class.php
    email_template_class.php    -- Sequence step templates
    multi_email_template_class.php
    scrape_page_class.php       -- One scraped page per record
    multi_scrape_page_class.php
    outbound_email_class.php    -- Log of sent emails + reply tracking
    multi_outbound_email_class.php
  logic/
    prospect_logic.php
    campaign_logic.php
    research_logic.php          -- Orchestrates scrape → analyze → icebreaker pipeline
  admin/
    admin_prospects.php         -- Prospect list, import, scrape status
    admin_campaigns.php         -- Campaign list, wizard
    admin_campaign_detail.php   -- Prospect roster, queue, send log
    admin_research.php          -- Per-prospect research view
  ajax/
    scrape_status.php           -- Polling endpoint for scrape progress
    generate_icebreaker.php     -- On-demand regeneration for single prospect
  includes/
    WebScraper.php              -- HTTP fetcher + page crawler
    ResearchAnalyzer.php        -- LLM integration for research summaries
    IcebreakerGenerator.php     -- Prompt + output formatting for icebreakers
    CampaignMailer.php          -- Sending logic with throttle + reply detection
  scheduled/
    scrape_runner.php           -- Processes pending scrape jobs (cron)
    campaign_sender.php         -- Sends queued emails on schedule (cron)
  views/                        -- (empty for now — no public-facing views)
```

---

## Data Model

### `cep_prospects` (Prospect)
| Column | Type | Notes |
|---|---|---|
| cep_id | serial PK | |
| cep_first_name | varchar(100) | |
| cep_last_name | varchar(100) | |
| cep_email | varchar(255) | unique |
| cep_company_name | varchar(255) | |
| cep_website_url | varchar(500) | Root URL to scrape |
| cep_job_title | varchar(255) | optional |
| cep_linkedin_url | varchar(500) | optional |
| cep_notes | text | operator notes |
| cep_scrape_status | varchar(50) | pending / running / complete / failed |
| cep_scrape_started_time | timestamp | |
| cep_scrape_completed_time | timestamp | |
| cep_research_status | varchar(50) | pending / complete / failed |
| cep_research_summary | text (JSON) | structured output from LLM |
| cep_created_time | timestamp | |
| cep_delete_time | timestamp | soft delete |

### `cec_campaigns` (Campaign)
| Column | Type | Notes |
|---|---|---|
| cec_id | serial PK | |
| cec_name | varchar(255) | |
| cec_status | varchar(50) | draft / active / paused / complete |
| cec_from_name | varchar(255) | Sender display name |
| cec_from_email | varchar(255) | Sending address |
| cec_daily_limit | int | Max emails per day |
| cec_send_hour_start | int | 0–23, UTC |
| cec_send_hour_end | int | 0–23, UTC |
| cec_created_time | timestamp | |
| cec_delete_time | timestamp | |

### `ccp_campaign_prospects` (Campaign ↔ Prospect join)
| Column | Type | Notes |
|---|---|---|
| ccp_id | serial PK | |
| ccp_cec_campaign_id | int FK | |
| ccp_cep_prospect_id | int FK | |
| ccp_status | varchar(50) | queued / sent / replied / bounced / unsubscribed / skipped |
| ccp_icebreaker | text | Selected icebreaker for this prospect in this campaign |
| ccp_icebreaker_candidates | text (JSON) | All generated candidates |
| ccp_sequence_step | int | Which step in sequence (1, 2, 3…) |
| ccp_next_send_time | timestamp | When to send the next step |
| ccp_added_time | timestamp | |

### `cet_email_templates` (Email Template / Sequence Step)
| Column | Type | Notes |
|---|---|---|
| cet_id | serial PK | |
| cet_cec_campaign_id | int FK | |
| cet_step_number | int | 1 = first touch, 2 = follow-up, etc. |
| cet_delay_days | int | Days after previous step before sending |
| cet_subject | varchar(500) | Supports merge vars |
| cet_body_text | text | Plain text. Supports merge vars |
| cet_body_html | text | HTML version (optional) |
| cet_created_time | timestamp | |
| cet_delete_time | timestamp | |

### `csp_scrape_pages` (Scraped Page)
| Column | Type | Notes |
|---|---|---|
| csp_id | serial PK | |
| csp_cep_prospect_id | int FK | |
| csp_url | varchar(1000) | Page URL |
| csp_page_title | varchar(500) | |
| csp_content_text | text | Extracted text (strip HTML) |
| csp_scraped_time | timestamp | |

### `coe_outbound_emails` (Sent Email Log)
| Column | Type | Notes |
|---|---|---|
| coe_id | serial PK | |
| coe_ccp_id | int FK | campaign_prospect join record |
| coe_cet_template_id | int FK | |
| coe_sent_time | timestamp | |
| coe_message_id | varchar(500) | SMTP Message-ID for reply threading |
| coe_opened_time | timestamp | if tracking pixel used |
| coe_replied_time | timestamp | set when reply detected |
| coe_bounce_code | varchar(50) | if bounced |

---

## Scraping Pipeline

1. **Trigger:** Prospect added to campaign, or manual re-scrape requested.
2. **Crawler:** Start at `cep_website_url`. Follow internal links up to a configurable page limit (default: 30 pages). Exclude: PDFs, images, JS/CSS assets, external domains.
3. **Content extraction:** Strip HTML tags, extract visible text. Store each page as a `csp_scrape_pages` record.
4. **Completion:** Set `cep_scrape_status = complete`. Queue research analysis.

**Constraints:**
- Respect `robots.txt` (operator-configurable to skip for legitimate research use).
- Configurable request delay between pages (default: 500ms) to avoid hammering small sites.
- Timeout per page: 10 seconds.
- Max content per page stored: 50,000 characters (truncate).

---

## Research & Icebreaker Pipeline

After scraping is complete:

1. **Aggregate content:** Concatenate all scraped page text for the prospect. Prioritize: homepage, about, services/products, blog (most recent 3 posts), case studies. Truncate total to fit LLM context window (~40k tokens safe limit with GPT-4o).

2. **Research prompt:** Send to LLM (Claude or OpenAI — operator-configurable via setting) with a structured prompt requesting:
   - Plain-language description of the company (2–3 sentences)
   - Who they serve (target customer)
   - 3–5 likely pain points based on the content
   - 5–10 non-obvious specific details (stats, product features, named clients, niche claims, interesting blog takes)
   - 3–4 candidate icebreaker opening lines, ranked by specificity and non-obviousness

3. **Prompt engineering rules (from reference post):**
   - Use short company names naturally ("Stripe" not "Stripe Inc.")
   - Reference content beyond the homepage
   - Avoid anything that could appear in 500 other emails
   - Each icebreaker must cite a specific, verifiable detail

4. **Store:** JSON research summary in `cep_research_summary`. Store icebreaker candidates in `ccp_icebreaker_candidates`. Pre-select the top-ranked candidate as `ccp_icebreaker`.

5. **Operator review:** Admin UI shows the research summary and icebreaker candidates before sending. Operator can swap candidates or edit directly.

---

## Merge Variables

Email templates support:
- `{first_name}` — prospect first name
- `{last_name}` — prospect last name
- `{company}` — company name (short form)
- `{website}` — prospect website
- `{icebreaker}` — the selected icebreaker sentence
- `{sender_name}` — campaign from-name
- `{unsubscribe_link}` — auto-generated per-recipient unsubscribe URL

---

## Sending

- **Transport:** Use the platform's existing `SystemMailer` with SMTP settings already configured. Add campaign-specific override for from-name/from-email.
- **Throttle:** Respect `cec_daily_limit` per campaign. Track daily counts in campaign state.
- **Sending window:** Only send during `cec_send_hour_start` to `cec_send_hour_end` UTC.
- **Sequence steps:** After step 1 is sent, schedule step 2 at `next_send_time = sent_time + cet_delay_days`. If prospect replies (detected via reply-to monitoring or manual marking), stop sequence.
- **Bounces:** Mark prospect status as `bounced`. Do not retry.
- **Unsubscribe:** Every email includes an unsubscribe link. Clicking sets status to `unsubscribed` and suppresses all future sends to that email across all campaigns.

---

## Reply Detection

Two options (operator-configurable):

1. **Manual marking:** Admin marks a prospect as replied in the campaign detail UI. Simple but requires human monitoring.
2. **Inbound email monitoring:** If the platform's self-hosted email plugin is active, watch for replies to the from-address arriving in the inbound email table (`iem_inbound_emails`). Match on `Message-ID` / `In-Reply-To` header. Automatically set `coe_replied_time` and `ccp_status = replied`.

---

## Admin Interface

### `/admin/cold_email/prospects`
- List all prospects with scrape/research status badges
- Import via CSV (columns: first_name, last_name, email, company_name, website_url, job_title)
- Add single prospect form
- Per-row: trigger scrape, view research, add to campaign

### `/admin/cold_email/campaigns`
- Campaign list with status, prospect count, sent count, reply count
- Create campaign wizard: name → sending settings → add prospects → create sequence steps → review & activate

### `/admin/cold_email/campaign_detail?id=N`
- Prospect roster table: status, icebreaker preview, next send time
- Per-row: swap icebreaker, mark replied, skip prospect
- Send log tab: all sent emails with open/reply timestamps
- Stats bar: sent / opened / replied / bounced counts

### `/admin/cold_email/research?prospect_id=N`
- Full research summary for a prospect
- Icebreaker candidates with rank scores
- Raw scraped pages accordion (URL + extracted text preview)
- Regenerate icebreaker button

---

## Plugin Settings

Declared in `plugin.json`:

| Key | Default | Description |
|---|---|---|
| `cold_email_llm_provider` | `openai` | `openai` or `claude` |
| `cold_email_llm_model` | `gpt-4o` | Model to use for research/icebreaker generation |
| `cold_email_openai_api_key` | `` | API key (stored encrypted) |
| `cold_email_anthropic_api_key` | `` | API key (stored encrypted) |
| `cold_email_scrape_max_pages` | `30` | Max pages to crawl per prospect |
| `cold_email_scrape_delay_ms` | `500` | Delay between page fetches (ms) |
| `cold_email_respect_robots_txt` | `1` | 1 = respect, 0 = ignore |
| `cold_email_reply_detection` | `manual` | `manual` or `inbound_email` |

---

## Scheduled Tasks

Two tasks registered in the platform's scheduled task system:

1. **`cold_email_scrape`** — Runs every 2 minutes. Picks up to 3 pending scrape jobs and processes them.
2. **`cold_email_sender`** — Runs every 5 minutes. Sends queued emails within daily limits and sending windows.

---

## Open Questions

1. **LLM cost control:** Should there be a per-campaign or per-prospect budget cap on LLM calls? Research analysis could get expensive at scale.
2. **Multi-step sequence:** Is a linear sequence (step 1 → wait → step 2 → wait → step 3) sufficient, or do we need branching (e.g., "if opened but no reply, send variant B")?
3. **GDPR/CAN-SPAM compliance:** Should the unsubscribe list be global (across all campaigns, all operators on a multi-tenant install) or per-campaign?
4. **Prospect deduplication:** Dedup on email address globally, or allow the same email in multiple campaigns?
5. **Scrape privacy:** Some sites may not want their content scraped for AI analysis. What opt-out mechanism (if any) do we expose?
6. **Sending infrastructure:** Does this use the existing SMTP config, or should operators be able to configure a dedicated outbound SMTP (e.g., a separate domain/IP to protect reputation)?
