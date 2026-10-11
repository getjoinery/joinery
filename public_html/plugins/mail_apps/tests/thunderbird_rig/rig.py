#!/usr/bin/env python3
"""Thunderbird measurement rig — command line.

  rig.py start   [--fresh] [--pref key=value ...]   launch headless Thunderbird
  rig.py account [--imap-host H --imap-port P --imap-security none|starttls|ssl
                  --smtp-host H --smtp-port P --smtp-security none|starttls|ssl
                  --user ADDRESS --passfile FILE]  create the IMAP account + SMTP server
  rig.py js 'CODE'        run chrome-context JS (may await; `return` a value)
  rig.py jsfile FILE      same, from a file
  rig.py shot NAME        screenshot of the main window -> $RIG_HOME/shots/NAME.png
  rig.py mark TEXT        marker line in the rig server's logs (if the control URL answers)
  rig.py scenario NAME    run one scenario from scenarios.py (see `rig.py scenario list`)
  rig.py alert [NAME]     print any open alert/dialog windows, screenshot them
                          (shots/NAME-<n>.png), then close the alert
  rig.py stop             quit Thunderbird

Paths: $RIG_HOME (default ~/mail-apps-rig) holds the Thunderbird build, the
profile (profiles/<RIG_PROFILE>, default "main"), and logs (logs/<RIG_RUN>).
"""
import argparse
import base64
import json
import os
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import tb  # noqa: E402

CONTROL = os.environ.get("RIG_CONTROL", "http://127.0.0.1:1880")
PROFILE = os.path.join(tb.RIG_HOME, "profiles", os.environ.get("RIG_PROFILE", "main"))
LOGDIR = os.path.join(tb.RIG_HOME, "logs", os.environ.get("RIG_RUN", "run1"))



def control(path, **params):
    q = "&".join("%s=%s" % (k, urllib.parse.quote(str(v))) for k, v in params.items())
    req = urllib.request.Request(CONTROL + path + ("?" + q if q else ""), method="POST" if path not in ("/state", "/submissions") else "GET")
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            return json.loads(r.read() or b"null")
    except Exception as e:  # the rig server is optional (e.g. against the real gateway)
        return {"control_error": str(e)}


def mark(text):
    with open(os.path.join(LOGDIR, "marks.log"), "a") as f:
        import datetime
        f.write("%s MARK %s\n" % (datetime.datetime.now(datetime.timezone.utc).strftime("%H:%M:%S.%fZ"), text))
    control("/mark", text=text)


