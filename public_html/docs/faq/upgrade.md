# Upgrading Joinery

## How do I upgrade my site?

Open the Updates page at `/admin/admin_updates` (System > Updates; superadmin only). It shows the version your site runs and the version its upgrade source offers. Press the update button and the page shows the upgrade's transcript as it runs.

If your site is run by a management node, the page says its updates come from there and shows no update button. Upgrade it from the management node instead.

See: docs/deploy_and_upgrade.md > The Updates page

## How do I upgrade from the command line?

Run the site's own upgrade script as root:

```bash
# Bare-metal
php /var/www/html/mysite/public_html/utils/upgrade.php --verbose

# Docker
docker exec mysite php /var/www/html/mysite/public_html/utils/upgrade.php
```

`--verbose` prints every step. To move a bare-metal site forward, always use `upgrade.php` rather than running `install.sh site` again over it.

See: docs/deploy_and_upgrade.md > upgrade.php

## Where does my site get its upgrades from?

From the address in the `upgrade_source` setting. The installer sets it to wherever the site was installed from: `https://getjoinery.com`, which serves stable releases, unless `--upgrade-server=URL` was given at install.

Every archive is checked against the release signing key before it is installed, so `upgrade_source` only chooses where a verified archive is fetched from. Pointing it at a server that serves anything else gets a refused upgrade.

See: docs/deploy_and_upgrade.md > Where a site's `upgrade_source` comes from

## What happens if an upgrade fails?

The site goes back to the release it was running, automatically. Before switching, the upgrade checks the new code; after switching, it runs database migrations and a quick check that the site loads. If any step fails, the whole previous release is put back, and the broken copy is kept in a `public_html_failed_*` directory for diagnosis.

The rollback restores the code but not database changes, because migrations already ran. Those changes are additive, so the previous code normally runs fine. Upgrade the site forward once the problem is fixed rather than leaving it on the rolled-back release.

See: docs/deploy_and_upgrade.md > upgrade.php

## Why did my upgrade stop with "Upgrade refused"?

The downloaded release failed its signature check, so nothing was installed. Every archive must carry a signed list of its files, signed by a key your site holds, with every file matching. Any other result (unsigned, signed by an unknown key, a changed or missing file) stops the upgrade with `Upgrade refused: … verdict: <name>: <why>`. The verdict says which check failed.

Check that `upgrade_source` points at the server you expect.

See: docs/deploy_and_upgrade.md > What root verifies before a deploy

## I pressed the update button but nothing happens. Why?

Upgrades from the browser are carried out by the host converger, a root timer on the server. A machine without a working converger cannot upgrade or install anything from the browser. The request is kept and runs when the converger comes back.

`install.sh` installs the converger on every new server. If it is missing or has been silent for a day, the admin header and the health panel say so, with the command to reinstall it.

See: docs/deploy_and_upgrade.md > A host installer runs only out of a package we built

## How much free disk space does an upgrade need?

Three times the size of the site's live code, and never less than 500 MB. The upgrade checks this before it downloads anything.

See: docs/deploy_and_upgrade.md > Directory Structure

## Will an upgrade overwrite a plugin or theme I changed?

Not if it is marked as a local copy. A plugin or theme with `receives_upgrades` set to `false` is kept as it is across every upgrade. Uploaded extensions get this setting automatically. **Allow upgrade** on the Plugins and Themes pages lets the next upgrade replace the copy.

The Updates page lists every local copy under **Kept out of updates**, with the version the upgrade source ships.

See: docs/deploy_and_upgrade.md > Extension Distribution Flags

## Why did a plugin I don't have not arrive with the upgrade?

An upgrade refreshes only the plugins and themes already on your site. To add one you do not have, install it from the marketplace at `/admin/admin_marketplace` (**Add New** on System > Plugins or System > Themes).

See: docs/deploy_and_upgrade.md > Marketplace

## How do I upgrade sites from a management node?

On the management node, open the node's page in Server Manager and go to the **Updates** tab. It compares the node's version with the management node's.

- **Apply Update** upgrades that one site.
- **Upgrade All Sites on This Host** queues a separate upgrade for every enabled site on the same host, so one failure does not affect the others. To skip a site, disable it on its node page first.

See: docs/deploy_and_upgrade.md > Deployment self-update (read before editing `upgrade.php`)
