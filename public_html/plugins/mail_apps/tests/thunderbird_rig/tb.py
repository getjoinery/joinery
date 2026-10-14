"""Drive a real headless Thunderbird over Marionette (chrome context).

Used by rig.py. Thunderbird keeps running between rig.py invocations; each
invocation opens a Marionette session, does its work, and closes the session.
"""
import json
import os
import signal
import subprocess
import time

from marionette_driver.marionette import Marionette

RIG_HOME = os.environ.get("RIG_HOME", os.path.expanduser("~/mail-apps-rig"))
TB_BIN = os.environ.get("TB_BIN", os.path.join(RIG_HOME, "tb157/thunderbird/thunderbird"))
MARIONETTE_PORT = int(os.environ.get("RIG_MARIONETTE_PORT", "28281"))

# Prefs that keep a fresh profile quiet and make Thunderbird's logs visible.
# Nothing here changes IMAP/SMTP behaviour; protocol defaults are left alone
# unless a scenario asks (--pref on the command line).
BASE_PREFS = {
    "marionette.port": MARIONETTE_PORT,
    "mail.shell.checkDefaultClient": False,
    "mail.provider.suppress_dialog_on_startup": True,
    "mailnews.start_page.enabled": False,
    "mail.rights.version": 1,
    "mail.spotlight.firstRunDone": True,
    "mail.winsearch.firstRunDone": True,
    "app.update.disabledForTesting": True,
    "app.update.auto": False,
    "datareporting.policy.dataSubmissionEnabled": False,
    "datareporting.healthreport.uploadEnabled": False,
    "toolkit.telemetry.enabled": False,
    "browser.dom.window.dump.enabled": True,
    "devtools.console.stdout.chrome": True,
    "mailnews.smtp.loglevel": "All",
    "mailnews.send.loglevel": "All",
    "mailnews.compose.loglevel": "All",
    "mail.setup.loglevel": "All",
    "mail.accounthub.enabled": False,
    "extensions.update.enabled": False,
    "mail.biff.use_system_alert": False,
}


def pidfile():
    return os.path.join(RIG_HOME, "thunderbird.pid")


def write_userjs(profile, extra_prefs):
    os.makedirs(profile, exist_ok=True)
    prefs = dict(BASE_PREFS)
    prefs.update(extra_prefs or {})
    with open(os.path.join(profile, "user.js"), "w") as f:
        for k, v in prefs.items():
            f.write("user_pref(%s, %s);\n" % (json.dumps(k), json.dumps(v)))


def launch(profile, logdir, extra_prefs=None):
    """Start Thunderbird headless with Marionette and protocol logging."""
    write_userjs(profile, extra_prefs)
    os.makedirs(logdir, exist_ok=True)
    env = dict(os.environ)
    env["MOZ_HEADLESS"] = "1"
    env["MOZ_LOG"] = "IMAP:5,IMAP_CS:5,IMAPAutoSync:5,IMAP_KW:5,IMAPCache:4,MsgCompose:5,MsgSend:5,SMTP:5,timestamp,sync"
    env["MOZ_LOG_FILE"] = os.path.join(logdir, "tb-moz.log")
    env["MOZ_CRASHREPORTER_DISABLE"] = "1"
    out = open(os.path.join(logdir, "tb-stdout.log"), "ab")
    p = subprocess.Popen(
        [TB_BIN, "--headless", "--marionette", "-remote-allow-system-access", "-profile", profile, "-no-remote"],
        stdout=out, stderr=subprocess.STDOUT, env=env, start_new_session=True)
    with open(pidfile(), "w") as f:
        f.write(str(p.pid))
    # wait for Marionette
    for _ in range(120):
        try:
            m = Marionette(host="127.0.0.1", port=MARIONETTE_PORT)
            m.start_session()
            m.delete_session()
            return p.pid
        except Exception:
            time.sleep(1)
    raise RuntimeError("Thunderbird did not open Marionette")


def stop():
    """Quit Thunderbird (gracefully if possible) and kill its process group."""
    try:
        with open(pidfile()) as f:
            pid = int(f.read().strip())
    except (OSError, ValueError):
        return
    try:
        with session() as m:
            m.set_context(m.CONTEXT_CHROME)
            m.execute_script("Services.startup.quit(Ci.nsIAppStartup.eForceQuit);")
    except Exception:
        pass
    for _ in range(20):
        if not _alive(pid):
            break
        time.sleep(0.5)
    _kill_tree(pid)
    os.remove(pidfile())


