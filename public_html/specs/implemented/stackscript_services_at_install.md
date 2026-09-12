# StackScript: email and backups set up from the deploy form

**Status:** Implemented 2026-09-12. Live gate (a real Linode deploy with both
fields filled) **unrun** — the tools' happy paths need a real SMTP2GO key and
a real bucket, and the only honest proof is a deploy. Companion to
`specs/implemented/linode_stackscript.md`, whose "no credentials on the create
form" decision this reverses for two optional fields (owner's call,
2026-09-12): a field that is optional and does something concrete when
filled is not the wall of questions that decision refused.

## What this does for the user

Two things the setup wizard asks for are things the deployer already has in
hand when they fill in the deploy form: the sending key from their email
provider, and the bucket their backups should go to. With those on the form,
the install does the work and the wizard finds it done:

- **Email.** The wizard's Email step opens on "prove it works" (send a test,
  press "It arrived"), or on a DNS wait while the records propagate — not on
  an empty provider form. The From address, the owner's mailbox, the domain
  registered at the provider, the mail records published through the Linode
  token, the provider asked to verify: all done during the install.
- **Backups.** The wizard's Backups step shows the bucket as set and asks only
  for the recovery key.

What stays human: the delivery proof (a message the owner confirms arrived)
and the recovery key (a secret shown once). Neither can be done by a script
without lying about it.

## Fields (four, all optional)

| Field | Env var the handoff reads | What it does |
|---|---|---|
| SMTP2GO API key | `JOINERY_MAIL_API_KEY` (masked as `..._PASSWORD`) | email setup |
| Backblaze B2 bucket | `JOINERY_BACKUP_BUCKET` | backups target |
| Backblaze application key ID | `JOINERY_BACKUP_KEY_ID` | |
| Backblaze application key | `JOINERY_BACKUP_KEY` (masked) | |

The wrapper asks only what the quickstart's path needs: SMTP2GO and
Backblaze, no provider dropdowns. The handoff script is general — it also
reads `JOINERY_MAIL_PROVIDER` (default smtp2go), `JOINERY_BACKUP_PROVIDER`
(b2/s3/linode, default b2) and `JOINERY_BACKUP_REGION` — so another wrapper
or a hand-run install can name a different provider without touching the
tools.

## Design

**Thin wrapper, fat repo, and the work in the one place every path shares.**
The wrapper declares the fields and renames the masked ones. The handoff only
exports them — `JOINERY_DNS_CREDENTIAL` (the token as the JSON the credential
tool takes), `JOINERY_MAIL_API_KEY`, `JOINERY_BACKUP_*` — before `install.sh
site`. `_site_init.sh` does everything: seals the DNS credential over stdin,
runs the two tools with the secrets in the environment (never argv), unsets
them, and records each outcome in `config/install_services.txt`. So a hand-run
`install.sh site` with the same variables gets the same services (owner's
requirement, 2026-09-12), and Docker gets them because `install.sh` crosses
every `_site_init.sh` input into the container from one list
(`SITE_INIT_ENV_INPUTS`) — which fixed a standing gap: `JOINERY_INSTALL_BUNDLE`
had never crossed, so a Docker install always got the default bundle.
`install.sh`'s closing summary and the handoff's both read the outcomes file;
the first-task email notice prints only when email was not set up, and the
generated-password credentials file says the same.

**The tools run the wizard's own ceremony.** `utils/install_mail_provider.php`
calls the functions the wizard's POSTs call — `_setup_mail_register()` and
`_setup_mail_publish()` were extracted from `setup_logic.php` for exactly
this, so an installer and the wizard cannot drift. `utils/install_backup_target.php`
does what the wizard's "Save and test" does, through
`BackupTarget::complete_credentials()` (extracted from the Backups page logic).

**Neither service is a condition of the install.** A rejected key or an
unreachable bucket is reported in the closing summary, named as something the
wizard will ask for again, and the site is installed regardless. A failure
leaves nothing half-configured: the mail tool puts every setting it wrote back
to its previous value; the backup tool removes the target it created.

**The DNS token is consumed here when it is used here.** The mail tool
publishes the mail plan through `DnsInstallCredential` exactly as the wizard
would, consume-on-use. When the sending domain could not be registered at
the provider (no DKIM records in the plan yet), the credential is left for
the wizard's retry rather than spent on a half plan.

**Which providers qualify.** One a single key configures: it registers sending
domains through its API (`SendingDomainRegistrar`) and its settings group
declares exactly one secret. SMTP2GO and Mailgun do; SMTP does not and is
refused before anything is written.

**The From address is derived, not asked.** The admin address itself when it
is on the site's domain; otherwise its local part (letters and digits) on the
site's domain, `admin@` as the last resort. The wizard's own prefill uses the
owner's first name, which the install does not have. Changeable afterwards
under Settings, Email; the closing summary and the quickstart both say so.

## Defects found building it

- **B1** The first draft's revert cleared `email_service` and the key to blank
  instead of restoring what was there. Run against dev by the refusal test, it
  blanked a configured provider. Fixed: previous values are snapshotted from
  the table before the write and put back on any failure. Dev's two settings
  were restored by hand.
- **B2** The "exactly one secret" rule alone admitted SMTP (its one secret is
  the password), which then failed validation after the write. Fixed by
  requiring `SendingDomainRegistrar` as well, refused before any write.
- **B3** (pre-existing, found while crossing the new inputs) Docker installs
  never received `JOINERY_INSTALL_BUNDLE`; the env file crossed only the
  admin email, admin password and upgrade server. Fixed by the one list.

## Tests

- `tests/unit/installer_contract_test.php` — the four fields exist, the two
  secrets are password-named, the wrapper exports every field it declares,
  the handoff exports and runs no tool itself, `_site_init.sh` seals the
  credential over stdin and runs both tools with secrets in the environment
  on fresh installs only, neither is a condition of the install, outcomes
  are recorded and both summaries read them, every input `_site_init.sh`
  reads is on the Docker crossing list, the tools take no argv and refuse
  the web. The services block was also run against a fixture site root with
  stub tools (all good / both fail / nothing supplied) and the summary
  function against fixture outcome files.
- `tests/unit/install_service_tools_test.php` (db, dev-only) — the verdict /
  reason / exit-code contract, and that every refusal writes nothing.

## Docs updated

`docs/installation.md` (field table, the Site domain row corrected to
required, and a "Services set up at install" section for the command line), `docs/quickstart.md` (bucket created before the server; both keys
pasted on the form; wizard paragraph), `docs/backups.md` (installer-created
target), `docs/email_system.md` (the tool).

## Live gate

Deploy a Linode with all fields filled; expect the closing summary's Email
and Backups lines, the wizard's Email step on the prove or dns stage, the
Backups step showing the bucket set. Then a deploy with a wrong SMTP2GO key:
expect "NOT set up", the wizard's Email step on the form, and no provider
configured. Queue: `project_live_verification_queue`.
