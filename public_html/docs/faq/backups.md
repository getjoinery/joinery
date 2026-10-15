# Backups

## How do I set up backups?

Open **Admin > System > Backups** (`/admin/admin_backups`), or the Backups step of the setup wizard. You need two things:

1. **A backup target**: an S3-compatible bucket and its access key. The site tests the bucket before it saves it.
2. **A recovery key**: the page makes one in your browser, shows you the secret half once, and asks you to paste it back to prove you saved it.

Once both exist, nightly backups switch on by themselves. Until the recovery key is proven, encrypted backups refuse to run.

See: docs/backups.md > Recovery key setup

## What is the recovery key, and why does it matter so much?

Every backup is encrypted. It can be opened by the site itself, for routine restores, or by your recovery key. The site keeps only the public half of the recovery key; the secret half is shown to you once and never touches the server.

Keep the secret half in a password manager. If the server is lost entirely, your bucket credentials plus that secret half are all you need to recover the site. Without it, a backup from a lost server cannot be opened.

See: docs/backups.md > Key model: one envelope per backup

## Where can I store my backups?

In an S3-compatible bucket. Supported providers include Backblaze B2, Amazon S3 and Linode.

The bucket must:

- be its own bucket, not the one your site's files are stored in;
- not be publicly readable;
- let the key list, write and delete objects (retention needs delete).

The target is tested against each of these when you save it, and refused with the reason if a check fails.

See: docs/backups.md > Saving a target

## How often do backups run, and how long are they kept?

The **Backup** scheduled task runs them; set its frequency and time on the Scheduled Tasks page. By default:

- Backups are incremental: one full backup, then runs that carry only what changed. A fresh full is taken every 7 days (`backup_full_interval_days`).
- Restore points from the last 28 days are kept in the bucket (`backup_retention_days`). The newest backup, and the newest one that passed verification, are always kept regardless of age.
- What a run leaves on the server's own disk is small and kept 7 days (`backup_local_retention_days`).

See: docs/backups.md > Retention

## How do I know my backups actually work?

The **Backup verification** task opens and reads your newest backup every 7 days (`backup_verify_every_days`), using the key on the server. The Backups page shows the result as **Last verified restorable**.

On the Backups page you can also:

- **Verify the newest backup** on demand.
- **Rehearse a restore**, which restores into a scratch area and a throwaway database, counts what came back, and deletes it. Your live site is not touched.

To confirm the recovery key you saved still works, use the Recovery Readiness page.

See: docs/backups.md > Verifying backups

## How do I restore a backup?

On the same server, with a backup on its disk:

```bash
bash maintenance_scripts/sysadmin_tools/restore_project.sh {project} /backups/{archive}
```

From the bucket, with your recovery key: download the backup, unlock it with `backup_envelope.php open`, then restore with `restore_project.sh` (or `restore_chain.sh` for an incremental chain).

A restore drops the database and loads the backup in its place. Nothing is saved first, so anything written since the backup was taken is lost.

See: docs/backups.md > Restoring

## How do I move my site to a new server using a backup?

Install a fresh site on the new server, then restore onto it:

```bash
sudo ./install.sh -y server
sudo ./install.sh -y site --bare-metal <site> --password-file=/root/.joinery_postgres_password <domain>
bash maintenance_scripts/sysadmin_tools/restore_chain.sh <site> \
     --artifacts <chain dir> --key-file /tmp/k --domain <domain> --adopt-secret-key --force
```

The restore adjusts the domain, paths and web server configuration to the new machine. Use `--adopt-secret-key` so stored credentials (the backup target, OAuth client secrets, IMAP passwords, cloud accounts) keep working; without it they must be entered again. `restore_project.sh` has no such option. The new server's PostgreSQL must be at least as new as the old one's.

See: docs/deploy_and_upgrade.md > Rebuilding a site on new hardware

## How do I change the backup recovery key?

On the Backups page, in the Recovery key section, choose **Actions → Rotate key**. It runs the same ceremony as the first setup: a new key made in your browser, saved by you, and pasted back to prove it.

- Keep the old key until every backup made with it has aged out; old backups still need it.
- The next backup starts a fresh full backup sealed to the new key.
- Backups refuse to run until the new key is proven.

See: docs/backups.md > Rotating the key

## A backup failed. Where do I find out why?

From the first failure, every admin page shows superadmins a notice: when the run failed, which target, and the last line of the backup's output. The next successful backup clears it.

A backup that cannot read part of the site fails and names the file, rather than storing an incomplete backup. The Backups page also warns when no backup has reached the bucket for two nights.

See: docs/backups.md > Scheduling

## Can I stop backups from being deleted, even by someone who breaks into the server?

Yes, with object lock. Set lock days on a backup target whose bucket has object lock turned on (Backblaze B2, Amazon S3 or Linode). Nothing can delete or replace a locked backup before its date, not the site and not the key's owner.

- Object lock can only be turned on when the bucket is created.
- The lock days are fixed once anything is stored in the target.
- Storage for locked backups is paid until their date, even if retention would have deleted them sooner.

See: docs/backups.md > Object lock
