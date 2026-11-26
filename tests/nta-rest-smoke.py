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
LIVE_READ_CONFIRMED = os.getenv("NTA_CONFIRM_LIVE_READ", "") == "yes"
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

status, _ = request("/diagnostic")
check("Anonymous admin diagnostics denied", status in (401, 403), status)

if MODE == "public":
    print("SKIP authenticated checks (public mode)")
    sys.exit(0)

if MODE not in ("authenticated", "capture-on"):
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
expected_capture = MODE == "capture-on"
check("Capture flag matches selected test mode",
      body.get("capture_enabled") is expected_capture, status)

status, _ = request("/diagnostic", auth)
check("Exporter cannot access admin diagnostics", status in (401, 403), status)

if MODE == "authenticated":
    status, _ = request("/reports?page=1&per_page=1", auth)
    check("Reports safely disabled until launch", status == 409, status)
    print("Authenticated OFF-mode smoke completed; no records exported.")
    sys.exit(0)

# Explicit opt-in to reading one private response in runner memory, only once
# capture was separately enabled by the site owner.
if not LIVE_READ_CONFIRMED:
    print("FAIL capture-on requires explicit live-read confirmation")
    sys.exit(1)

status, body = request("/reports?page=1&per_page=1", auth)
check("Authenticated report endpoint available with capture ON", status == 200, status)

# Values never enter logs, output files, or artifacts. Local process memory only.
allowed_fields = {
    "Date", "Project", "Vendor", "Country (ISO code)",
    "Project language / locale", "Week beginning Monday",
    "Days attempted to work", "Days with NTA",
    "Completed paid-equivalent hours (0.5-hour steps)",
    "How are hours measured?", "Source of weekly figures",
    "Account access status",
}
valid_envelope = (
    isinstance(body, dict) and body.get("page") == 1
    and type(body.get("count")) is int
    and isinstance(body.get("reports"), list)
    and body["count"] == len(body["reports"])
    and body["count"] <= 1
)
check("Report envelope shape and page limit", valid_envelope, status)

# Zero rows is valid before the first approved response.
if body["count"] == 0:
    print("PASS no captured rows yet; record-content validation not exercised")
else:
    item = body["reports"][0]
    valid_fields = (
        isinstance(item, dict) and set(item) == allowed_fields
        and all(isinstance(v, str) for v in item.values())
    )
    check("Exact 12-field allowlist (date plus 11 NTA fields)", valid_fields, status)
    check("Captured submission date not before launch",
          len(item["Date"]) >= 10 and item["Date"][:10] >= "2026-12-01", status)
    check("Weekly start not before first eligible week",
          item["Week beginning Monday"] >= "2026-12-07", status)
print("Capture-ON read-only smoke completed; no report contents logged or persisted.")