ACCOUNT_JS = r"""
const [cfg] = arguments;
const { AccountConfig } = ChromeUtils.importESModule("resource:///modules/accountcreation/AccountConfig.sys.mjs");
const { CreateInBackend } = ChromeUtils.importESModule("resource:///modules/accountcreation/CreateInBackend.sys.mjs");
const c = new AccountConfig();
c.incoming.type = "imap";
c.incoming.hostname = cfg.imapHost; c.incoming.port = cfg.imapPort;
c.incoming.username = cfg.user; c.incoming.password = cfg.password;
c.incoming.socketType = cfg.imapSocket; c.incoming.auth = Ci.nsMsgAuthMethod.passwordCleartext;
c.outgoing.type = "smtp";
c.outgoing.hostname = cfg.smtpHost; c.outgoing.port = cfg.smtpPort;
c.outgoing.username = cfg.user; c.outgoing.password = cfg.password;
c.outgoing.socketType = cfg.smtpSocket; c.outgoing.auth = Ci.nsMsgAuthMethod.passwordCleartext;
c.identity.realname = "Rig User"; c.identity.emailAddress = cfg.user;
c.rememberPassword = true;
const account = await CreateInBackend.createAccountInBackend(c);
const s = account.incomingServer;
// What the account wizard does next (accountcreation/views/email.mjs).
s.getNewMessages(s.rootFolder, rig.win().msgWindow, null);
// Show what the account wizard leaves as defaults.
const vals = {};
for (const k of ["use_idle", "use_condstore", "use_compress_deflate", "autosync_offline_stores", "offline_download",
                 "check_time", "login_at_startup", "delete_model", "fetch_by_chunks", "send_client_info",
                 "allow_utf8_accept", "check_all_folders_for_new", "max_cached_connections", "useServerFilter", "moveOnSpam"]) {
  try { vals[k] = s.getBoolValue(k); } catch (e) {}
  try { const i = s.getIntValue(k); if (typeof vals[k] != "boolean" || i) vals[k + "(int)"] = i; } catch (e) {}
}
return {key: account.key, server: s.key, serverURI: s.serverURI, offlineSupportLevel: s.offlineSupportLevel,
        maxConnections: s.QueryInterface(Ci.nsIImapIncomingServer).maximumConnectionsNumber,
        biffMinutes: s.biffMinutes, doBiff: s.doBiff, prefs: vals,
        identity: {fcc: account.defaultIdentity.fccFolderURI, drafts: account.defaultIdentity.draftsFolderURI,
                   archive: account.defaultIdentity.archiveFolderURI, archiveEnabled: account.defaultIdentity.archiveEnabled,
                   smtpKey: account.defaultIdentity.smtpServerKey}};
"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("start")
    p.add_argument("--fresh", action="store_true", help="delete the profile first")
    p.add_argument("--pref", action="append", default=[], help="key=value (JSON value, or plain string)")
    p = sub.add_parser("account")
    p.add_argument("--imap-host", default="127.0.0.1")
    p.add_argument("--imap-port", type=int, default=1143)
    p.add_argument("--imap-security", default="none", choices=["none", "starttls", "ssl"])
    p.add_argument("--smtp-host", default="127.0.0.1")
    p.add_argument("--smtp-port", type=int, default=1587)
    p.add_argument("--smtp-security", default="none", choices=["none", "starttls", "ssl"])
    p.add_argument("--user", default="rig@example.test")
    p.add_argument("--passfile", default=os.path.join(tb.RIG_HOME, "pass.txt"))
    p = sub.add_parser("js")
    p.add_argument("code")
    p = sub.add_parser("jsfile")
    p.add_argument("file")
    p = sub.add_parser("shot")
    p.add_argument("name")
    p = sub.add_parser("mark")
    p.add_argument("text")
    p = sub.add_parser("scenario")
    p.add_argument("name")
    p = sub.add_parser("alert")
    p.add_argument("name", nargs="?", default="")
    sub.add_parser("stop")
    a = ap.parse_args()

    if a.cmd == "start":
        if a.fresh and os.path.isdir(PROFILE):
            import shutil
            shutil.rmtree(PROFILE)
        prefs = {}
        for kv in a.pref:
            k, v = kv.split("=", 1)
            try:
                prefs[k] = json.loads(v)
            except ValueError:
                prefs[k] = v
        print("thunderbird pid", tb.launch(PROFILE, LOGDIR, prefs))
    elif a.cmd == "account":
        # nsMsgSocketType: plain=0, trySTARTTLS=1, alwaysSTARTTLS=2, SSL=3
        sock = {"none": 0, "starttls": 2, "ssl": 3}
        with open(a.passfile) as f:
            password = f.read().strip()
        cfg = dict(imapHost=a.imap_host, imapPort=a.imap_port, imapSocket=sock[a.imap_security],
                   smtpHost=a.smtp_host, smtpPort=a.smtp_port, smtpSocket=sock[a.smtp_security],
                   user=a.user, password=password)
        with tb.session() as m:
            print(json.dumps(tb.js(m, ACCOUNT_JS, [cfg]), indent=1))
    elif a.cmd in ("js", "jsfile"):
        code = a.code if a.cmd == "js" else open(a.file).read()
        with tb.session() as m:
            print(json.dumps(tb.js(m, code), indent=1, default=str))
    elif a.cmd == "shot":
        os.makedirs(os.path.join(tb.RIG_HOME, "shots"), exist_ok=True)
        path = os.path.join(tb.RIG_HOME, "shots", a.name + ".png")
        with tb.session() as m:
            data = m.screenshot(format="base64", full=False)
        with open(path, "wb") as f:
            f.write(base64.b64decode(data))
        print(path)
    elif a.cmd == "mark":
        mark(a.text)
    elif a.cmd == "scenario":
        import scenarios
        scenarios.run(a.name, sys.modules[__name__])
    elif a.cmd == "alert":
        from marionette_driver.marionette import Alert
        with tb.session() as m:
            main_handle = m.current_chrome_window_handle
            for n, h in enumerate(m.chrome_window_handles):
                m.switch_to_window(h)
                try:
                    info = m.execute_script("return [location.href, document.title, (document.getElementById('infoBody')||document.body||{}).textContent]")
                except Exception:  # this window is blocked by the alert itself
                    print("window %d: blocked by an alert:" % n, repr(Alert(m).text))
                    continue
                info[2] = " ".join(info[2].split())[:300]
                print("window %d:" % n, json.dumps(info))
                if a.name and h != main_handle:
                    path = os.path.join(tb.RIG_HOME, "shots", "%s-%d.png" % (a.name, n))
                    with open(path, "wb") as f:
                        f.write(base64.b64decode(m.screenshot(format="base64")))
                    print("  shot", path)
            for h in m.chrome_window_handles:
                try:
                    m.switch_to_window(h)
                    al = Alert(m)
                    t = al.text
                    al.accept()
                    print("accepted alert:", repr(t))
                except Exception:
                    pass
    elif a.cmd == "stop":
        tb.stop()
        print("stopped")


if __name__ == "__main__":
    os.makedirs(LOGDIR, exist_ok=True)
    main()
