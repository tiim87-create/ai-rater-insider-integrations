# AI Rater Insider — WordPress Integrations

Source code for **airaterinsider.com** WordPress Business integrations.

## First module: Weekly NTA Bridge (pre-release)

`ari-nta-bridge/` contains a minimal WordPress plugin for a **private**, authenticated transfer of approved weekly NTA fields to an analytics worker.

- Captures **nothing by default**: `ARI_NTA_CAPTURE_ENABLED` must be explicitly set to `true` in server configuration **after** live Jetpack payload mapping and provenance have been verified.
- Requires an exact 11-field schema; discards any other fields and never exports IP, email, browser, files or visitor identity.
- Ignores spam, prelaunch submissions and responses not attributable to the approved site form.
- Uses a dedicated, read-only `ari_nta_export` capability. No unauthenticated report endpoint.
- Offers an admin-only diagnostic that exposes only **metadata key names** (no values) for mapping; never use production private answers as test fixtures.
- Site-specific form: reusable Jetpack form **1204**, draft intake page **1200**.

**Status:** experimental source, **not installed or deployed to WordPress**. The hook's exact `$entry_values` shape and form-parent metadata must be verified in a staged test before capture is enabled. This repo is currently PUBLIC: **never commit secrets, raw submissions or attachments**.

Run local tests:

```bash
php tests/test-schema.php
php -l ari-nta-bridge/ari-nta-bridge.php
```

Do not set up automatic WordPress deployment on push. Follow the release checklist in `docs/DEPLOYMENT.md`.
