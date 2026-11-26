#!/usr/bin/env python3
"""Privacy and grouping tests with synthetic records only."""
import importlib.util
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "tools" / "aggregate-nta.py"
spec = importlib.util.spec_from_file_location("ari_nta_aggregate", path)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def record(**overrides):
    data = {
        "Project": "search_quality",
        "Vendor": "telus",
        "Country (ISO code)": "IT",
        "Project language / locale": "it-IT",
        "Week beginning Monday": "2026-12-07",
        "Days attempted to work": "3",
        "Days with NTA": "2",
        "Completed paid-equivalent hours (0.5-hour steps)": "6.0",
        "How are hours measured?": "vendor_report",
        "Source of weekly figures": "time_log",
        "Account access status": "active",
    }
    data.update(overrides)
    return data


def check(ok, message):
    if not ok:
        raise AssertionError(message)
    print("PASS:", message)


check(mod.aggregate([record()] * 19)["cells"] == [],
      "below publication threshold suppressed")
result = mod.aggregate([record()] * 20)
check(len(result["cells"]) == 1 and result["cells"][0]["reports"] == 20,
      "20 validated synthetic submissions form one cell")
cell = result["cells"][0]
check(cell["attempted_days_total"] == 60 and cell["nta_days_total"] == 40,
      "counts aggregated without exposing individual responses")
check(cell["median_completed_hours"] == 6.0, "hours median derived from rows")
check(set(cell) == {"project", "country_iso2", "vendor", "week_start", "reports",
                   "attempted_days_total", "nta_days_total", "median_completed_hours"},
      "public fields exactly match dashboard allowlist")
private = record(**{"Email": "private@example.invalid", "IP Address": "198.51.100.4"})
serialized = str(mod.aggregate([private] * 20))
check("private@example.invalid" not in serialized and "198.51.100.4" not in serialized,
      "private fields never emitted")
check(mod.aggregate([record(**{"Account access status": "quality_hold"})] * 20)["cells"] == [],
      "quality holds excluded from ordinary NTA aggregates")
check(mod.aggregate([record(**{"Days with NTA": "4"})] * 20)["cells"] == [],
      "inconsistent attempt counts excluded")
check(mod.aggregate([record(**{"Week beginning Monday": "2026-12-08"})] * 20)["cells"] == [],
      "invalid Monday start excluded")
check(len(mod.aggregate([record()] * 20 + [record(**{"Vendor": "rws"})] * 19)["cells"]) == 1,
      "suppressed small vendor cell not emitted")
try:
    mod.aggregate([record()] * 2, min_reports=2)
except ValueError:
    check(True, "minimum cell size cannot be reduced below 20")
else:
    raise AssertionError("privacy threshold can be bypassed")
