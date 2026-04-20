# FUTURE: Personal AI Recipes Plugin

**Status:** Brainstorm / pre-spec. Not scheduled for implementation.

## Vision

A Joinery plugin that turns Joinery into a personal AI operating system for **life-admin tasks** — not coding, not business automation. The thesis: most agent frameworks are built for developers at keyboards running tool loops against code. There's a quieter niche for *background* agents that handle the non-code parts of a life — local events, news digests, research, watchlists — reachable through channels humans already live in (email, web dashboard).

The pitch is deliberately small: **smart scheduled tasks with LLM + tools + email delivery**. Not Claude Code. Not Claude Cowork. Not a chat UI. A recipe runner.

### Why Joinery is well-positioned

- Scheduled tasks system already exists — no cron layer to build
- Email plumbing (send + inbound via Mailgun webhook) already exists
- Admin UI framework for the dashboard already exists
- Plugin system means each tool surface (web search, DB query, scraper) can be its own plugin
- Settings/tier system handles model selection and cost governance for free
- Analytics events provide an audit trail with no new work

### Non-goals

- Feature parity with Claude Code, OpenCode, or Cowork
- Real-time chat UX
- Multi-user SaaS product (single-user personal tool at first)
- Heavy agent frameworks with memory, persistence, tool-loop depth
- Business model / monetization (not a concern at this stage)

---

## MVP

**Goal for this section: hammer out the smallest version that's actually useful to run at home this month.** Everything here is a proposal — push back freely.

### Target user story

> "Every Monday at 7am, search for live music at these 5 venues in Nashville this week, write me an email summary with links."

> "Every morning at 6am, give me a news digest on these topics: AI policy, self-hosted tools, Nashville local news."

> "Once a week, check NVDA / AAPL / whatever, summarize earnings news and analyst takes, email me."

The MVP must handle all three of these well and nothing else.

### Proposed MVP scope (IN)

- **Recipes** defined in admin UI — a row in a table, essentially:
  - Name
  - Schedule (cron string or simple interval)
  - Prompt (the instructions the LLM gets)
  - Allowed tools (checkboxes against a known tool registry)
  - Delivery (email address — defaults to owner)
  - Model endpoint (dropdown: Claude Sonnet, Haiku, Opus to start)
  - Enabled / disabled flag
- **Recipe runner** — invoked by scheduled tasks system, does a bounded tool loop (max N iterations, max M tokens, timeout T), formats result, emails owner
- **Run history** — every execution logged with: timestamp, duration, token usage, cost estimate, output, any errors
- **Dashboard** — list of recipes with last-run status, run-now button, output viewer for each run
- **Tool set (minimum viable):**
  - `web_search(query)` — via Brave Search, Serper, or similar
  - `fetch_url(url)` — retrieve and extract readable content from a webpage
  - `get_workspace()` / `set_workspace(content)` — read/write the recipe's persistent scratchpad (see Joinery-native capabilities below)
  - `get_recent_outputs(recipe_name, n)` — look at what other recipes (or this recipe) produced recently
  - `save_note(title, content)` / `get_my_notes(search)` — read/write to a notes table visible in the Joinery UI
  - Six tools total. Covers the three target stories plus the cross-recipe and persistent-state cases the Joinery-native section enables.
- **Model backend:** Claude API only. Configured via existing settings. One endpoint, one auth flow.

### Joinery-native capabilities (the differentiation at MVP)

Most agent tools are batteries-not-included — blank scripts that call APIs and email you. Joinery is already a platform with a database full of *your stuff* — so recipes should participate in it, not just sit beside it. These four hooks cost little to build and make Joinery recipes structurally more capable than standalone agents from day one.

**Framing:** other agents live in a vacuum and can only email you. Joinery agents live inside your personal data platform and can participate in it.

**1. Per-recipe persistent workspace.**
A text/JSON blob on the recipe row. The LLM reads it at the start of each run, overwrites it at the end. Solves 80% of "remember what you already did" cases without a memory subsystem — no vector store, no RAG, just the LLM curating its own notepad. Covers: "don't repeat news stories from last week," "bands I've already seen live," "stocks I've already researched this quarter," "topics that turned out to be duds."

**2. Recipe outputs as a queryable data source.**
Recipe runs are already logged (see `rcr_recipe_runs`). Expose a tool — `get_recent_outputs(recipe_name, n=5)` — so recipes can read each other. A "weekly digest of digests" becomes trivial. The stock research recipe can see what the news digest surfaced. Near-zero additional work; just a thin tool wrapping existing data.

