#!/usr/bin/env python3
"""Build only privacy-thresholded NTA aggregate cells from approved rows.

Usage (in a private processing environment, NEVER in the public GitHub CI):
    python3 tools/aggregate-nta.py < private-validated-reports.json > reviewed-public-aggregates.json

The input is a JSON list of validated exports or {"reports": [...]}.
No private rows, raw identifiers or metadata are emitted. No networking.
This pure utility does NOT publish data to WordPress automatically.
"""
import json
import statistics
import sys
from collections import defaultdict
from datetime import date

FIELDS = (
    "Project", "Vendor", "Country (ISO code)", "Project language / locale",
    "Week beginning Monday", "Days attempted to work", "Days with NTA",
    "Completed paid-equivalent hours (0.5-hour steps)",
    "How are hours measured?", "Source of weekly figures", "Account access status",
)
MIN_REPORTS = 20


def _parse(row):
    if not isinstance(row, dict) or not set(FIELDS).issubset(row):
        return None
    if row["Project"] not in ("search_quality", "ads_quality"):
        return None
    if row["Vendor"] not in ("telus", "welodata", "rws", "other"):
        return None
    country = row["Country (ISO code)"]
    if not isinstance(country, str) or len(country) != 2 or not country.isalpha() or not country.isupper():
        return None
    if row["Account access status"] != "active":
        # Quality holds are different from normal task shortages.
        return None
    try:
        wk = row["Week beginning Monday"]
        dt = date.fromisoformat(wk)
        if dt.weekday() != 0 or wk < "2026-12-07":
            return None
        attempted = int(row["Days attempted to work"])
        nta = int(row["Days with NTA"])
        hours = float(row["Completed paid-equivalent hours (0.5-hour steps)"])
        if str(row["Days attempted to work"]) not in {str(i) for i in range(8)}:
            return None
        if str(row["Days with NTA"]) not in {str(i) for i in range(8)}:
            return None
        if nta > attempted or not 0 <= hours <= 100 or hours * 2 != int(hours * 2):
            return None
        if attempted == 0 and hours > 0:
            return None
        if row["How are hours measured?"] not in (
            "payroll", "vendor_report", "task_aet_estimate", "self_estimate"
        ):
            return None
        if row["Source of weekly figures"] not in (
            "platform_record", "time_log", "memory_estimate"
        ):
            return None
    except (TypeError, ValueError, OverflowError):
        return None
    return (row["Project"], country, row["Vendor"], wk), attempted, nta, hours


def aggregate(rows, min_reports=MIN_REPORTS):
    if not isinstance(rows, list) or type(min_reports) is not int or min_reports < MIN_REPORTS:
        raise ValueError("Expected records list and public minimum >= 20")
    grouped = defaultdict(list)
    for row in rows:
        parsed = _parse(row)
        if parsed:
            key, attempted, nta, hours = parsed
            grouped[key].append((attempted, nta, hours))
    cells = []
    for key, values in sorted(grouped.items()):
        if len(values) < min_reports:
            continue
        project, country, vendor, wk = key
        cells.append({
            "project": project,
            "country_iso2": country,
            "vendor": vendor,
            "week_start": wk,
            "reports": len(values),
            "attempted_days_total": sum(x[0] for x in values),
            "nta_days_total": sum(x[1] for x in values),
            "median_completed_hours": statistics.median(x[2] for x in values),
        })
    return {
        "schema_version": "0.2-weekly",
        "cells": cells,
        "note": "Aggregated approved submissions; not unique workers or representative task availability. Suppressed cells are insufficient evidence, not zero NTA.",
    }


def main():
    data = json.load(sys.stdin)
    rows = data.get("reports") if isinstance(data, dict) else data
    json.dump(aggregate(rows), sys.stdout, separators=(",", ":"), allow_nan=False)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
