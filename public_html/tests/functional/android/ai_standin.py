#!/usr/bin/env python3
"""A stand-in OpenAI-compatible model for the phone gates' AI leg
(specs/fortress_mobile_apps.md § R13), run on the Mac mini, where the
emulator reaches it at http://10.0.2.2:PORT.

POST /v1/chat/completions answers one scripted verdict that satisfies both
device-capable jobs (the triage reads `summary`; the security scan reads
score, verdict, red_flags and summary), in the shape of the judgements in
plugins/mailbox/tests/fixtures/device_ai_vectors.json. GET /v1/models lists
the model. Every request is counted in the log line it prints, never its body.

Usage: python3 ai_standin.py PORT SUMMARY
"""
import json
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(sys.argv[1])
SUMMARY = sys.argv[2]
MODEL = "walk-standin"


class Handler(BaseHTTPRequestHandler):
    def _send(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path.rstrip("/").endswith("/models"):
            self._send(200, {"object": "list", "data": [{"id": MODEL, "object": "model"}]})
        else:
            self._send(404, {"error": {"message": "not found"}})

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        try:
            req = json.loads(self.rfile.read(length) or b"{}")
        except ValueError:
            self._send(400, {"error": {"message": "bad json"}})
            return
        if not self.path.endswith("/chat/completions"):
            self._send(404, {"error": {"message": "not found"}})
            return
        verdict = {"summary": SUMMARY, "score": 2, "verdict": "safe", "red_flags": []}
        self._send(200, {
            "model": req.get("model") or MODEL,
            "choices": [{"message": {"role": "assistant", "content": json.dumps(verdict)}, "finish_reason": "stop"}],
            "usage": {"prompt_tokens": 100, "completion_tokens": 20},
        })

    def log_message(self, fmt, *args):
        sys.stderr.write("standin: %s %s\n" % (self.command, self.path))


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
