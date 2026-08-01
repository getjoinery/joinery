# Recipes that ship with new installs

**Status:** Active spec, unbuilt.

## What we want

A fresh Joinery install arrives with no AI recipes at all. Someone who installs
the platform and turns on Joinery AI faces an empty page and a blank prompt box,
even though the two or three recipes worth having — triage your inbox, score
mail for phishing, put dated events on your calendar — are the same on every
install.

We want to curate a small set here and have them appear, ready to configure, on
every new install.

## A shipped recipe is a template, not a recipe

Most of a recipe is specific to the instance it was built on, so it cannot
travel:

| Field | Why it cannot ship |
|---|---|
| `rcp_owner_user_id` | points at a user on our instance |
| `rcp_source_config` | names a mailbox address that exists nowhere else |
| `rcp_model` | names a model the destination may not have |
| `rcp_enabled` | must never arrive switched on (below) |
| `rcp_allow_tainted_writes` | a security acknowledgment, not ours to give (below) |

What does travel is the useful part: which job it runs, the prompt, the
schedule, the iteration and token ceilings, the thinking level, and the model
controls. So what ships is a **template** — the judgement and the settings,
minus identity and binding.

## Two things must arrive switched off

**Enabled.** A recipe that starts reading someone's mail and spending tokens
the moment they install, without being asked, is not something we should ever
ship. Templates arrive disabled.

**Tainted writes.** All three email jobs declare `untrustedDigest()`, and for a
pipeline job the taint gate evaluates to tainted-capable unconditionally —
`record_verdict` counts as a write tool and the item digest counts as untrusted
content. The flag is enforced twice: `admin_edit_logic` refuses to save such a
recipe without it, and `RecipeRunner::checkTaintDrift()` fails the run before any
model call.

So triage and security scan **cannot run with it off**. That is exactly why a
template ships with it off and disabled: the recipe sits there inert and
readable, and the moment the operator enables it the save path walks them
through the acknowledgment. We never pre-accept a security posture on someone
else's behalf, and we never ship something that fails every run with a taint
error.

What the operator is accepting is narrower than the name suggests, and the
prompt should say so: in pipeline mode the model cannot choose what to write. It
returns one verdict for one message and the job writes a fixed field on that
same message — a label from the operator's own label set, a one-line summary, a
danger score, a calendar entry. It cannot be steered into modifying arbitrary
records the way an agent-mode recipe with write tools could.

## How they ship: declared, then seeded

The platform already does this three times — declared settings
(`PluginManager::syncSettings()`), declared scheduled tasks
(`ScheduledTaskRegistry::activateDeclared()`), and menus, where `CLAUDE.md` is
explicit that core items go in `admin_menus.json` and "never via direct database
inserts". Recipes follow the same road.

Declarations live in **`plugins/joinery_ai/recipes.json`**, a sibling of
`plugin.json` rather than a section inside it. Prompts are long, and keeping them
in their own file leaves `plugin.json` readable and makes a prompt edit a clean
diff.

```json
{
  "recipes": [
    {
      "key": "email_triage_default",
      "name": "Email triage",
      "pipeline_job": "email_triage",
      "prompt": "",
      "schedule_frequency": "hourly",
      "max_iterations": 25,
      "max_tokens": 5000,
      "monthly_token_cap": 200000,
      "thinking_level": "off"
    }
  ]
}
```

An empty `prompt` means "use the job's built-in default", which is the normal
case — a non-technical admin never writes or sees a prompt.

**Leave it empty. Do not paste prompt text in here.** The prompts that matter
already ship in the job classes (`defaultPrompt()`), where an upgrade can
improve them for every install, including ones seeded years earlier. A prompt
written into this file is a one-time snapshot: seeding is create-only, so once an
install has the row, a better prompt shipped later never reaches it. Putting
prompt text here converts a maintainable prompt into a frozen one.

**The schedule is advisory, and on some mailboxes it is ignored entirely.** A
recipe whose job reads sealed content never runs from cron — the dispatcher
skips it and it runs in slices inside the owner's unlock window instead
(specs/in_window_deferred_work.md). So a template declaring `hourly` will honour
that on an ordinary mailbox and quietly disregard it on an encrypted one. The
edit form already explains this per recipe; the template must not be read as a
promise the platform will not keep.

**Model controls ship unset.** `temperature` and `top_p` fall back to the
site's plugin-setting defaults when null. Shipping explicit values would
override tuning the operator did globally, to no benefit — a curated recipe has
no opinion about someone else's model settings. Same for `rcp_workspace`, which
pipeline recipes do not use at all.

Seeding runs in the plugin sync, beside the settings and task seeders it
mirrors.

**Seed once, never overwrite.** A declaration creates a recipe if one with that
key does not exist, and otherwise does nothing. An upgrade must never replace a
prompt the operator tuned. This is deliberately unlike declared settings, which
do get updated and removed.

