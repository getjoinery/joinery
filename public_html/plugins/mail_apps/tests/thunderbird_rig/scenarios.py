"""Scenarios for the Thunderbird rig. Each one marks the logs, drives
Thunderbird the way a person would (compose window, thread pane, toolbar
commands), and prints what Thunderbird shows afterwards.

Run one with:  rig.py scenario NAME      (rig.py scenario list)

Scenarios that end in an error dialog leave it open on purpose; read and close
it with `rig.py alert NAME`.
"""
import json
import time

import tb

# Open a compose window the way the toolbar does, wait until it is ready.
COMPOSE_JS = r"""
const [kind, to, subject, body, folderName, msgKey] = arguments;
const identity = rig.account().defaultIdentity;
const opened = new Promise(resolve => {
  const obs = { observe(win, topic) {
    if (topic != "domwindowopened") return;
    win.addEventListener("load", () => { if (win.document.documentElement.getAttribute("windowtype") == "msgcompose") { Services.ww.unregisterNotification(obs); resolve(win); } }, {once: true});
  }};
  Services.ww.registerNotification(obs);
});
if (kind == "new") {
  const params = Cc["@mozilla.org/messengercompose/composeparams;1"].createInstance(Ci.nsIMsgComposeParams);
  const fields = Cc["@mozilla.org/messengercompose/composefields;1"].createInstance(Ci.nsIMsgCompFields);
  fields.to = to; fields.subject = subject; fields.body = body;
  params.composeFields = fields; params.identity = identity;
  params.type = Ci.nsIMsgCompType.New; params.format = Ci.nsIMsgCompFormat.Default;
  MailServices.compose.OpenComposeWindowWithParams(null, params);
} else {
  const folder = rig._find(folderName);
  const hdr = rig.hdrs(folder).find(h => h.messageKey == msgKey);
  const type = {reply: Ci.nsIMsgCompType.Reply, forward: Ci.nsIMsgCompType.ForwardInline}[kind];
  MailServices.compose.OpenComposeWindow(null, hdr, folder.getUriForMsg(hdr), type, Ci.nsIMsgCompFormat.Default, identity, null, rig.win().msgWindow);
}
const win = await opened;
for (let i = 0; i < 100 && !(win.gLoadingComplete && win.GetCurrentEditor && win.GetCurrentEditor()); i++) await rig.sleep(100);
await rig.sleep(1500);
if (kind == "forward") win.SetComposeDetails({to: to, identityKey: null});
if (kind != "new") { win.document.getElementById("msgSubject").value = win.document.getElementById("msgSubject").value; }
return {ready: !!win.gLoadingComplete, title: win.document.title};
"""

COMPOSE_WIN = 'const win = Services.wm.getMostRecentWindow("msgcompose");'


def _js(code, args=None):
    with tb.session() as m:
        return tb.js(m, code, args)


def compose(kind, to="friend@example.org", subject="Rig test", body="Hello from the rig.", folder="INBOX", key=1):
    return _js(COMPOSE_JS, [kind, to, subject, body, folder, key])


def send_async():
    # Fire and return: a failure opens a modal error dialog Marionette cannot see past.
    return _js(COMPOSE_WIN + ' setTimeout(() => win.SendMessage(), 0); return "send started";')


def state_after(wait=6):
    time.sleep(wait)
    return _js(r"""
const out = {prompts: rig.prompts(), composeOpen: !!Services.wm.getMostRecentWindow("msgcompose")};
const outbox = MailServices.accounts.localFoldersServer.rootFolder.getFolderWithFlags(Ci.nsMsgFolderFlags.Queue);
out.outbox = outbox ? rig.describe(outbox).map(m => m.subject) : null;
for (const n of ["Sent", "Drafts"]) out[n] = rig.describe(rig._find(n)).map(m => [m.key, m.subject, m.flags]);
out.status = rig.win().document.getElementById("statusText")?.value;
return out;
""")


def s_send(r):
    r.mark("Q7a: send a new message (SMTP ok)")
    r.control("/smtp", mode="ok")
    print(compose("new", subject="Q7 plain send"))
    print(send_async())
    print(json.dumps(state_after(), indent=1))


