# Deployment and privacy gate

## Non-production checks
1. Verify exact `grunion_after_feedback_post_inserted` payload shape on a staging site. The documented hook's fourth argument is `$entry_values`; the mapping to field labels is NOT yet verified against form 1204.
2. With capture disabled, install only after reviewing source and version, then inspect admin-only `/wp-json/ari-nta/v1/diagnostic?feedback_id=<known_test_id>`. It exposes metadata **key names**, not values.
3. Verify `_feedback_form_id` or `_feedback_post_id` truly identifies form 1204/page 1200; otherwise amend attribution after controlled testing.
4. Create a **dedicated export-only WordPress user**, assigned the `ARI NTA Export Reader` role, and an application password. Keep it in GitHub Actions secrets, never source code.
5. Test unauthenticated GET: must be 401/403; authenticated scoped user can read only the intended aggregate input, not arbitrary WP admin content.
6. Confirm private retention policy and deletion process; validate every returned report against the already-tested Python normalizer and aggregation logic.
7. Only after all gates pass, set `ARI_NTA_CAPTURE_ENABLED` to `true` in protected server config. Use a **manual deployment** first; never auto-deploy every push to production.

## WordPress.com GitHub Deployments
- Connect this repository to `airaterinsider.com` using **Settings → Repositories** only after granting the WordPress.com GitHub app access to this specific repo.
- Branch `main`, target `/wp-content/plugins/ari-nta-bridge`, **manual** deployment. However, this repository's plugin lives in the subfolder `ari-nta-bridge/`. A deployment pointing the repository root at that target would nest the plugin and is **not suitable as-is**. Either create a deployment-only branch whose root contains the plugin files, or package/install a reviewed plugin ZIP.
- Never connect repository root to site root, themes directory, or broad plugins directory.
- Do not activate until source layout and deployment path have been verified. No deploy has occurred.

## Scope
The plugin is experimental and currently captures no data. Tests validate the *pure schema* only, not live Jetpack hooks, authenticated WordPress responses, REST behavior, or storage privacy.

## Deployment branch prepared — 2026-11-24
- Created branch `deploy/ari-nta-bridge` at commit `a33c70e24e2960d0e01e8d575e03ebbc90dccdde` with **only** plugin root files (`ari-nta-bridge.php`, `includes/class-ari-nta-schema.php`, and a non-executable note). This avoids deploying the entire research repository or nesting the plugin incorrectly.
- Attempted to register manual WordPress.com GitHub Deployment targeting `/wp-content/plugins/ari-nta-bridge` (auto deploy off). **WordPress refused without changing site state:** its WordPress.com account lacks a connected GitHub account / installed WordPress.com GitHub app for this repo.
- Owner must open https://my.wordpress.com/sites/airaterinsider.com/settings/repositories, connect GitHub, and grant the WordPress.com GitHub app access to the `tiim87-create/ai-rater-insider-integrations` repository. Do not broadly grant all repositories if specific selection is available.
- After user connects, retry deployment registration with the deployment-only branch and manual mode; **do not trigger deployment or activate plugin** until Jetpack hook/provenance and access control have been tested.


## Verified first WordPress.com deployment — 2026-11-24
- WordPress deployment connection ID **9802** points to `tiim87-create/ai-rater-insider-integrations`, branch `deploy/ari-nta-bridge`, destination `/wp-content/plugins/ai-rater-insider-integrations`, auto deploy **off**.
- Manual run **293576** succeeded at 2026-11-24 14:13:51 UTC; deployed commit `a33c70e24e2960d0e01e8d575e03ebbc90dccdde`.
- Verified through WordPress installed-plugin inventory: **AI Rater Insider — NTA Bridge** version **0.1.0**, plugin file `ai-rater-insider-integrations/ari-nta-bridge`, status **inactive**, `can_activate=true`.
- Actual deployment destination name is different from the originally suggested `ari-nta-bridge`, and this **works** because WordPress discovers the plugin header in the root PHP file. Do not change destination to match file basename.
- No activation, REST endpoint exposure, data capture or secret configuration has been performed. The next gate is a staged diagnostic and correct Jetpack hook/meta provenance validation before enabling capture.


## Plugin activation checkpoint — 2026-11-24
- Called WordPress.com plugin activation for `ai-rater-insider-integrations/ari-nta-bridge` on `airaterinsider.com`.
- WordPress confirmed **status=activated**, plugin name **AI Rater Insider — NTA Bridge**, version **0.1.0**.
- Independently re-listed installed plugins and confirmed status **active**, `can_deactivate=true`.
- Code review confirms capture is **disabled by default**, unless a server-side `ARI_NTA_CAPTURE_ENABLED === true` constant is explicitly configured (we have not configured it).
- Attempted read-only unauthenticated URL checks for `/wp-json/ari-nta/v1/reports` and `/diagnostic`, but external web/network tools did **not** return an HTTP response (web inaccessible; local runtime DNS blocked). **Do not claim the unauthenticated endpoints were successfully tested.**
- Next gate: verify both endpoints return **401/403** anonymously, verify privileged diagnostic works, verify Jetpack hook provenance and key mapping, then complete a safe staging test. Do not enable capture before these checks.