**A deleted template stays deleted.** The existence check must count
soft-deleted rows, not just live ones. Checking only live rows means an operator
who deletes a template they do not want gets it back at the next upgrade, for
ever — the system quietly overruling a decision they made on purpose. Matching
on `rcp_declared_key` regardless of `rcp_delete_time` makes deletion mean what
it says.

**Removing a declaration does not delete anything.** Once an operator has a
recipe, it is theirs. A withdrawn template stops arriving on new installs and
leaves existing ones alone.

**The owner** is resolved at seed time as the lowest-numbered active
permission-10 admin, **excluding the system user**. `User::USER_SYSTEM` is id 2
and carries permission 10, so a naive "lowest permission-10 user" picks it
whenever the system row seeds before the human one — and every shipped recipe
would then be owned by a service account, executing its writes as that account
rather than as a person who can be held responsible for them. With no human
admin — an install still mid-setup — seeding is skipped and retried at the next
sync, rather than creating an ownerless or system-owned recipe.

## Marking a recipe to ship

Templates are authored the same way any recipe is: build it in the admin, get it
right, then mark it. A **Ship with new installs** action on the recipe page
writes the current recipe into `recipes.json`, stripping the five fields above
and assigning a stable key.

The file is under version control on the instance that publishes, so the change
shows up as an ordinary diff to review and commit — the same round trip as any
other declared default.

### Only on an instance that publishes

The action is meaningless anywhere else. On a customer install,
`plugins/joinery_ai/recipes.json` is replaced wholesale by the next upgrade, so
an edit there is silently discarded.

The platform already names this distinction. `utils/upgrade.php` decides whether
an instance publishes or consumes with:

```php
$settings->get_setting('upgrade_server_active') || PluginHelper::isPluginActive('server_manager')
```

The action is gated on that same predicate, extracted into one named helper so
the two cannot drift apart. Everywhere else the control is simply absent — not
disabled with an explanation, because on a consuming install it is not a thing
the operator is missing out on.

Seeding is the opposite and is **not** gated: it must run on every install,
which is the entire point.

## What the operator sees

A seeded recipe appears in the recipe list marked as a template awaiting setup —
disabled, no mailbox chosen. Opening it shows the prompt and settings already
filled in, with the mailbox picker empty and the enable and tainted-writes
controls off.

The list should distinguish "shipped with your install, not yet set up" from "a
recipe you disabled", because they look identical otherwise and mean opposite
things.

## Data model changes

| Table | Change |
|---|---|
| `rcp_recipes` | `rcp_declared_key` varchar, nullable, unique |

`rcp_name` is not unique, so without a stable key a re-sync cannot tell an
already-seeded recipe from a new one and would duplicate every template on every
upgrade. The column is null for anything an operator created themselves.

## Docs to update

- **`plugins/joinery_ai/docs/overview.md`** — a Shipped recipes section: the
  declaration format, seed-once semantics, what cannot travel and why, and the
  publisher-only marking action.
- **`docs/plugin_developer_guide.md`** — note alongside declared settings and
  tasks that a plugin can also declare seed rows, with recipes as the example.

## Tests

- a declaration seeds one recipe, disabled, with no mailbox, no model, tainted
  writes off, and the declared prompt and caps;
- a second sync creates nothing and changes nothing, including after the
  operator has edited the prompt;
- removing a declaration leaves the existing recipe untouched;
- a template the operator DELETED is not resurrected by the next sync;
- seeding never assigns `User::USER_SYSTEM` as the owner, even when it is the
  lowest-numbered permission-10 row;
- seeding with no permission-10 user creates nothing and does not throw;
- a seeded recipe is skipped by the dispatcher while disabled;
- enabling one without accepting tainted writes is refused, and the refusal
  explains what is being accepted;
- the marking action writes the file on an upgrade server, strips all five
  non-travelling fields, and is absent on a consuming install.

## Rejected alternatives

**Add `rcp_recipes` to `create_install_sql.php`'s essential tables.** That
exports the table wholesale, so all 27 recipes currently on dev would ship,
including test junk — and it makes our dev database the source of truth for what
customers receive. Whole-table export is right for reference data (countries,
timezones, email templates) and wrong for curated configuration.

**A per-row `ship_with_install` flag exported from the dev database at publish
time.** Closer, but the shipped set still lives in a database rather than in
version control, so nobody can review what changed in a release, and a rebuilt
dev database loses the curation.

**Seed them enabled and bound to the first mailbox found.** Guesses at
consent, at which mailbox matters, and at whether the operator wants AI reading
their mail at all.

## Decisions

- Templates ship disabled with tainted writes off; enabling is where the
  operator accepts the posture.
- Declarations live in `recipes.json`, seeded on plugin sync, create-only.
- The marking action exists only on an instance that publishes, gated on the
  existing upgrade-server predicate.
- Deletion is respected: a removed template is never re-seeded.
- The system user is never a recipe owner.
