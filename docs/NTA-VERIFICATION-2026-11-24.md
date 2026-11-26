# NTA Bridge verification — 2026-11-24

## Trigger and scope
The technical WordPress user `nta-exporter` was connected to WordPress.com through direct `wp-login.php` login after the invitation handoff failed. This note records a non-deploying GitHub Actions validation trigger.

## Current automated checks
- PHP syntax for files under `ari-nta-bridge/` and `tests/`.
- `php tests/test-schema.php`.

## Not covered by these checks
- Production authentication with the `nta-exporter` Application Password.
- REST API read access under `ARI NTA Export Reader`.
- Live Jetpack form provenance (page 1200 / form 1204).
- Privacy-safe extraction of response values.

## Safe next validation
Use a secret-backed, read-only API smoke test only after reviewing the endpoint and confirming that the exporter has its restricted role restored. Never put an Application Password or private submissions into the public repository, workflow logs, or artifacts. Keep capture disabled until provenance is verified.

This file documents verification scope; it does not deploy or enable capture.

## Continuation checkpoint — 2026-11-26

Verified live via WordPress connector:
- NTA Bridge **v0.2.2 active**, manual deployment connection **9802** (automated deploy disabled).
- `nta-exporter` has role `ari_nta_export_reader` and only capabilities `read` and `ari_nta_export`.
- Last authenticated smoke run #45464665947: header HTTP 200, anonymous reports HTTP 401, Application Password HTTP 200, scoped export permission HTTP 200, capture disabled, authenticated reports HTTP 409. No responses exported.

Added negative permission tests to `tests/nta-rest-smoke.py`:
- anonymous `GET /diagnostic` must be forbidden;
- authenticated exporter `GET /diagnostic` must also be forbidden (requires `manage_options`).
Public mode runs on test-file push; authenticated mode must be dispatched manually with existing Actions secrets.

**Release gate: do NOT enable production capture yet.** The plugin enforces `LAUNCH_DATE=2026-12-01`; the strict schema accepts weekly Monday dates from **2026-12-07** onward. Validate first live submission, saved Jetpack field shape 11/11, source-page provenance, cross-field rules and private retention/export destination before enabling collection. Treat prelaunch records as tests, never as production statistics.

No plugin deployment, secrets exposure, live form submission, or capture-setting modification performed on 2026-11-26.