def s_reply(r):
    r.mark("Q7b: reply to INBOX UID 2")
    print(compose("reply", folder="INBOX", key=2))
    print(send_async())
    print(json.dumps(state_after(), indent=1))


def s_forward(r):
    r.mark("Q7c: forward INBOX UID 3 inline")
    print(compose("forward", to="other@example.org", folder="INBOX", key=3))
    print(send_async())
    print(json.dumps(state_after(), indent=1))


def s_send454(r):
    r.mark("Q7d: send while SMTP answers 454 at end of DATA")
    r.control("/smtp", mode="454", stage="data")
    print(compose("new", subject="Q7 454 send"))
    print(send_async())
    print(json.dumps(state_after(8), indent=1))


def s_send454mail(r):
    r.mark("Q7e: send while SMTP answers 454 at MAIL FROM")
    r.control("/smtp", mode="454", stage="mail")
    print(compose("new", subject="Q7 454 at MAIL FROM"))
    print(send_async())
    print(json.dumps(state_after(8), indent=1))


def s_send550(r):
    r.mark("Q7f: send while SMTP answers 550 5.7.1 at end of DATA")
    r.control("/smtp", mode="550", stage="data")
    print(compose("new", subject="Q7 550 send"))
    print(send_async())
    print(json.dumps(state_after(8), indent=1))


def s_sendlater454(r):
    r.mark("Q7g: Send Later (Outbox), then Send Unsent Messages while SMTP answers 454")
    r.control("/smtp", mode="454", stage="data")
    print(compose("new", subject="Q7 send later 454"))
    print(_js(COMPOSE_WIN + ' setTimeout(() => win.SendMessageLater(), 0); await rig.sleep(4000); return "queued";'))
    print(json.dumps(state_after(1)["outbox"]))
    r.mark("Q7g: Send Unsent Messages")
    print(_js('const sl = Cc["@mozilla.org/messengercompose/sendlater;1"].getService(Ci.nsIMsgSendLater);'
              ' setTimeout(() => sl.sendUnsentMessages(rig.account().defaultIdentity), 0); return "sending";'))
    print(json.dumps(state_after(8), indent=1))


def s_sendlater_ok(r):
    r.mark("Q7h: Send Unsent Messages again with SMTP ok")
    r.control("/smtp", mode="ok")
    print(_js('const sl = Cc["@mozilla.org/messengercompose/sendlater;1"].getService(Ci.nsIMsgSendLater);'
              ' setTimeout(() => sl.sendUnsentMessages(rig.account().defaultIdentity), 0); return "sending";'))
    print(json.dumps(state_after(8), indent=1))


def s_drafts(r):
    r.mark("Q8: save a draft twice, then close the compose window")
    r.control("/smtp", mode="ok")
    print(compose("new", subject="Q8 draft", body="First version."))
    print(_js(COMPOSE_WIN + ' await win.SaveAsDraft(); await rig.sleep(4000); return "saved once";'))
    r.mark("Q8: second save")
    print(_js(COMPOSE_WIN + ' win.SetComposeDetails({plainTextBody: "Second version, longer.", identityKey: null}); await win.SaveAsDraft(); await rig.sleep(4000); return "saved twice";'))
    r.mark("Q8: close compose window")
    print(_js(COMPOSE_WIN + ' win.gContentChanged = false; win.gMsgCompose.bodyModified = false; win.close(); await rig.sleep(2000); return "closed";'))
    print(json.dumps(state_after(2), indent=1))


# Select a message in the thread pane and run a toolbar/menu command on it.
SELECT_AND_DO_JS = r"""
const [folderName, msgKey, command] = arguments;
const a = rig.about3Pane();
const folder = rig._find(folderName);
a.displayFolder(folder.URI);
await rig.sleep(1500);
const hdr = rig.hdrs(folder).find(h => h.messageKey == msgKey);
if (!hdr) return {error: "no such message", have: rig.describe(folder).map(m => m.key)};
a.threadTree.selectedIndex = a.gDBView.findIndexOfMsgHdr(hdr, true);
await rig.sleep(1500);
const subject = hdr.mime2DecodedSubject;
a.goDoCommand(command);
await rig.sleep(3000);
return {did: command, on: subject};
"""

