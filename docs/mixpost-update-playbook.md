# Mixpost customization-preserving upgrade playbook

This supersedes the former “pull latest and recreate” procedure. Production is a licensed Pro application, while this repository also contains the separate Lite package. Updating the Lite dependency file does not upgrade production.

Start with the [docs index](README.md), [current production release snapshot](current-production-release.md)
and [production runbook](production-runbook.md). The running release includes
the [TTB comment availability patch](../ops/comment-sync/README.md). Its applicator,
custom support classes and regression tests are versioned under `ops/comment-sync/`.
Reconcile their effective production bytes before a new candidate, preserving all
inbox history and Story capture. The [7.5.3 upgrade record](mixpost-v7-5-3-upgrade.md)
records the current target and separates preparation from production acceptance.

The [customization hub](customizations/README.md), [modification register](customizations/modifications.json), and [hardening checklist](customizations/update-hardening.md) are required update inputs. Run `python3 ops/scripts/check-customization-register.py`; additions and deployment delivery modes must match the source register and image recipe.

## Release contract

Preserve the application-level [Instagram Story capture](customizations/instagram-story-media.md)
files, migration/config and hourly command on every update. The exact-candidate
validator and mounted startup guard require its 28 behavior checks and application
fingerprints. Stage the new matching gate before installing a changed wrapper;
retain the old wrapper/gate together for rollback. Rehearse the additive table
on isolated restored MySQL and verify Annex's existing SELECT-only read access.

A release is the **vendor base digest + exact Pro version/source + composer.lock + customization Git commit + mount manifest + database checkpoint**. The vendor image downloads packages at startup: an image digest alone is not an application version or a reliable rollback. Build a frozen image with `ops/upgrades/build-frozen-image.sh`; its startup uses the installed code and never resolves packages or restores an application from shared storage.

The authoritative PHP application override map is `ops/production-overrides/deployment-manifest.json`. Startup (`peachy-start.sh`) and PHP upload limits (`uploads.ini`, both CLI and FPM) are also versioned; their mounts are infrastructure paths outside the legacy Infra manifest format. Preserve the storage volume, environment, proxy routing, networks and service credentials. Never mount a Vite manifest or individual hashed client asset. Since 7.0.2, the X analytics Vue overlays are built with the target Pro package and included in the frozen image; do not restore the former `/root/mixpost/x-analytics-dist` directory mount.

Google sign-in is a database-backed production setting rather than a file override. Preserve and recheck it using the [Google SSO runbook](google-sso.md), including the existing-user email match and the login-page button.

The mounted `peachy-start.sh` also enforces the [frozen upload release gate](frozen-release-gates.md) before migrations and services start. Each exact candidate must pass the frontend/queue/backend recovery tests and match freshly rebuilt assets. A release announcement or an older “superseded upstream” note cannot waive this contract.

Local-upload candidates additionally require the v2 six-suite gate, isolated real-video/ordinary-file/outbox/publication tests, persistent-volume container-replacement recovery, and controlled cloud byte readback. Preserve the dedicated media worker lease and background scanner. See [local uploads](customizations/local-uploads.md) for capacity, retries, and rollback constraints. Pending staged records must drain before rollback to an app lacking this route; never rewind the database over newly accepted media.

## 1. Inventory and checkpoint

1. Use an isolated checkout of current `origin/main`. Reconcile local-only commits and edits; never overwrite the user's checkout or assume remote Git contains every live fix.
2. Record running Pro version, Composer source reference and lock hash, Docker image ID, Compose files, entrypoint, all bind mounts and hashes, and MySQL/Redis container IDs. Inspect runtime app/bootstrap/package source against the pristine installed archive to catch unmounted hotfixes.
3. Save the current Compose, environment, every mounted file, release metadata, pristine app archive and a **consistent database dump** under a root-only timestamped backup directory. Do not put credentials, database dumps or licensed full-source archives in Git. Use the app container's database dump client with configured credentials, `--single-transaction --no-tablespaces --skip-lock-tables`; check exit status and dump size. MySQL 8.0.32's dump client can require unavailable FLUSH privileges; the app's MariaDB client works with the application user.
4. Retain a frozen copy of the old application for rollback, not merely the old vendor base image. Preserve local media/storage and the external object-store configuration. Record queued/delayed jobs and upcoming scheduled publications before cutover.