**3. Dashboard widgets, not just email.**
Recipe outputs render as cards on a personal landing page using the existing theme/view system. The music recipe is a "this week's live shows" card. News digest becomes a panel. Stock research becomes a tile. The dashboard isn't a fleet-management screen — it's your actual home page, built by your recipes. Email delivery remains available; dashboard is the default surface, email is opt-in per recipe.

**4. Joinery-native tools — the platform as a toolset.**
At MVP minimum: a `save_note(title, content)` / `get_my_notes(search)` pair that writes into a notes table visible and editable in the Joinery UI. This creates a loop pure cron+LLM+email cannot: *agent writes → you edit → next run reads your edits.* Starting small means starting with notes. Future iterations expand the Joinery-as-toolset surface to events, contacts, profile data, community activity, etc. (per the "Native AI surfaces per plugin" future direction).

### Proposed MVP scope (OUT — explicitly deferred)

- Local inference (Ollama, llama.cpp) — later, for private-data recipes
- Email-as-UI (configure recipes by emailing an agent) — later
- Inbound email triage / reading your inbox — later
- Agent memory across runs — later
- Approval queues — recipes are read-only to the world, outputs go only to owner
- Multi-user / household mode — single-user only
- MCP server support — later; use a hardcoded tool registry for MVP
- Agent-to-agent communication — not needed for recipe model
- Complex tool composition / chaining beyond what a tool-calling loop already provides
- Cost caps, tier gating — soft cap per run (max tokens) is enough for MVP
- Custom model providers beyond Anthropic — later

### Open MVP questions to hammer out

1. **Recipe definition: UI vs config file vs DB row?** Starting proposal: admin UI writing to DB table. Is that right, or is raw YAML / JSON files in `plugins/personal_ai_recipes/recipes/` more your speed?
2. **Tool loop engine: write from scratch or use Anthropic's tool-use API natively?** Starting proposal: Anthropic SDK's tool-use loop, wrapped thinly. No LangChain-style abstraction.
3. **Output format: always email, or also dashboard-only?** Starting proposal: every run produces a dashboard card; optionally also emails. Some recipes ("is my thing in stock") might not need email.
4. **Failure handling:** if a tool call fails or the LLM times out, what happens? Starting proposal: log the error, email a brief failure notice once per day max, don't spam on every failed run.
5. **How are recipes authored initially — by you directly in the UI, or is there a "setup helper" conversation?** Starting proposal: direct UI for MVP; setup helper is a future enhancement.
6. **Is "run now" enough, or do we need dry-run / test mode?** Starting proposal: run now is the test mode. Real recipes run on schedule.
7. **Scheduled tasks integration:** register one scheduled task per recipe, or one dispatcher task that checks all recipes every minute? Starting proposal: one dispatcher, checks recipes table, fires due ones.

### Rough data model sketch (proposal, not committed)

```
rcp_recipes
  rcp_id
  rcp_name
  rcp_prompt
  rcp_schedule              -- cron string
  rcp_allowed_tools         -- JSON array of tool names
  rcp_model                 -- 'claude-sonnet-4-7' etc.
  rcp_delivery_email        -- nullable; defaults to owner
  rcp_delivery_dashboard    -- bool
  rcp_enabled
  rcp_max_iterations        -- tool loop cap, default 10
  rcp_max_tokens            -- token budget per run, default 20000
  rcp_workspace             -- persistent scratchpad; LLM reads at start, overwrites at end
  rcp_render_as_widget      -- bool; show output as dashboard card
  rcp_created_time, rcp_modified_time, rcp_delete_time

rcr_recipe_runs
  rcr_id
  rcr_rcp_recipe_id
  rcr_started_time
  rcr_completed_time
  rcr_status                -- pending, running, success, failed, timeout
  rcr_input_tokens, rcr_output_tokens
  rcr_cost_estimate
  rcr_output                -- the rendered result
  rcr_error                 -- null unless failed
  rcr_tool_calls            -- JSON log of every tool invocation
```

### "Done" looks like

- You can log in, create a recipe, set a schedule, pick tools, save
- Recipe runs on schedule; output lands on your dashboard and (optionally) in your email
- A recipe can remember things across runs via its workspace
- A recipe can read another recipe's recent outputs
- A recipe can save a note you can see, edit, and have the next run read back
- Dashboard shows you what each recipe did, what it cost, and lets you re-run
- Three target stories above all work end-to-end
- No local inference required, no private-data handling required, no multi-user required