MOVE_JS = r"""
const [fromName, msgKey, toName] = arguments;
const src = rig._find(fromName), dest = rig._find(toName);
const hdr = rig.hdrs(src).find(h => h.messageKey == msgKey);
MailServices.copy.copyMessages(src, [hdr], dest, true, null, rig.win().msgWindow, true);
await rig.sleep(3000);
return {moved: hdr.mime2DecodedSubject, from: fromName, to: toName};
"""


def folders_now():
    return _js('const o = {}; for (const f of rig.server().rootFolder.descendants) o[f.URI.split("127.0.0.1/")[1] || f.URI]'
               ' = rig.describe(f).map(m => m.key + ":" + m.subject + (m.keywords ? " [" + m.keywords + "]" : "")); return o;')


def s_archive(r):
    r.mark("Q9a: Archive button on INBOX UID 5")
    print(_js(SELECT_AND_DO_JS, ["INBOX", 5, "cmd_archive"]))
    print(json.dumps(folders_now(), indent=1))


def s_move(r):
    r.mark("Q9b: move INBOX UID 4 to Projects (Move To / drag)")
    print(_js(MOVE_JS, ["INBOX", 4, "Projects"]))


def s_delete(r):
    r.mark("Q9c: Delete button on INBOX UID 3")
    print(_js(SELECT_AND_DO_JS, ["INBOX", 3, "cmd_delete"]))
    print(json.dumps(folders_now(), indent=1))


def s_delete_in_trash(r):
    r.mark("Q9d: Delete in Trash (first message there)")
    key = _js('const f = rig._find("Trash"); rig.about3Pane().displayFolder(f.URI); await rig.sleep(2000);'
              ' return rig.describe(f).map(m => m.key)[0];')
    # Fire and return: Thunderbird asks "permanently delete?" (mail.warn_on_delete_from_trash);
    # answer it with `rig.py alert`.
    print(_js(SELECT_AND_DO_JS.replace("a.goDoCommand(command);", "setTimeout(() => a.goDoCommand(command), 0);"),
              ["Trash", key, "cmd_delete"]))
    # mail.warn_on_delete_from_trash shows a confirm; the alert command answers it


def s_junk(r):
    r.mark("Q9e: Junk button on INBOX UID 1")
    print(_js(SELECT_AND_DO_JS, ["INBOX", 1, "cmd_markAsJunk"]))
    print(json.dumps(folders_now(), indent=1))


def s_notjunk(r):
    r.mark("Q9f: Not Junk on INBOX UID 1")
    print(_js(SELECT_AND_DO_JS, ["INBOX", 1, "cmd_markAsNotJunk"]))


def s_tag(r):
    r.mark("Q12: tag INBOX UID 2 as Important ($label1) - not in PERMANENTFLAGS")
    print(_js('const f = rig._find("INBOX"); const h = rig.hdrs(f).find(h => h.messageKey == 2);'
              ' f.addKeywordsToMessages([h], "$label1"); await rig.sleep(3000);'
              ' return rig.describe(f).find(m => m.key == 2);'))


def s_bcc(r):
    r.mark("Q7i: send with a Bcc recipient (does the Sent APPEND carry Bcc?)")
    r.control("/smtp", mode="ok")
    print(compose("new", subject="Q7 bcc send"))
    print(_js(COMPOSE_WIN + ' win.SetComposeDetails({bcc: "hidden@example.org", identityKey: null}); await rig.sleep(500); setTimeout(() => win.SendMessage(), 0); return "send started";'))
    print(json.dumps(state_after()["Sent"]))


def s_draft_then_send(r):
    r.mark("Q8b: save a draft, then send it from the same compose window")
    r.control("/smtp", mode="ok")
    print(compose("new", subject="Q8 draft then send", body="Draft body."))
    print(_js(COMPOSE_WIN + ' await win.SaveAsDraft(); await rig.sleep(4000); return "saved";'))
    r.mark("Q8b: send it")
    print(send_async())
    print(json.dumps(state_after(), indent=1))


SCENARIOS = {name[2:]: fn for name, fn in globals().items() if name.startswith("s_")}


def run(name, r):
    if name == "list":
        for n, fn in SCENARIOS.items():
            print(n)
        return
    SCENARIOS[name](r)
