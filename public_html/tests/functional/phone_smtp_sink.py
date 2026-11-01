#!/usr/bin/env python3
"""A throwaway SMTP sink for the phone gates (tests/functional/android/fortress_gate.sh, ios/fortress_gate.sh).

The gates send replies, forwards and drafts from the site under test. A
workspace box never delivers mail off the machine, so the gate points the
site's SMTP settings here for the run: every message is accepted and dropped,
and one line per message (envelope only) goes to stdout. Nothing is relayed.

Usage: smtp_sink.py PORT   (listens on 127.0.0.1 only)
"""
import asyncio
import sys


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
                size = 0
                while True:
                    line = await reader.readline()
                    if not line or line in (b".\r\n", b".\n"):
                        break
                    size += len(line)
                print(f"sink: {sender} -> {', '.join(rcpts)} ({size} bytes)", flush=True)
                await say("250 OK: dropped")
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
    asyncio.run(main(int(sys.argv[1])))