def _alive(pid):
    """Whether the process still runs. On Windows os.kill(pid, 0) would end it
    (any signal but the console ones is TerminateProcess), so ask tasklist."""
    if os.name == "nt":
        out = subprocess.run(["tasklist", "/FI", "PID eq %d" % pid, "/NH"], capture_output=True, text=True).stdout
        return str(pid) in out
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def _kill_tree(pid):
    """Thunderbird and its child processes: its process group on POSIX
    (launched in a new session), taskkill /T on Windows (no process groups)."""
    if os.name == "nt":
        subprocess.run(["taskkill", "/PID", str(pid), "/T", "/F"], capture_output=True)
        return
    try:
        os.killpg(pid, signal.SIGKILL)
    except OSError:
        pass


class session:
    """Context manager: a Marionette session in chrome context."""

    def __enter__(self):
        self.m = Marionette(host="127.0.0.1", port=MARIONETTE_PORT)
        # Leave Thunderbird's own alerts open so a scenario can read them.
        self.m.start_session({"unhandledPromptBehavior": "ignore"})
        self.m.set_context(self.m.CONTEXT_CHROME)
        self.m.timeout.script = 300
        return self.m

    def __exit__(self, *a):
        try:
            self.m.delete_session()
        except Exception:
            pass


# JS prelude for every script: imports and small helpers.
PRELUDE = r"""
const { MailServices } = ChromeUtils.importESModule("resource:///modules/MailServices.sys.mjs");
const { MailUtils } = ChromeUtils.importESModule("resource:///modules/MailUtils.sys.mjs");
const rig = {
  win() { return Services.wm.getMostRecentWindow("mail:3pane"); },
  about3Pane() { return this.win().gTabmail.currentAbout3Pane; },
  account() { return MailServices.accounts.accounts.find(a => a.incomingServer.type == "imap"); },
  server() { return this.account().incomingServer; },
  folder(name) { return this.server().rootFolder.getChildNamed ? this._find(name) : null; },
  _find(name) {
    for (const f of this.server().rootFolder.descendants) { if (f.name == name || f.prettyName == name) return f; }
    if (name.toUpperCase() == "INBOX") return this.server().rootFolder.getFolderWithFlags(Ci.nsMsgFolderFlags.Inbox);
    return null;
  },
  hdrs(folder) {
    const out = [];
    for (const h of folder.messages) out.push(h);
    return out;
  },
  describe(folder) {
    return this.hdrs(folder).map(h => ({key: h.messageKey, subject: h.mime2DecodedSubject, flags: "0x" + h.flags.toString(16),
      read: h.isRead, flagged: h.isFlagged, offline: !!(h.flags & Ci.nsMsgMessageFlags.Offline), size: h.messageSize,
      keywords: h.getStringProperty("keywords"), msgid: h.messageId}));
  },
  // Every open dialog/alert: window-level and in-window (tab-modal / subdialog).
  prompts() {
    const out = [];
    for (const w of Services.wm.getEnumerator(null)) {
      const type = w.document.documentElement.getAttribute("windowtype");
      if (type != "mail:3pane") out.push({type, url: w.location.href, title: w.document.title,
        text: (w.document.getElementById("infoBody")?.textContent || w.document.body?.innerText || "").slice(0, 600)});
      for (const d of w.document.querySelectorAll("dialog, .dialogBox, browser.dialogFrame, .dialogFrame")) {
        const doc = d.contentDocument || d.ownerDocument;
        const t = (d.contentDocument?.getElementById("infoBody")?.textContent) || d.textContent;
        if (t && t.trim()) out.push({type: "in-window", tag: d.localName, text: t.trim().slice(0, 600)});
      }
    }
    return out;
  },
  closePrompts() {
    for (const w of Services.wm.getEnumerator(null)) {
      if (w.location.href.includes("commonDialog")) w.close();
    }
  },
  sleep(ms) { return new Promise(r => setTimeout(r, ms)); },
  urlListener() {
    let resolve; const p = new Promise(r => resolve = r);
    return { promise: p, OnStartRunningUrl() {}, OnStopRunningUrl(url, status) { resolve(status); },
             QueryInterface: ChromeUtils.generateQI(["nsIUrlListener"]) };
  },
};
"""


def js(m, body, args=None, sync=False):
    """Run JS in chrome context. body may `await`; its `return` value comes back."""
    if sync:
        return m.execute_script(PRELUDE + body, script_args=args or [])
    script = PRELUDE + "const [__done] = [arguments[arguments.length-1]];\n" + \
        "(async () => {\n" + body + "\n})().then(v => __done(v === undefined ? null : v), e => __done({error: String(e), stack: e && e.stack}));"
    return m.execute_async_script(script, script_args=args or [], sandbox=None)