---

## Beyond MVP (future directions, in rough priority order)

### 1. Local inference for private-data recipes

Once the MVP is proving useful with cloud APIs, add Ollama / llama.cpp endpoint as an alternative model backend. Per-recipe flag `requires_local_model`; private-data recipes (inbox reading, memory-heavy, org-data) route there. Qwen 2.5 72B or Llama 3.3 70B on a 24GB GPU or Mac Studio is the target hardware profile. Slow inference is fine — recipes run in the background, 1-hour budget is generous.

Sovereignty framing: *your* data stays yours. Calling external APIs for inputs (news, stock data, venue listings) is fine because that data isn't yours to begin with. The line is drawn at what the LLM *sees in the prompt* — generic prompts can go to cloud, prompts containing your email / memory / DB contents must go local.

### 2. Email as UI

Inbound email via existing Mailgun webhook plumbing. You email `agents@yourdomain` with a plain-English description of what you want; a setup agent drafts a recipe and emails back for confirmation. You reply "yes" and it's live. Changing or disabling recipes works the same way. Dashboard becomes a tool for peeking and kill switches; email is the primary interface.

### 3. Memory / accumulated context

Some recipes benefit from "what did you tell me about this last week" — news digests that don't repeat stories, research agents that build on prior runs. Simple version: each recipe has an append-only `memory` text field the LLM can read and write. Not vector-stored, not RAG — just a rolling summary the LLM curates itself.

### 4. Approval queues for outbound actions

Once recipes do things beyond emailing you (drafting replies, posting to calendar, filing tickets), outputs become *proposals* that queue in the dashboard. One-click approve sends them. Extends the existing social_features approval patterns.

### 5. Inbox triage agents

Recipes that read your inbox and draft responses, categorize, extract action items. Requires local inference (your email is private data). Leans on the inbound email infrastructure.

### 6. Household / partnership mode

Shared agents across two or more users who trust each other. Calendar-aware agents that see both partners' schedules. Shared approval queue. Uses the existing permission/tier system.

### 7. MCP server support

Instead of a hardcoded tool registry, agents can use any MCP server configured in settings. Makes the tool surface extensible without writing new Joinery tool plugins.

### 8. Native AI surfaces per plugin (the institutional bet)

Separate and larger bet: every Joinery plugin exposes an AI-callable surface. Events plugin: "summarize upcoming events in our voice." Social plugin: "find members matching X." Email plugin: "draft a reply in the org's tone." Recipes (or admins, or automations) can compose these. Joinery becomes AI-native infrastructure for community/membership orgs — AI threaded through every plugin, not a chatbot bolted on.

This is a much bigger and more strategic bet than the personal recipe runner, but the two dovetail: the tool abstractions, cost governance, and permission patterns you build for personal recipes are exactly the primitives institutions would need.

---

## Philosophy notes

### Life-admin vs code-admin

Claude Code, OpenCode, Cowork, et al. are *code-admin* tools — dev-first, CLI-native, keyboard-forward. This plugin claims the inverse niche: *life-admin* agents for when you're not at your desk. The distinctiveness is **context** (this is your personal platform, it knows you) and **channels** (email, dashboard, scheduled delivery, not chat).

### Data sovereignty, redefined

Sovereignty here means "your data stays yours," not "nothing ever leaves the network." Pulling news/stock/venue data from external APIs is fine; those inputs aren't yours. What matters is that your prompts, your accumulated history, your emails, your DB contents don't leak to a cloud LLM. This reframing makes the MVP feasible — most recipes don't touch private data and can happily use Claude API. Local inference becomes an *unlockable capability* for the subset of recipes that read your private state.

### Cron-scheduled recipes ≠ persistent agents

Keeping these distinct matters. A recipe is a function: trigger → gather → format → deliver → exit. No memory, no state, no loop. A persistent agent has continuity over time, judgment over state, memory of prior runs. Recipes are the MVP target. Persistent agents are a much harder problem deferred to "Beyond MVP #3."

### Start where friction is lowest

The MVP deliberately uses Claude API (not local), hardcoded tools (not MCP), single-user (not households), admin-UI authoring (not email setup). Every one of these is a future enhancement, but building them upfront delays the moment you're actually running useful recipes at home. Ship the smallest thing, use it, let actual usage reveal which deferred features matter most.
