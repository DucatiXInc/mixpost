# Current production release

## November 21 X image-storage recovery update

Mixpost Pro **7.7.0** reopened at **9:54:42 a.m. CST on November 21, 2026**
(`2026-11-21T15:54:42Z`) with [PR #33](https://github.com/DucatiXInc/mixpost/pull/33).
The actual final read-only checker passed **41 checks** at **10:04:25 a.m. CST**
(`2026-11-21T16:04:25Z`), and the checkpoint operation wrapper recorded
`state: accepted`. The live release record has `acceptancePending: false`.

Managed X images now stage their original bytes through the configured storage
disk into a temporary local file. Failed or incomplete reads release that
destination for retries after 15 and 30 seconds, retaining completed X uploads
in the durable checkpoint. Successful recovery stays silent; exhaustion records
the storage error and retains terminal alerting. See
[X publishing media recovery](customizations/x-processing-recovery.md).

| Item | Verified value |
| --- | --- |
| Licensed package | Mixpost Pro Team `7.7.0` |
| Package source | `ad194735fe0cb48f15c1a1a2e4fb2822a4a81ce4` |
| Composer lock SHA-256 | `4d364ed2feeab2f37a3200b9fa99fdf308326a7052d542735d826eb2571573ce` |
| Frozen image | `peachy/mixpost-pro:7.7.0-94cac98-x-image-storage-20261121` |
| Immutable image ID | `sha256:24aeab2e7ab81b5b03e89a768b500d76a45013d4fba077bd9daa1b064578df2c` |
| Runtime customization source | `94cac987a7e53c296dbe323e89fad33f70e1d248` |
| Standalone fixture and rehearsal record | `5801207ff4951ad80e8a936d48e8fa5f072013c9` |
| Source merge | [PR #33](https://github.com/DucatiXInc/mixpost/pull/33), `57d34986e68824f8b3689bbe00857f794b2384a4` |
| App container | `mixpost-mixpost-1`, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Host release record | `/root/mixpost/frozen-release.json` |
| Deployment evidence and rollback | `/root/mixpost/backups/x-image-storage-deploy-20261121/` |
| Accepted rehearsal | `/root/mixpost/backups/x-image-storage-rehearsal-94cac98-20261121/` |
| Previous image | `peachy/mixpost-pro:7.7.0-a57ca75-instagram-recovery-20261120` |
| Previous immutable ID | `sha256:7f6623f9a656bbb3a4e8918f1f79e19961743642dbecc7ae045ae38717e592d3` |

| Stage | Result |
| --- | --- |
| Source and CI | All three main workflows passed on merge `57d3498`: [frozen release guard](https://github.com/DucatiXInc/mixpost/actions/runs/44920662624), [asset build](https://github.com/DucatiXInc/mixpost/actions/runs/44920662584), and [PHP/Laravel tests](https://github.com/DucatiXInc/mixpost/actions/runs/44920662505). |
| Frozen candidate | The exact rehearsed image was promoted under the production tag. Fresh validation passed all 205 frozen checks, including 28 X media recovery cases, a byte-identical complete frontend rebuild, all 69 registered sources and the matching startup guard. |
| Isolated rehearsal | A fresh consistent dump restored all 96 tables into private MySQL/Redis/storage on an internal-only network. All 38 regression suites and 21 scheduler checks passed with fake provider transport and notifications. Guarded container replacement preserved stored bytes and business fingerprints; owned rehearsal resources were removed. |
| Cutover | Recreated only the app after draining workers and taking a fresh database/configuration checkpoint. Updated the X media trait and matching mounted guard. The guard passed before initialization, and the schema and all 92 business-table fingerprints matched before reopening. Both Compose files, all 53 mount locations, environment, credentials, networks, MySQL and Redis were preserved. |
| Production | All 41 final read-only checks passed, including the live source/asset fingerprints, all 318 served asset hashes, health/login, Horizon, cron, web/broadcast processes, signed BIGINT and all 91 accepted Facebook dates. |
| Storage verification | Both controlled storage probes ran as `www-data` and verified exact bytes and cleanup. The browser fixture's full 12 MiB original also matched its SHA-256 before cleanup. |
| Authenticated browser | The existing signed-in session survived replacement. A 12 MiB video interrupted at an observed 30% resumed in the same chunk session, completed with HTTP 201 and played from HTTPS cloud storage at 640×360. Its media row, session, three objects and all three object versions were removed, with cleanup confirmed in the UI. Browser network conditions were restored. |
| Google sign-in | Existing-user provisioning, Google configuration and the authorization redirect/callback passed. A fresh end-to-end Google login was not performed. |
| Final acceptance | `accepted-report.json` passed through the actual checkpoint operation wrapper with `state: accepted`; registration completed. The live release record and `/root/mixpost/deployment-receipts/x-image-storage-deploy-20261121.json` record this acceptance. |

During browser-fixture cleanup, the storage adapter's initial streamed read
stalled and returned `null`; cleanup stopped before mutations. A bounded S3
`GetObject` read (10-second connection timeout, 60-second request timeout, no SDK
retries) then verified all original bytes and their SHA-256 before removing the
owned fixture. Global storage configuration was unchanged. This was verification
helper recovery; it does not demonstrate a live X publication or recovery email.

No live social test post or email was sent. X publication, a naturally occurring
X storage-recovery outcome, and recovery-email receipt were not witnessed during
acceptance. The next scheduled destination at acceptance was
`2026-11-22T07:15:00Z`; its later outcome is outside this release's acceptance
evidence. The frozen image lacks PHPUnit and PDO SQLite: the standalone health
and audience snapshot tests were unavailable, while actual health-route and
audience report/queue checks passed in the rehearsal.

### November 21 evidence and recovery

Private evidence in the deployment root includes `accepted-report.json`,
`validated/upload-gate.json`, `rehearsal-provenance.json`, `cutover/`, storage and
browser proofs, and `image-recovery-proof.json`. The acceptance report SHA-256 is
`af44d0378a3a3d8b19eb60d030f1d9ef9fcfeb8c098de0112379a114b0b8642d`.

Use this release's `cutover/ROLLBACK.txt` and `cutover/drained/` configuration,
override and gate snapshots. The schema stayed the same: retain the current
database, local upload state, later posts and provider IDs for a code rollback.
Restore the exact November 20 image together with its matching X trait, startup
wrapper, prior passing gate and both Compose files. The consistent drained dump
at `cutover/drained/database.sql` is for disaster recovery; never restore it over
later successful writes without reconciliation.

Both exact current and previous images were exported, checksummed and reload-tested
in `exact-current-and-previous-images.tar.gz`; its SHA-256 is
`87d50a54c4ef401e58483c0b7cea9b927081f97db8eabaa2cdda626bb24d85f6`.
Keep the current/previous recovery bundles, image archive and referenced tools
local under the [checkpoint storage policy](checkpoint-storage.md).

## November 20 grouped Instagram recovery update

Mixpost Pro **7.7.0** reopened with the grouped collaborator recovery fix at
9:28 a.m. CST on November 20, 2026 (`2026-11-20T15:28:00Z`). The package source
and Composer lock match the November 19 release. The update replaces the
image-baked Instagram recovery helper, with the same startup guard and 53 mounts.

| Item | Verified value |
| --- | --- |
| Frozen image | `peachy/mixpost-pro:7.7.0-a57ca75-instagram-recovery-20261120` |
| Immutable image ID | `sha256:7f6623f9a656bbb3a4e8918f1f79e19961743642dbecc7ae045ae38717e592d3` |
| Customization source | `a57ca751eb41bf5bea60d191f076903b75649585` |
| Source merge | [PR #31](https://github.com/DucatiXInc/mixpost/pull/31), `d073d55def3ddbe030407e4e6fb95cd284c85ee2` |
| Evidence and rollback | `/root/mixpost/backups/grouped-instagram-recovery-20261120/` |
| Previous immutable ID | `sha256:381309e428fbac64e4544744abc0e3e806e003cf724635cbaa3083980af388bc` |

| Stage | Result |
| --- | --- |
| Source and CI | The frozen guard, frontend build and PHP/Laravel workflows passed on the source merge. |
| Frozen candidate | All 195 checks passed, the rebuilt frontend matched the image assets, and all 69 registered sources matched the release gate. |
| Isolated rehearsal | All 38 suites and 21 scheduler checks passed. A simulated grouped Meta rejection verified a retry with only the named invites removed and both removals recorded. |
| Cutover | Recreated only the app. The schema, 92 business-table fingerprints, protected services, configuration and mounts passed verification. |
| Production | The exact grouped response matched the running recovery code. Health, login, workers and served assets passed. Both controlled storage probes verified exact bytes and cleanup. |
| Authenticated browser | Confirmed post #2400's published status. Resumed an interrupted 12 MiB upload in the same session, played the recovered clip and verified cleanup of its media row, session and three stored objects. |
| Final acceptance | The read-only checker passed 40 checks, including all 318 served asset hashes, and the checkpoint wrapper recorded `state: accepted` at `2026-11-20T15:31:07Z`. |

Use the release's `cutover/ROLLBACK.txt` and `cutover/drained/` snapshot with
the verified exact image archive. The drained dump is for disaster recovery.
Retain the live database and provider IDs for a code rollback because the schema
stayed the same.

## November 19 Pro 7.7.0 baseline

Mixpost Pro **7.7.0** reopened in production on November 19, 2026, at
10:57 PM CST (`2026-11-20T04:57:10Z`). The final read-only checker passed 38
checks at `2026-11-20T05:01:54Z` and the checkpoint wrapper recorded
`state: accepted`. Verify the running identity before the next upgrade.

| Item | Verified value |
| --- | --- |
| Licensed package | Mixpost Pro Team `7.7.0` |
| Package source | `ad194735fe0cb48f15c1a1a2e4fb2822a4a81ce4` |
| Composer lock SHA-256 | `4d364ed2feeab2f37a3200b9fa99fdf308326a7052d542735d826eb2571573ce` |
| Frozen image | `peachy/mixpost-pro:7.7.0-38bc7b8-rebuild-20261119` |
| Immutable image ID | `sha256:381309e428fbac64e4544744abc0e3e806e003cf724635cbaa3083980af388bc` |
| Customization source | `38bc7b839ffaeeb3ec1f17efa9bc52d2212f800b` |
| Source merge | [PR #29](https://github.com/DucatiXInc/mixpost/pull/29), `470489abb9f9f1032cd33f319756ae4a16f8159f` |
| Upgrade decision | [Playbook PR #28](https://github.com/DucatiXInc/mixpost/pull/28) |
| App container | `mixpost-mixpost-1`, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Host release record | `/root/mixpost/frozen-release.json` |
| Recovery and evidence root | `/root/mixpost/backups/v770-rehearsal-20261119/` |
| Previous accepted release | [Pro 7.6.0 with durable X processing recovery](https://github.com/DucatiXInc/mixpost/pull/27) |
| Previous immutable ID | `sha256:9a23f7e6a418fdf94ca3b8765c3145029e2ba6ae11ad9cc7af631f084d50860b` |

## November 19 release evidence

| Stage | Result |
| --- | --- |
| Source and CI | PR #29 matched the rehearsed source. The frozen guard, frontend build and PHP/Laravel checks passed on the source merge. |
| Frozen image | 195 checks passed. The rebuilt frontend matched the exact image byte for byte and all 69 registered sources and the mounted guard matched the gate. |
| Isolated rehearsal | 96 tables restored. All 38 regression suites and 21 scheduler checks passed. REST and MCP returned the clearer format message. Simulated Meta and mail tests verified recovery, workspace recipients, duplicate suppression and retained terminal alerts. |
| Database | The schema and all 92 business-table fingerprints matched before reopening. Signed BIGINT and all 91 accepted Facebook dates were verified in production. |
| Cutover | Recreated only the app. Updated the mounted publishing finalizer and delivered the recovery and notification code in the image. Both Compose files, all 53 mount locations, the environment, credentials, networks, MySQL and Redis were preserved. |
| Production | Health, login, Horizon, cron and all 318 served asset hashes passed. Both storage probes verified exact bytes and cleanup. |
| Authenticated upload | Interrupted a 12 MiB video at 10%, resumed the same session, and played the recovered 640×360 video. Verified removal of the synthetic record, its session and all three cloud objects and their versions. |
| Google sign-in | Verified the login configuration, existing-user policy and Google redirect with the expected callback. The authenticated browser check used an existing session. |
| Final acceptance | The actual read-only checker passed 38 checks. The previous checkpoint's registration metadata was reconciled after verifying its acceptance hash and both recovery dumps. |

7.7.0 explains the username format at save time. When Instagram later publishes
after the automatic removal of a rejected collaborator, all users in the owning
workspace receive the removed handles and a link. See
[automatic publishing recovery](customizations/automatic-publishing-recovery.md).
The workspace email was verified in rehearsal. A live collaborator rejection
and inbox receipt had yet to occur at acceptance.

## November 19 recovery

Use `cutover/ROLLBACK.txt`, the drained backup and both Compose/override/gate
snapshots in the recovery root. Both exact images were exported, checksummed
and reload-tested. For a rollback to the previous 7.6.0 release, retain current
database contents and provider IDs and restore its exact image, matching bind
file and gate. The drained database is for disaster recovery. Preserve the
signed BIGINT and 91-date contract, public recovered files and later writes.
Follow [checkpoint storage](checkpoint-storage.md) for retention.
