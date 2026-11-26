# NTA Bridge — controlled live capture verification

**Status:** Pre-launch; live capture remains disabled. These instructions are a release gate, not authorization to enable it automatically.

## Verified as of 2026-11-26
- WordPress NTA Bridge `0.2.2` active. Deployment connection `9802` is **manual**, not auto-on-push.
- Dedicated WordPress user `nta-exporter` has `ari_nta_export_reader`, `read` and `ari_nta_export`; no `manage_options`.
- Authenticated REST smoke run [#45798068293](https://github.com/tiim87-create/ai-rater-insider-integrations/actions/runs/45798068293) passed all eight checks, including admin-only diagnostics blocked with HTTP 403.
- Offline processing tests verify fail-closed behavior and persistence path using synthetic entries, **not** actual WordPress database writes.
- `ARI_NTA_CAPTURE_ENABLED` remains off. The plugin refuses report GET with HTTP 409 while off.
- Code enforces source `_feedback_source_post_id=1200`, launch submission date `>=2026-12-01`, and a Monday week date `>=2026-12-07`. Historical test submissions must not enter production statistics.

## Preconditions before any live capture
1. Agree on retention, access controls and final export destination. Do not copy raw responses into this **public** GitHub repository, issues, Actions logs or artifacts.
2. Verify full backup and restoration path for plugin settings and database before any server configuration change. Confirm WordPress table creation from activation (not solely unit-test doubles).
3. Confirm staging or controlled maintenance window and **exact mechanism for setting and removing** server-side `ARI_NTA_CAPTURE_ENABLED`. An Action passing does not toggle this setting.
4. Choose a fresh synthetic form submission *after* the enable step with valid 11-field values and week `2026-12-07` or later. The actual submitted timestamp must be `>=2026-12-01` for capture to pass.
5. Review `grunion_after_feedback_post_inserted` behavior and saved Jetpack `_feedback_extra_fields` timing. Keep logs restricted to counts/booleans, never values or identifiers.

## Live test acceptance (requires deliberate activation)
1. Before change: run `NTA REST API smoke test` in `authenticated` mode. Expect `capture_enabled=false` and report HTTP 409.
2. Enable server constant using an approved WordPress hosting configuration mechanism. **Do not deploy code or write any credentials in GitHub to accomplish this.**
3. After enable, use a revised smoke test that expects `capture_enabled=true` and validates *only* HTTP statuses, schema shape and count without logging payload values. The present smoke workflow intentionally expects OFF and will fail after enabling.
4. Submit one synthetic, valid response to the original NTA form; inspect admin-only diagnostics: approved source, 11/11 labels, no duplicate labels, schema valid, no cross-field failures.
5. Read `/reports?page=1&per_page=1` with the dedicated account **only** in memory. Assert the expected field whitelist, no PII/unrecognized fields, date and one record; do not print/store the body, IDs or values.
6. Check unauthorized user is denied; exporter must not access `/diagnostic` (HTTP 403). Ensure duplicate handling and unexpected records have not escaped the allowlist.
7. Disable capture again immediately after controlled test until production rollout is approved. With OFF, API must again return HTTP 409.
8. Confirm any synthetic rows are excluded from downstream statistics with a reproducible documented filtering rule. Do not delete records blindly or mistake a test row for weekly production data.

## Stop/rollback conditions
Stop capture if authentication fails, anonymous access succeeds, source provenance is uncertain, schema/field mapping differs, PII appears, duplicate rows persist, or retention/deletion is unverified. Restore the previous server setting and re-run the authenticated OFF smoke workflow. Diagnose without exposing response contents.

## Operational next step
First prepare a **mode-aware, privacy-safe, live capture-ON smoke workflow** for step 3/5. Do not deploy or enable collection solely because the offline checks pass.

## Capture-ON smoke workflow prepared — 2026-11-26

The `NTA REST API smoke test` workflow now has three modes:
- `public`: safe automatic checks without authentication.
- `authenticated`: verifies capture OFF, valid Application Password, scoped permission, admin denial and HTTP 409 for reports.
- `capture-on`: **manual only**; requires input `confirm_live_read=yes`, a live `capture_enabled=true` response, and reads at most one report in GitHub runner process memory. Verifies HTTP 200, response envelope, exact whitelist (`Date` plus the 11 approved fields), eligible dates and no unexpected keys. It never prints response bodies, values, dates, email, IP, account identities or secrets.

**Important:** successful `capture-on` with zero rows validates endpoint availability but **does not** validate actual storage. At least one approved synthetic post-launch response must exist before declaring end-to-end capture proven. Manual live mode does **not** change WordPress settings or insert a record, and must not be invoked ahead of a controlled live test. Existing authenticated OFF-mode remains the default operational safety check.

Do not enable capture or use a public GitHub Action as a long-term store for NTA submissions.
