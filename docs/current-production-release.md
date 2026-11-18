# Current production release

Mixpost Pro **7.6.0 with durable X processing recovery** was deployed on
November 18, 2026, at 9:31 AM CST (`2026-11-18T15:31:46Z`). The actual final
31-check read-only verifier ran through the checkpoint completion wrapper and
returned **`state: accepted`** at `2026-11-18T15:42:40Z`. Recheck runtime
identity before another operation.

| Item | Verified value |
| --- | --- |
| Package | Mixpost Pro Team `7.6.0` |
| Package source | `19b755928f2550f57a2992876a9303bbb884297c` |
| Composer lock SHA-256 | `39910f438fa966ce23233a18a67e44a908a3f1e6d2ec87f091859a08d1a13697` |
| Frozen image | `peachy/mixpost-pro:7.6.0-x-status-6f80233-20261118` |
| Immutable image ID | `sha256:9a23f7e6a418fdf94ca3b8765c3145029e2ba6ae11ad9cc7af631f084d50860b` |
| Runtime customization source | `6f8023333d2756e880b6286f4e97aa9db163e01e`, layered on the accepted 7.6.0 image |
| Source merge | [PR #25](https://github.com/DucatiXInc/mixpost/pull/25), `623121be35a8fb6f8951b16bf946ba44b829aaf1` |
| Rehearsal fixture merge | [PR #26](https://github.com/DucatiXInc/mixpost/pull/26), `b412bee582988035bf60b4b1baa41e4c5ea3d0e4` |
| App container | `mixpost-mixpost-1`, running, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Release identity | `/root/mixpost/frozen-release.json`; packaged `peachy-release.json` retains the licensed package identity |
| Previous tested image, retained locally | `peachy/mixpost-pro:7.6.0-4ae6b9b-20261118` |
| Previous immutable ID | `sha256:e476773d60d442a02aa3409ca0887631737f9dbc0488cdc010e2829bc6554079` |
| Recovery/evidence root | `/root/mixpost/backups/x-processing-recovery-20261118/` |

## Observed evidence

| Stage | Result |
| --- | --- |
| Source | Durable X processing checkpoints and failure-only email behavior; all 69 registered sources matched the exact candidate |
| CI | Implementation and rehearsal-fixture merges each passed frozen guard, asset build and PHP/Laravel tests; observed separately from deployment |
| Exact candidate | All 195 frozen checks passed, including 18 X processing recovery cases; complete frontend rebuilt and matched byte for byte |
| Isolated rehearsal | All 96 tables restored; 37 regression suites and 21 scheduler checks passed; all 92 business-table fingerprints preserved after eight owned fixture rows were removed |
| Database | No schema change; unchanged business data through inert initialization and before reopening; signed BIGINT, all 91 accepted Facebook dates and importer boundary/repeat tests preserved |
| Cutover | App only; three PHP binds and the mounted guard updated, plus the recovery notification in the frozen image. Both Compose files, all 53 mounts, environment, credentials, networks, MySQL and Redis preserved |
| Storage | 10 MiB multipart and 12 MiB local-delivery recovery probes verified exact bytes, lost-acknowledgment recovery and cleanup as the app user |
| Authenticated browser | Normal upload, deliberate interruption at 19%, readable connection error, successful same-session retry and 16-second 640×360 cloud playback passed; 501 MiB rejected against the 500 MiB limit with an initiation HTTP 422 and no video chunks |
| Cleanup | Three synthetic media rows, all nine cloud objects and their versions, and the accepted chunk session removed; UI confirmed no matching files; browser network conditions restored |
| Final acceptance | All 318 served asset hashes and 31 final checks passed; wrapper returned `state: accepted`; owned isolated services removed and deployment lock released |
| Authorized post retry | Post 2348/account 101 was retried once through the normal UI. X returned HTTP 403/code 326: account temporarily locked. No tweet creation was attempted and caption/media were unchanged. Publishing requires the owner to unlock @TrentonDucati in X |

[Durable X processing recovery](customizations/x-processing-recovery.md) retries
incomplete status responses, connection failures, rate limits and server errors
without re-uploading accepted media. Processing state and deadlines survive worker
restarts. Successful automatic recovery is silent across providers. Queued
failure emails recheck fresh state; unrecovered terminal failures still alert.
The account-lock outcome is accepted evidence of correct terminal handling,
**not a successful publication**. No inbox receipt or successful live X publication
is claimed for this operation.

Story capture, TTB comment sync, Instagram recovery, local uploads, provider
safeguards, Google SSO, analytics/cost controls and the upload ingress configuration
remain preserved. The earlier [7.6.0 upgrade record](mixpost-v7-6-0-upgrade.md)
retains its separate migration, browser and recovery evidence.

## Recovery

Use this checkpoint's `cutover/drained/database.sql`, exact Compose/override/gate
snapshots and `cutover/ROLLBACK.txt`. Current and previous exact images were
exported, checksummed and reload-tested. This fix has no schema changes: drain
publishing and restore the prior 7.6.0 image with its matching binds, wrapper and
177-check gate while retaining current data, recovered files and later provider
IDs. Never restore a stale dump over later posts or an accepted publication.

The earlier 7.6.0 migration added media parent/visibility columns and a self
foreign key. Reverting farther to 7.5.3 still requires the matching database-aware
upgrade rollback plan; an old image cannot undo that migration. Preserve the
Facebook BIGINT and all-91-date contract after recovery. Keep current/previous
bundles and image exports local; older evidence follows
[checkpoint storage](checkpoint-storage.md).
