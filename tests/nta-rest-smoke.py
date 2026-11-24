#!/usr/bin/env python3
"""Read-only NTA Bridge smoke test. Never prints response bodies or credentials."""
import base64
import json
import os
import sys
import urllib.error
import urllib.request

HOST = "https://airaterinsider.com"
PREFIX = "/wp-json/ari-nta/v1"
MODE = os.getenv("NTA_SMOKE_MODE", "public")
TIMEOUT = 15


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


opener = urllib.request.build_opener(NoRedirect)


def request(route, authorization=None):
    headers = {"Accept": "application/json", "User-Agent": "ARI-NTA-smoke/1"}
    if authorization is not None:
        headers["Authorization"] = authorization
    req = urllib.request.Request(HOST + PREFIX + route, headers=headers, method="GET")
    try:
        with opener.open(req, timeout=TIMEOUT) as response:
            status = response.status
            raw = response.read(16384)
    except urllib.error.HTTPError as exc:
        status = exc.code
        raw = exc.read(16384)
    try:
        body = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        body = None
    return status, body


def check(name, condition, status):
    print(f"{'PASS' if condition else 'FAIL'} {name} (HTTP {status})")
    if not condition:
        sys.exit(1)


status, body = request("/transport-check", "ARIProbe v1")
check("Harmless Authorization header reaches plugin",
      status == 200 and isinstance(body, dict) and body.get("probe_header_arrived") is True,
      status)

status, _ = request("/reports")
check("Anonymous report access denied", status in (401, 403), status)

if MODE == "public":
    print("SKIP authenticated checks (public mode)")
    sys.exit(0)

if MODE != "authenticated":
    print("FAIL invalid mode")
    sys.exit(1)

username = os.getenv("NTA_WP_USERNAME", "").strip()
password = os.getenv("NTA_WP_APP_PASSWORD", "").strip()
if not username or not password:
    print("FAIL required Actions secrets missing (NTA_WP_USERNAME / NTA_WP_APP_PASSWORD)")
    sys.exit(1)

# Never print, log, cache, or persist this header or the returned response body.
token = base64.b64encode((username + ":" + password).encode()).decode("ascii")
auth = "Basic " + token
status, body = request("/auth-check", auth)
check("Application Password authenticated", status == 200 and isinstance(body, dict)
      and body.get("authenticated") is True, status)
check("Exporter has scoped permission", body.get("can_export_nta") is True, status)
check("Capture remains disabled", body.get("capture_enabled") is False, status)

status, _ = request("/reports?page=1&per_page=1", auth)
check("Reports safely disabled until launch", status == 409, status)
print("Authenticated smoke test completed; no records exported.")