## Anonymous endpoint test confirmed by site owner — 2026-11-24
- Owner opened `https://airaterinsider.com/wp-json/ari-nta/v1/reports` in an unauthenticated/incognito session.
- Observed exact response: `{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}`.
- **Pass: unauthenticated access to private reports is denied.** This alone does NOT verify protection against other logged-in roles or verify the privileged diagnostic endpoint. Capture remains disabled.
- Next: admin-only read-only diagnostic `https://airaterinsider.com/wp-json/ari-nta/v1/diagnostic?feedback_id=1208` to inspect provenance boolean and metadata **key names only**, followed by staged Jetpack hook mapping. If browser login is via WordPress.com SSO, REST authentication may require a nonce and opening the endpoint directly can return 401 even for an administrator.

## Admin diagnostic deployed — 2026-11-24
- Previous direct REST diagnostic URL showed `rest_forbidden` status 401 to logged-in visitor (cookie-authenticated REST nonce is not necessarily available from direct browser navigation).
- Introduced `Tools → NTA Bridge Diagnostics` with `manage_options` protection, read-only checks of feedback IDs 1208 and 1201. It displays form/page attribution booleans and metadata key names only, **not** values, response content, IPs, browser details, or files.
- Main branch commit `1fa545fc1817e489316fb34695b3d28e43d669f6`; deployment branch `fc8c3aca8bb18063b59cf9cc65bf9a108f4cb423`.
- WordPress deployment ID 9802 run **293584** completed **success** at 2026-11-24 14:22 UTC.
- WordPress installed plugin inventory independently confirmed **0.1.1 active**.
- **Capture remains disabled.** No responses published or exported.
- Admin should visit `https://airaterinsider.com/wp-admin/tools.php?page=ari-nta-diagnostics` and report provenance booleans. Do not enable capture until Jetpack hook arguments and exact source metadata are validated.

## Actual Jetpack source metadata discovered — 2026-11-24
Site owner's admin diagnostics for historical feedback #1208 and #1201 show neither `_feedback_form_id` nor `_feedback_post_id` is present. Actual available key names: `_feedback_source_post_id`, `_feedback_extra_fields`, `_feedback_akismet_values`, and `_feedback_email`. Current `source_verified` therefore returns false for both. The new `_feedback_source_post_id` value is **not yet known** and must not be assumed equal to either page 1200 or reusable form 1204.
- Read-only admin diagnostics **v0.1.2** added source-key presence and booleans comparing its value to page **1200** and reusable form **1204**, without printing the raw value.
- GitHub main change commit `130fcda086c7e99311a5262f2757bbc7e7f7e9e3`, deployment branch commit `b002ab3de6a2ed6052b0a7cbad26a39a01544912`.
- WordPress deployment run **293590** reported **success** at 2026-11-24 14:26:59 UTC; plugin inventory showed **0.1.2 active**.
- Next: admin refresh `/wp-admin/tools.php?page=ari-nta-diagnostics` and report whether `_feedback_source_post_id` matches expected page or form. Do not enable capture or change production attribution until verified.

## Verified source attribution fix — 2026-11-24
- Admin-only diagnostic **0.1.2** for saved feedback #1208 and #1201 confirmed:
  - `_feedback_source_post_id` **present** in both;
  - source value matches **page 1200** in both; does **not** equal reusable form **1204**;
  - prior `_feedback_form_id` and `_feedback_post_id` checks were wrong for this site.
- Replaced `source_verified()` in **0.1.3** to require strict equality `get_post_meta($feedback_id, '_feedback_source_post_id', true) == 1200`. Did not broaden to legacy IDs or any other form.
- Main commit `860fde0441c282e8847960cea0bcae0fc6278823`; deployment commit `7b5a468a5433cf9ab01a1cedabc23a14a46d2d0d`. Deployment run **293597** reported **success** at 2026-11-24 14:29:42 UTC. Plugin inventory confirmed **v0.1.3 active**.
- Capture continues **disabled** by default. The saved responses can be used to verify the updated attribution booleans via admin diagnostics, but they are not and must not be imported into the statistical feed.
- Still unverified: `grunion_after_feedback_post_inserted` fourth argument mapping to the 11 expected labels; any missing matching field must fail closed. Need staged proof before capture activation.
