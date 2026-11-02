#!/usr/bin/env python3
"""A throwaway SMTP sink for the phone gates (tests/functional/android/*_gate.sh, ios/*_gate.sh).

The gates send replies, forwards and drafts from the site under test. A
workspace box never delivers mail off the machine, so the gate points the
site's SMTP settings here for the run: every message is accepted and dropped,
and one line per message (envelope only) goes to stdout. Nothing is relayed.

A gate that needs mail to arrive (a seeded message, a reply to a local
mailbox, a password-reset email) names the site's own mail domains with
--deliver: a recipient at one of them is handed to the site's inbound handler
(plugins/mailbox/utils/inbound_email_handler.php, as the mail server's pipe
would), so it is stored in the site's mailbox; everything else is dropped.

Usage: phone_smtp_sink.py PORT [--deliver DOMAIN[,DOMAIN...] --handler PATH]
       (listens on 127.0.0.1 only)
"""
import asyncio
import sys

DELIVER = set()
HANDLER = ""


async def deliver(rcpt, data):
    """Hand one message to the site's inbound handler; return its exit status."""
    proc = await asyncio.create_subprocess_exec(
        "php", HANDLER, rcpt, "gate-sink",
        stdin=asyncio.subprocess.PIPE, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.STDOUT)
    out, _ = await proc.communicate(data)
    if proc.returncode != 0:
        print(f"sink: handler for {rcpt} exited {proc.returncode}: {out.decode(errors='replace')[-300:]}", flush=True)
    return proc.returncode


async def session(reader, writer):
    async def say(line):
        writer.write((line + "\r\n").encode())
        await writer.drain()

    await say("220 gate-sink ESMTP")
    sender, rcpts = "", []
    try:
        while True:
            raw = await reader.readline()
            if not raw:
                break
            cmd = raw.decode(errors="replace").strip()
            verb = cmd[:4].upper()
            if verb in ("EHLO", "HELO"):
                await say("250 gate-sink")
            elif verb == "MAIL":
                sender, rcpts = cmd[10:], []
                await say("250 OK")
            elif verb == "RCPT":
                rcpts.append(cmd[8:])
                await say("250 OK")
            elif verb == "DATA":
                await say("354 End data with <CR><LF>.<CR><LF>")
                data = bytearray()
                while True:
                    line = await reader.readline()
                    if not line or line in (b".\r\n", b".\n"):
                        break
                    data += line[1:] if line.startswith(b"..") else line
                local = [r.strip("<> ") for r in rcpts
                         if r.strip("<> ").rsplit("@", 1)[-1].lower() in DELIVER]
                failed = [r for r in local if await deliver(r, bytes(data)) != 0]
                stored = [r for r in local if r not in failed]
                print(f"sink: {sender} -> {', '.join(rcpts)} ({len(data)} bytes)"
                      + (f", delivered to {', '.join(stored)}" if stored else ""), flush=True)
                if failed:
                    await say("451 Delivery to " + ", ".join(failed) + " failed")
                else:
                    await say("250 OK: " + ("delivered" if local else "dropped"))
            elif verb == "QUIT":
                await say("221 Bye")
                break
            elif verb in ("RSET", "NOOP"):
                await say("250 OK")
            else:
                await say("502 Not implemented")
    finally:
        writer.close()


async def main(port):
    server = await asyncio.start_server(session, "127.0.0.1", port)
    print(f"sink: listening on 127.0.0.1:{port}", flush=True)
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    args = sys.argv[1:]
    if "--deliver" in args:
        DELIVER = {d.strip().lower() for d in args[args.index("--deliver") + 1].split(",") if d.strip()}
    if "--handler" in args:
        HANDLER = args[args.index("--handler") + 1]
    if DELIVER and not HANDLER:
        sys.exit("--deliver needs --handler")
    asyncio.run(main(int(args[0])))
