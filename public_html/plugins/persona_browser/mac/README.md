# Persona Browser — Mac service

This folder is the piece that runs **on a Mac**, not on the Joinery server. It
holds a real, hand-logged-in browser session and lets your Joinery site read
that feed over your tailnet. Joinery does all the parsing; this service only
holds the session, scrolls the feed, and hands back the raw page markup plus the
images it can pull through the logged-in browser.

## Requirements

- A Mac (Apple Silicon or Intel) that stays on.
- **Tailscale** running and logged in — the service binds to your tailnet
  address, so nothing off your tailnet can reach it.
- Your Joinery server on the **same tailnet**.

## Install

Copy this whole `mac/` folder to the Mac (any location), then:

```bash
cd mac
./install.sh
```

The script installs a self-contained Node, the service, and Playwright's
Firefox, writes a config with a generated token, and starts a launchd agent that
keeps the service running (and restarts it on reboot). It finishes by printing
the **Service Endpoint** and **Service Token** to paste into the plugin's
settings in Joinery (`/profile/persona_browser/feed` → the plugin's settings).

Re-running `./install.sh` is safe — it keeps the existing token.

## Log in (one time, and whenever the session expires)

Logging in needs a real window and a human, so it is a separate step. On the
Mac's own screen (not plain SSH):

```bash
cd ~/persona-browser && ./login.sh
```

A Firefox window opens. Log in, clear any 2FA / "was this you" prompt, wait until
your feed is showing, then close the window. The session is saved into the
profile the service reuses.

Confirm it stuck:

```bash
cd ~/persona-browser && node read_feed.js      # prints LOGGED_IN: true/false
```

## Files

| File            | What it is                                                        |
|-----------------|-------------------------------------------------------------------|
| `install.sh`    | One-shot setup: Node, deps, config, launchd agent.                |
| `server.js`     | The service Joinery calls (`/health`, `/content`, `/media/<f>`).  |
| `login.js`/`login.sh` | Headed login you run by hand.                               |
| `read_feed.js`  | Manual "is my login still good?" check.                           |
| `package.json`  | Playwright dependency.                                             |

## Managing the service

```bash
tail -f ~/persona-browser/server.log                         # logs
launchctl kickstart -k gui/$(id -u)/com.joinery.personabrowser   # restart
launchctl bootout gui/$(id -u)/com.joinery.personabrowser        # stop/remove
```
