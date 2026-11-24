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
