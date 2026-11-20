# Current production release

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

## Release evidence

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

## Recovery

Use `cutover/ROLLBACK.txt`, the drained backup and both Compose/override/gate
snapshots in the recovery root. Both exact images were exported, checksummed
and reload-tested. For a rollback to the previous 7.6.0 release, retain current
database contents and provider IDs and restore its exact image, matching bind
file and gate. The drained database is for disaster recovery. Preserve the
signed BIGINT and 91-date contract, public recovered files and later writes.
Follow [checkpoint storage](checkpoint-storage.md) for retention.
