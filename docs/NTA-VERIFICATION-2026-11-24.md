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
