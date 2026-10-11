#!/usr/bin/env python3
"""Observe Thunderbird's autoconfig lookups.

A logging HTTP proxy on 127.0.0.1:PORT. Thunderbird is pointed at it with
network.proxy.* prefs, so every autoconfig URL it tries arrives here:
plain-http requests as full URLs, https ones as CONNECT host:443 (refused,
so nothing ever leaves the machine). Optionally answers one http URL with a
config file, to see which source wins.

  autoconfig_probe.py LOGFILE [--port 18082] [--serve-url URL --serve-file FILE]
"""
import argparse
import datetime
import http.server
import socketserver


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("logfile")
    ap.add_argument("--port", type=int, default=18082)
    ap.add_argument("--serve-url", default="")
    ap.add_argument("--serve-file", default="")
    a = ap.parse_args()
    log = open(a.logfile, "a", buffering=1)

    class H(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def note(self, extra=""):
            log.write("%s %s %s %s\n" % (datetime.datetime.now(datetime.timezone.utc).strftime("%H:%M:%S.%f"),
                                         self.command, self.path, extra))

        def do_CONNECT(self):
            self.note("(https; refused by probe)")
            self.send_response(502)
            self.end_headers()

        def _answer(self):
            body = b""
            if self.command == "POST":
                n = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(n)
            self.note(("body=%d bytes auth=%s" % (len(body), "yes" if self.headers.get("Authorization") else "no")) if self.command == "POST" else "")
            if a.serve_url and self.path.split("?")[0] == a.serve_url:
                data = open(a.serve_file, "rb").read()
                self.send_response(200)
                self.send_header("Content-Type", "text/xml")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
                return
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()

        do_GET = do_POST = do_HEAD = _answer

    class S(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

    S(("127.0.0.1", a.port), H).serve_forever()


if __name__ == "__main__":
    main()