Preserve both effective Compose files, every mount, image-baked source, wrapper,
passing gates and exact current/previous rollback images. Before Docker cleanup,
preserve an exact image export and verify recovery. A top-level wildcard file
copy is not a complete recovery checkpoint. Active repairs, current/previous
bundles and referenced tools remain local under the [checkpoint storage policy](checkpoint-storage.md).

## 2. Obtain and compare the target

1. Read the [official release notes](https://mixpost.app/releases/pro) and [upgrade guidance](https://docs.mixpost.app/pro/upgrading/). Resolve the licensed package in a disposable container using a protected Composer auth file. Do not run the vendor startup against production to obtain source: it also migrates and starts workers.
2. Resolve an exact package version, keeping unrelated locked dependencies unchanged when compatible. For the 7.5.1 → 7.5.3 update the isolated command is `composer update inovector/mixpost-pro-team:7.5.3 --minimal-changes --prefer-dist --no-interaction --no-scripts`. Review the resulting lock diff; widen dependency resolution only for an actual requirement. Use an existing protected Composer credential source; never assume an old checkpoint's auth file is still local, and never bake it into the image.
3. Export pristine old and target sources. For every mount, compare **old upstream → custom** and **old upstream → new upstream**. `ops/upgrades/audit-overrides.py` produces the checksum inventory from exported sources and `docker inspect` mount JSON. Pass `--manifest ops/production-overrides/deployment-manifest.json --image-source CODE_ONLY_RUNNING_IMAGE_EXPORT` to include registered files baked into the image; missing image exports fail the audit. A mount-only inventory cannot preserve the local-upload helpers. Compare the complete effective Pro source too, including unregistered image patches: pristine old → effective live and pristine old → pristine target. Record every customized file as retained/rebased/upstream-equivalent/conflicted. Three-way merges may assist, but review their diffs and stop for unresolved conflicts. A checksum register alone does not prove preservation.
4. Reapply the required behavior to the new source. Review successful merges too: syntactically valid old state handling can break new queue/retry contracts. Use upstream implementations where equivalent; retain stronger custom safeguards where upstream only partially covers them. For the [Bluesky publishing fixes](bluesky-publishing-resilience.md), run `python3 ops/scripts/apply-bluesky-publishing-fixes.py --source "$staged_pro"` against the staged effective source on every update. Already-applied patches are a no-op; changed upstream context blocks for review/rebase. Preserve the registered image-baked `BlueskyManagesPost.php` and the existing Helpers/publishing-job bind overrides.
5. Record each customization as retained unchanged, rebased, replaced by equivalent upstream behavior, or inactive/historical. Add newly required classes to the manifest. Include server-only files in Git. Retire a patch only after its acceptance/failure cases pass against the exact unpatched upstream candidate and compiled assets. October 10 inspection found unsafe upload response handling again in 7.0.4: preserve/rebase the current `ops/production-patches/video-upload-*.patch` behavior and pass its recovery contract. Never revive the archived 6.2.0 image defaults.
6. For the X analytics client overlay, compare all three Vue components against the target package and build a complete bundle with `ops/scripts/build-pro-x-analytics-assets.sh INSTALLED_PRO_TEAM_PACKAGE_DIR NEW_OUTPUT_DIR`. Keep the licensed compiled bundle outside Git. Verify its manifest references files in the same output directory and retain the Peachy landing-page image inside it.
7. Record the exact target package version/source and lock hash. Package resolution can advance between the release announcement and the audit.
8. Since 7.0.3, preserve the vendor X API usage controls when rebasing custom import jobs. Disabled analytics must stop all X analytics requests, limited ranges must clamp every page and skip disjoint historical cadence windows, and full analytics must retain our explicit age-tiered history. Run `TwitterUsageControlsTest.php` in the isolated Pro rehearsal alongside the existing X resource regression. Do not re-enable the retired X mentions import while incorporating upstream job arrays.
9. Preserve the [X media read-cost controls](customizations/x-media-read-costs.md). Run `python3 ops/scripts/apply-twitter-media-cost-fixes.py --source "$staged_pro"` after rebasing the existing overrides. Timeline refreshes must have no expansions; only new/missing media metadata may use a bounded lookup. Preserve cached thumbnails/types, full text, metrics, pagination and range limits. Changed patch context blocks for rebase. Require all 17 `twitter-media-reads` checks in the frozen validator and `TwitterMediaReadIntegrationTest.php` in the restored-database rehearsal.

## 3. Rehearse in isolation

1. Restore the database dump to **separate MySQL and Redis containers** on a Docker `--internal` network. The candidate must not join production networks or mount writable production storage.
2. Copy the application key only through protected host files so the copied database remains readable. Override database/Redis hosts, mailer to `log`, broadcasts to `log`, sessions/cache to isolated stores. Run no cron or Horizon. Block outbound networking even when HTTP is faked in tests.
3. Apply the complete candidate manifest. Run package discovery, publish the packaged assets, normal migrations, and `mixpost:upgrade-database --force`. Review every migration, including column drops. Check existing users/workspaces/accounts/posts/media and provider IDs survive, and validate new per-account statuses and schedules.
4. Lint all PHP inside the target PHP runtime. Run the override regression suite under `ops/production-overrides/tests/`; plain script tests run directly, while PHPUnit tests require the appropriate runner. A Lite test suite is not proof of Pro compatibility. Check each script's explicit completion message as well as its exit status: Laravel can render an uncaught exception while the PHP process returns zero. New standalone Pro tests must install an exception handler that exits nonzero.
5. Exercise success, partial failure, exhausted jobs, staggered schedules, concurrent publishing runs, retries with existing remote IDs, blank additional content, social-video preparation/timeouts, Instagram terminal-only retry, X historical pagination, thumbnail repeat imports, Threads uploads, YouTube reports and failure-mail rendering. Fake external HTTP and notifications; never run test publishes against customer accounts.
6. Verify routes, cached routes/views, scheduler registration, provider class loading, health/login/public page and all packaged assets. Rehearse boot from the frozen image, with the same mount structure planned for production.

## 4. Freeze and deploy

From the Docker host, with a tested source container and an exact committed customization revision:

```sh
ops/upgrades/build-frozen-image.sh \
  mixpost-v7-build \
  inovector/mixpost-pro-team@sha256:REVIEWED_BASE_DIGEST \
  peachy/mixpost-pro:VERSION-COMMIT \
  FULL_CUSTOMIZATION_COMMIT
```

The builder excludes environment files, runtime caches, logs, sessions, media and Composer credentials. Inspect the resulting image for absence of secrets and check its installed package/lock hash. Use a unique tag and record its immutable image ID.

Build from the same five-input source layout exported by the validator (`resources`, `src`, `package.json`, `package-lock.json`, `vite.config.js`). Repeated builds may change generated CSS or chunk names; resolve a mismatch by rebuilding and retesting the exact image. Never normalize hashes, copy individual chunks or relax the byte comparison.

Run `ops/scripts/verify-frozen-upload-release.py --image EXACT_CANDIDATE --output PRIVATE_AUDIT_DIRECTORY` on the Docker host. It tests exact Pro source, rebuilds the full frontend, and issues image-bound source/asset/override evidence only when all checks pass. It has no skip option. The [gate procedure](frozen-release-gates.md) explains staging this proof alongside current/previous tested release records in the existing storage volume. A passing upload contract does not replace the isolated database/provider rehearsal above.

1. Acquire `/run/lock/infra-deploy-mixpost.lockdir` to exclude dashboard deployments. Check for due publications and drain active publishing jobs. Put the app into maintenance and pause scheduler/workers for the final database checkpoint; do not clear Redis queues.
2. Take another consistent database dump after draining. Save Compose and all current mounts again. Snapshot legacy `storage/mixpost-media/temp/chunked` sessions from the old app too: that location was ephemeral before local uploads. Restore them before each replacement starts; new sessions use persistent `storage/app/peachy-upload-state/chunked`. Existing serialized v6 queue jobs must drain or be reviewed for compatibility before v7 workers consume them.
3. Install reviewed host override files atomically and generate the manifest's read-only mounts. Preserve non-manifest mounts, both normal Compose files and the startup guard's read-only mount/entrypoint. Explicitly retain `MIXPOST_CORE_PATH=mixpost` and `MIXPOST_CALLBACK_PATH=mixpost`; v7 otherwise defaults to the root path. If the reviewed overrides changed after validation, rerun the upload validator against this final candidate/mount combination while maintenance and publishing pauses remain in effect. Stage its passing gate alongside the current gate. Set the app service to the exact frozen image and run `verify-frozen-upload-release.py --image EXACT_CANDIDATE --check-plan PRIVATE_AUDIT_DIRECTORY/upload-gate.json` **before replacing the running baseline**; it checks the normal two-file configuration and rejects stale image/tests, changed baseline, lost mounts, or configuration drift beyond the app image. Other infrastructure changes need a separate reviewed rehearsal/baseline. Then recreate **only** `mixpost` with `--no-deps --pull never` and a temporary third Compose file setting `entrypoint: ["sleep", "infinity"]` and `command: []`. MySQL and Redis remain running. This reviewed temporary entrypoint keeps HTTP, cron and Horizon inactive during migration; it does not waive the upload guard.
4. Before initialization, run `docker exec mixpost-mixpost-1 /usr/local/bin/peachy-start.sh --check-release-only` and require success. Then execute only the initialization portion of the frozen startup, stopping before `service cron start` and supervisord. For the reviewed startup in this repository: `docker exec mixpost-mixpost-1 bash -o pipefail -c "sed '/^service cron start/,\$d' /usr/local/bin/start.sh | bash -e"`. This creates the environment, migrates and caches the app without starting processes. Compare `ops/upgrades/data-fingerprint.php` output before/after; it hashes selected stable user, workspace, account, post, destination and media fields without exposing their values. Review any difference before continuing. Verify runtime and mount hashes while the container is inert, then recreate the app with the normal two Compose files and guarded entrypoint to start web, cron and workers. Resume Horizon if its pause state remains in Redis. Confirm migration completion before resuming normal traffic and workers. Check exact package/source/lock identity, expected image ID, every mounted hash, Horizon, scheduler, health, login, assets and an authenticated page. Recheck Google SSO using its runbook. Confirm protected container IDs are unchanged. Run the controlled `ops/scripts/verify-upload-recovery-storage.php` probe and require completion, content verification, and cleanup; separately verify an authenticated video upload with interruption/retry and record any unavailable check explicitly.
5. Before resuming traffic, run `MediaTemporaryPermissionsTest.php` in the running app as `www-data`; check the configured temporary path too if it differs from the default. Run the live storage probe as `www-data`, never root. Save release evidence and the rollback checkpoint. Monitor the first naturally scheduled publication; do not create an unsolicited social post or email as a test.

### Preserve concurrent repairs

Wait for any other Mixpost operation to release the deployment lock. Refresh the
runtime/configuration baseline and take the rehearsal backup **after** its
accepted change. The November 16 Facebook insights repair widened
`mixpost_facebook_insights.value` to signed `BIGINT` and corrected existing
history; preserve that field and data through both the upgrade and recovery.
Never use a pre-repair database dump for this update's rollback.

### Infra dashboard limitation

The legacy `deploy-mixpost.sh` pulls the vendor `latest` image and cannot freeze Composer resolution or undo database migrations. Do not use its generic Deploy/Rollback buttons for this major-version release. The frozen image uses a different configured image reference so the old updater's preflight refuses it. Until Infra is upgraded to this release contract, use this reviewed playbook for subsequent updates. Never retag a frozen release as vendor `latest` to bypass that guard.

## 5. Rollback

Stop publishing and writes first. If migrations ran, **image-only rollback is insufficient**: restore the matching pre-upgrade database checkpoint, prior frozen application, Compose and override snapshot together. Do not restore a database over later successful publications without first reconciling provider IDs and queued jobs; that can cause duplicate publishing. Retain the displaced database for reconciliation.

Recreate only the app (`--no-deps --pull never`), then verify package identity, schema, every mount, log ownership, health, login, scheduler, Horizon and unchanged MySQL/Redis containers. Do not use `docker compose down -v`, delete media, clear all queues or reconnect social accounts to complete an upgrade.

Preserve prior passing startup gates and their matching override snapshots. A staged candidate proof must not replace the current release's proof. The guard permits a prior tested release only when its actual source/assets/overrides still match; an older image with no passing upload contract remains blocked and needs its full reviewed pre-guard checkpoint for an explicitly chosen emergency rollback.

## Required release evidence

Record old/new versions, package source and lock hashes, base/frozen image IDs, Git commit, mount inventory, checkpoint paths, migration output, regression results and runtime/browser evidence. Clearly distinguish tests with fake providers from witnessed live publishing. Keep sensitive backups on the host; publish only sanitized hashes and outcomes in the repository.

Every accepted deployment must finish through the **automatic checkpoint
completion gate** in [checkpoint storage](checkpoint-storage.md): run its real
final read-only verifier through `checkpoint-maintenance.py operation ...
--acceptance REPORT --verify CHECKER ...`. Require `state: accepted` before
reporting the deployment complete. This records the new checkpoint and
automatically registers superseded accepted predecessors; the newest/current
and previous recovery bundles remain protected. The daily job catches missed
registration writes and flags untracked folders. This hook does not replace any
candidate, rehearsal, browser, provider or explicit acceptance requirement.
Never repeat a deployment mutation to repair a registration error.

## Facebook post insights compatibility gate

Preserve the registered [Facebook post insights compatibility](customizations/facebook-post-insights.md) customization in both fetchers and run `FacebookPostInsightsTest.php` in the isolated candidate. Meta rejects `post_video_views_unique`; including it blocks otherwise valid post and video analytics. Preserve historical values and verify source freshness and Reporting separately after cutover.

## Bluesky publishing resilience gate

Follow [Bluesky publishing resilience](bluesky-publishing-resilience.md) during
Section 2 and preserve/rebase its three versioned patches on every update.
The registered `BlueskyManagesPost.php` is baked into the exact frozen image;
keep the patched Helpers and publishing job in their existing read-only mounts.
The image recipe and manifest both include the publishing trait.

The frozen validator and startup guard require the `bluesky-publishing` suite
(18 offline checks) in addition to all six existing upload/storage suites.
Publishing and parsing-source fingerprints are mandatory even when image-baked.
Upstream adoption must pass the same behavior checks before a patch is retired.
Update the validator, wrapper, tests, and evidence together: gates issued without
the Bluesky suite do not satisfy the updated wrapper. Stage passing evidence
before installing that wrapper, and retain the previous exact wrapper, gates,
image, and mounted files as one compatible rollback checkpoint.

## X media expansion billing gate

The validator and startup guard additionally require `twitter-media-reads`
(17 offline checks) and fingerprints of the exact X provider and import job.
Follow [X media read-cost controls](customizations/x-media-read-costs.md) on every
upgrade. Rebase/apply its two versioned patches to the staged effective Pro
source and run the restored-database integration test before cutover. Upstream
adoption must pass this same contract before a local patch is retired.

Install the wrapper, candidate overrides and passing gate as one release. Old
gates lack the new suite. When the frozen image stays the same, keep its old
gate and stage the new `IMAGE_HEX-OVERRIDE_COMMIT.json` alongside it; rollback restores the
previous wrapper, overrides and gate together.

## TTB comment preservation gate

The frozen gate now requires 155 checks: the existing 115 plus 33 snapshot, two
broadcast and five reply-target checks. It fingerprints all 13 TTB source files,
including its three custom support classes and the effective scheduler. Run the
separate restored-MySQL `DatabaseTest.php` too; the network-disabled gate cannot
prove database transactions. The database test compares the current restored
history counts rather than assuming the November 13 counts remain fixed.

A changed startup wrapper invalidates earlier gates. Validate the candidate with
that wrapper, stage its gate, and install the wrapper only during the locked
cutover. Retain the previous exact wrapper and matching old gate for rollback;
do not install the new wrapper against an unvalidated old image.

## Facebook insights 64-bit preservation gate

The accepted November 16, 2026 database repair widened
`mixpost_facebook_insights.value` from signed `INT NOT NULL` to signed
`BIGINT NOT NULL`. Facebook video watch-time values exceeded both the signed
and unsigned 32-bit limits. Retrying the historical imports without widening
the field could not recover them. The repair corrected 19 outdated daily
values, restored one missing day, and verified all 91 affected dates against
fresh provider data. This is a persistent database change, not an image or
bind-mounted PHP customization.

Private repair and recovery evidence is retained under
`/root/mixpost/backups/facebook-insights-bigint-20261116/`, including the accepted
report, exact expected values, targeted undo, migration SQL and full backup.
That repair's `database.sql` is a **pre-repair undo backup**; it is not a valid
post-repair upgrade baseline. The later failure-history cleanup is recorded
separately under `/root/mixpost/backups/cleared-analytics-failures-20261116/`.
Keep provider payloads, account-level values and database dumps private.

Every subsequent update, including the 7.5.3 update, must:

1. Wait for other Mixpost work to release
   `/run/lock/infra-deploy-mixpost.lockdir`, then refresh the live baseline and
   take a new consistent **post-repair** backup. Capture the accepted affected
   rows and schema privately; verify the restored backup contains the widened
   field and corrected history before testing candidate migrations.
2. Require the same signed `BIGINT`, non-nullability and default contract
   before and after both normal migrations and `mixpost:upgrade-database` in
   the isolated restored candidate. Compare all 91 affected daily values with
   the accepted private baseline. A general user/post/media fingerprint or an
   upload gate alone does not check this table.
3. Exercise the actual candidate's Facebook day-period import on isolated
   MySQL with fake provider responses containing `2147483648` and `4294967296`.
   Require exact write/read round trips and idempotent repeat imports, with
   unrelated analytics rows unchanged. Do not call a real provider or run
   production publishing workers for this regression check.
4. Run the read-only schema/data gate on the inert live candidate after
   migrations, before opening traffic or workers, and again in the final
   acceptance checker. Missing evidence, narrowed types, changed null/default
   behavior, missing dates or changed historical values block acceptance.
   Never clamp large values or convert them to zero to pass the gate.
5. Use the matching **post-repair** upgrade checkpoint for rollback and verify
   this same contract after recovery. An image rollback does not restore a
   schema. Never narrow the field or restore the older repair undo dump over
   corrected history as part of an application update. Protect later writes
   and preserve the exact rollback image, configuration and database evidence.

Record actual baseline, restored-candidate, post-migration, live and rollback
checks separately in the upgrade evidence. A preservation paragraph or an
upstream release note is not evidence that these checks passed. Finish through
the existing checkpoint acceptance wrapper; repair registration metadata
without repeating a cutover or backfill.

The versioned read-only checker is
`ops/upgrades/verify-facebook-insights-bigint.php`. Pass the private baseline
through standard input with explicit scope and date bounds:

```sh
docker cp ops/upgrades/verify-facebook-insights-bigint.php \
  mixpost-mixpost-1:/tmp/verify-facebook-insights-bigint.php
docker exec -i -u www-data mixpost-mixpost-1 php \
  /tmp/verify-facebook-insights-bigint.php --expected=- \
  --workspace-id=24 --account-id=84 --metric-type=28 \
  --from=2026-05-05 --to=2026-08-03 < /PRIVATE/expected-values.json
```

In the isolated restored candidate, run
`php ops/production-overrides/tests/FacebookInsightsBigIntTest.php --workspace-id=24 --account-id=84`.
It calls the real importer twice, checks both 32-bit boundaries and an unrelated
sentinel row, then requires a full-table row hash match after rollback. Never
run that regression against production. Final acceptance must execute the
read-only checker again rather than trusting a saved success result.

## Container replacement details

Restore legacy upload sessions and file sessions before starting each replacement.
If the new image lacks `storage/mixpost-media/temp`, restore the complete relative
`mixpost-media/temp/chunked` tree into the existing `storage` parent, retaining
ownership; copying only its contents to an absent destination fails. Preserve
the separate persistent `storage/app` volume throughout.

For startup proof, inspect actual processes with
`docker top mixpost-mixpost-1 -eo pid,comm,args`, plus the release guard,
`horizon:status`, scheduler, maintenance state and HTTP checks. Include `pid` in
Docker's process format. Do not assume `supervisorctl status` is available: the
current vendor configuration has no supervisorctl section, despite normal
supervisord/web/Horizon processes running. A failed monitoring command must not
be interpreted as a failed app or bypassed; verify the actual required processes.

## Automatic publishing recovery preservation

Preserve [automatic Instagram recovery](customizations/automatic-publishing-recovery.md)
on every update. Exact named collaborator rejections must remove only those
invitations and continue, and definite storage download rejections must retry,
recover identical verified media and continue without repeating live posts.
Both must produce one final explanatory email. Keep account-specific recovery
state, public fallback media, private receipts, original cloud objects and the
30-minute/12-read limits across worker restarts and rollback.

The frozen validator/startup guard require all 22 `instagram-publishing-recovery`
checks and all six source fingerprints. The restored-database rehearsal must
also pass `InstagramPublishingRecoveryIntegrationTest.php`, including actual
account/version/media updates, retry persistence, publication guards and final
summary rendering. An upstream implementation cannot supersede this safeguard
without passing those same cases. Never bypass the suite to boot a new image.
