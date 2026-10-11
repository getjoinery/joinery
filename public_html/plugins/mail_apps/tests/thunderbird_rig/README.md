# Thunderbird measurement rig

Drives a real, current Thunderbird (headless, over Marionette) against an IMAP + SMTP-submission server and records exactly what Thunderbird sends and shows. Built for the mail_apps spec's Phase 0; re-run it against the real mail gateway once that exists.

Not a test-suite member: it needs a Thunderbird build and a running server, and it is driven by hand, one scenario at a time.

## Pieces

| File | What it is |
|---|---|
| `server/` | Throwaway Go server: go-imap v2 (beta.8) in-memory IMAP behind a logging shim, go-smtp submission, and a localhost control endpoint (inject mail, lock a UID, rotate the lock notice, change flags, make SMTP answer 454/550). Every protocol line goes to `imap.log` / `smtp.log`. |
| `tb.py` | Launches Thunderbird headless with Marionette and protocol logging; runs chrome-context JS. |
| `rig.py` | Command line: start/stop Thunderbird, create the account, run JS, take screenshots, read and close alerts, run scenarios, write log markers. |
| `scenarios.py` | Scripted user actions: send, reply, forward, 454/550 sends, Send Later, Bcc, drafts, archive, move, delete, junk, tag. |
| `analyze.py` | Summarises an `imap.log`: connections, folders selected per connection, every command shape Thunderbird sent. |
| `autoconfig_probe.py` | Logging HTTP proxy that shows every autoconfig URL Thunderbird tries (https ones are refused at CONNECT, nothing leaves the machine). |

## One-time setup (all under `~/mail-apps-rig`, override with `RIG_HOME`)

```bash
mkdir -p ~/mail-apps-rig/{dl,tb157,logs,profiles,shots}
cd ~/mail-apps-rig/dl
# newest release: https://product-details.mozilla.org/1.0/thunderbird_versions.json (LATEST_THUNDERBIRD_VERSION)
curl -O https://archive.mozilla.org/pub/thunderbird/releases/157.0.1/linux-x86_64/en-US/thunderbird-157.0.1.tar.xz
tar -C ../tb157 -xJf thunderbird-157.0.1.tar.xz          # or set TB_BIN to another build

cd ~/mail-apps-rig
python3 -m venv --without-pip venv && curl -sSO https://bootstrap.pypa.io/get-pip.py && venv/bin/python get-pip.py
venv/bin/pip install marionette_driver
head -c 12 /dev/urandom | base64 | tr -d '/+=' > pass.txt   # throwaway password

# build the server (module cache kept inside the rig directory)
cd /var/www/html/joinerytest/public_html/plugins/mail_apps/tests/thunderbird_rig/server
GOMODCACHE=~/mail-apps-rig/gomod GOCACHE=~/mail-apps-rig/gocache GOFLAGS=-modcacherw go build -o ~/mail-apps-rig/rigserver .
```

No X server is needed: Thunderbird runs with `--headless --marionette -remote-allow-system-access`, and Marionette screenshots of the chrome window work headless.

## A run

```bash
cd ~/mail-apps-rig
R=/var/www/html/joinerytest/public_html/plugins/mail_apps/tests/thunderbird_rig
export RIG_RUN=run1                      # logs land in logs/$RIG_RUN

./rigserver -passfile pass.txt -logdir logs/$RIG_RUN &   # see ./rigserver -h for options
venv/bin/python $R/rig.py start --fresh  # fresh profile; --pref key=value to change a Thunderbird pref
venv/bin/python $R/rig.py account        # creates the account exactly as the account wizard does
venv/bin/python $R/rig.py shot 01-inbox  # -> shots/01-inbox.png
venv/bin/python $R/rig.py scenario list
venv/bin/python $R/rig.py scenario send454
venv/bin/python $R/rig.py alert 02-send-error   # read + screenshot + close the error dialog
venv/bin/python $R/analyze.py logs/$RIG_RUN/imap.log --from "Q7a"
venv/bin/python $R/rig.py stop
kill %1
```

Server options worth knowing (`./rigserver -h` for all): `-noinferiors Archive` (Thunderbird then archives flat instead of creating `Archive/<year>`), `-permflags`, `-selectflags '... $Junk $NotJunk'` (Thunderbird then STOREs `$Junk`/`$NotJunk`), `-compress`, `-condstore=false`, `-tlscert/-tlskey` (implicit TLS on both ports, as 993/465).

The Phase 0 runs used: run1 defaults; run2 `-noinferiors Archive`; run3 adds `-compress -selectflags '\Seen \Flagged \Answered \Deleted \Draft $Forwarded $Junk $NotJunk'` with `rig.py start --pref mail.server.default.use_condstore=true`; run4 `--pref mail.server.default.use_idle=false`.

Server-side events go through the control endpoint (`server/control.go` lists them):

```bash
curl -XPOST 'localhost:1880/lock?folder=INBOX&uid=6'      # body FETCH of UID 6 -> NO [UNAVAILABLE]
curl -XPOST 'localhost:1880/inject?folder=INBOX&subject=Hi&kb=300'
curl -XPOST 'localhost:1880/notice?action=rotate'         # expunge the lock notice, number a fresh one
curl -XPOST 'localhost:1880/flags?folder=INBOX&uid=1&add=%5CFlagged&remove=%5CSeen'
curl -XPOST 'localhost:1880/smtp?mode=454&stage=data'     # ok | 454 | 550, at mail | rcpt | data
curl localhost:1880/state
```

Logs per run: `imap.log` (every IMAP line, both ways, credentials masked), `smtp.log`, `marks.log`, `tb-moz.log.moz_log` (Thunderbird's own IMAP/IMAP_CS/IMAPAutoSync/IMAP_KW log), `tb-stdout.log` (SMTP client, compose and account-setup console logs).

## Against a different server (the real gateway)

The rig server is optional; only `rig.py` and Thunderbird are needed.

```bash
venv/bin/python $R/rig.py start --fresh
venv/bin/python $R/rig.py account --imap-host mail.example.com --imap-port 993 --imap-security ssl \
    --smtp-host mail.example.com --smtp-port 465 --smtp-security ssl \
    --user person@example.com --passfile /path/to/app-password-file
```

The certificate must be one Thunderbird trusts. Without the rig server there is no `imap.log`; use Thunderbird's `tb-moz.log.moz_log` (`grep 'SendData\|CreateNewLineFromSocket'`) for the protocol lines, and drive server-side events (lock, unlock, new mail) through the site itself. `rig.py mark` still writes `marks.log` for timing.

## Notes

- Kill only by PID (`rig.py stop`, `kill <rigserver pid>`); `pkill -f` matches your own shell.
- `rig.py` scenarios that end in a modal error dialog leave it open; Marionette refuses commands until `rig.py alert` closes it.
- Headless Thunderbird reports the user as idle, so auto-sync of non-Inbox folders starts about 10 s after startup; on a desktop it starts when the person goes idle. To trigger it on demand: `rig.py js 'const a = Cc["@mozilla.org/imap/autosyncmgr;1"].getService(Ci.nsIObserver); a.observe(null, "back", ""); a.observe(null, "idle", "10");'`.
- The server's MOVE replaces go-imap's in-memory MOVE, which sends each EXPUNGE twice (see `server/session.go`).
