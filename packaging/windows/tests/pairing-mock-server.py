import argparse
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--port", type=int, required=True)
parser.add_argument("--events", required=True)
args = parser.parse_args()

events_path = Path(args.events)
base = f"http://127.0.0.1:{args.port}/"
expected_code = "ws1_0123456789abcdef01234567_0123456789abcdef0123456789abcdef0123456789abcdef"


def record(value: str) -> None:
    with events_path.open("a", encoding="utf-8") as handle:
        handle.write(value + "\n")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *values):
        return

    def send_json(self, status: int, payload: dict) -> None:
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_POST(self):
        if self.path != "/internal/windows-services/v1/pairing.php":
            self.send_json(404, {"success": False, "code": "not_found"})
            return
        if self.headers.get("X-Sokna-Windows-Services-Pairing") != "1":
            self.send_json(400, {"success": False, "code": "header_missing"})
            return
        length = int(self.headers.get("Content-Length", "0"))
        body = json.loads(self.rfile.read(length) or b"{}")
        action = body.get("action", "")
        if body.get("pairing_code") != expected_code:
            self.send_json(403, {"success": False, "code": "bad_code"})
            return
        record(action)
        if action == "exchange":
            self.send_json(200, {
                "success": True,
                "pairing_id": "ci-pairing",
                "bundle": {
                    "format": "sokna-windows-services-pairing-v1",
                    "schema_version": 1,
                    "local_base_url": base,
                    "local_bridge_allowed_origin": base.rstrip("/"),
                    "runtime_token": "a" * 64,
                    "local_token": "b" * 64,
                    "print_agent_token": "c" * 64,
                    "runtime_triggers": [
                        {"key": "maintenance.health", "intervalSeconds": 60}
                    ],
                },
            })
            return
        if action == "confirm":
            self.send_json(200, {"success": True, "pairing_id": "ci-pairing", "confirmed": True})
            return
        if action == "cancel":
            self.send_json(200, {"success": True, "pairing_id": "ci-pairing", "cancelled": True})
            return
        self.send_json(400, {"success": False, "code": "bad_action"})

    def do_GET(self):
        self.send_json(404, {"success": False, "code": "not_found"})


ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()
