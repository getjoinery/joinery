# FUTURE: Personal AI Recipes — Unbuilt Directions

**Status:** Brainstorm / pre-spec. Not scheduled for implementation.

The recipe runner itself is built and lives in the `joinery_ai` plugin (`includes/RecipeRunner.php`, `tasks/RecipeDispatcher.php`, `data/recipes_class.php`, `recipe_tools/`). What follows is the set of directions that have no code behind them yet.

---

## 1. MCP server support

Instead of the hardcoded tool registry in `includes/RecipeToolRegistry.php`, recipes could use any MCP server configured in settings. Makes the tool surface extensible without writing a new `RecipeToolInterface` implementation for every capability.

Open: how per-recipe tool allowlisting (`rcp_allowed_tools`) extends to tools whose names aren't known until a server is contacted.

## 2. Approval queues for outbound actions

Recipes today are read-only to the world — outputs go to the owner's dashboard or email. Once a recipe does things beyond reporting (drafting replies, posting to calendar, filing tickets), those outputs should become *proposals* that queue in the dashboard for one-click approval rather than firing directly.

Relates to `rcp_allow_tainted_writes`, which currently gates writes at the recipe level rather than per-action.

## 3. Household / partnership mode

Shared recipes across two or more users who trust each other: calendar-aware recipes that see both partners' schedules, a shared approval queue. Recipes are currently single-owner (`rcp_owner_user_id`). Would lean on the existing permission/tier system.

---

## Philosophy notes

### Life-admin vs code-admin

Claude Code, OpenCode, Cowork, et al. are *code-admin* tools — dev-first, CLI-native, keyboard-forward. Recipes claim the inverse niche: *life-admin* agents for when you're not at your desk. The distinctiveness is **context** (this is your personal platform, it knows you) and **channels** (email, dashboard, scheduled delivery, not chat).

### Data sovereignty, redefined

Sovereignty here means "your data stays yours," not "nothing ever leaves the network." Pulling news/stock/venue data from external APIs is fine; those inputs aren't yours. What matters is that your prompts, your accumulated history, your emails, your DB contents don't leak to a cloud LLM. The line is drawn at what the LLM *sees in the prompt* — generic prompts can go to cloud, prompts containing private state must go local.

### Recipes ≠ persistent agents

A recipe is a function: trigger → gather → format → deliver → exit. A persistent agent has continuity over time, judgment over state, memory of prior runs. Keeping these distinct matters when weighing anything above. The workspace field (`rcp_workspace`) is deliberately a notepad, not a memory subsystem; the memory thread has since shipped — see `specs/implemented/joinery_ai_memory.md` (`mem_memories`, remember/recall/forget recipe tools, automatic chat context).
