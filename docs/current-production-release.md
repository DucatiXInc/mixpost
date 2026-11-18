# Current production release

Mixpost Pro **7.6.0** was deployed on November 18, 2026, at 8:45 AM CST
(`2026-11-18T14:45:55Z`). The actual final 38-check read-only verifier ran
through the checkpoint completion wrapper and returned **`state: accepted`**.
Recheck runtime identity before another operation.

| Item | Verified value |
| --- | --- |
| Package | Mixpost Pro Team `7.6.0` |
| Package source | `19b755928f2550f57a2992876a9303bbb884297c` |
| Composer lock SHA-256 | `39910f438fa966ce23233a18a67e44a908a3f1e6d2ec87f091859a08d1a13697` |
| Frozen image | `peachy/mixpost-pro:7.6.0-4ae6b9b-20261118` |
| Immutable image ID | `sha256:e476773d60d442a02aa3409ca0887631737f9dbc0488cdc010e2829bc6554079` |
| Runtime customization source | `4ae6b9b20fc1d3049ae2ce820959c56e4a3ee873` |
| Source merge | [PR #23](https://github.com/DucatiXInc/mixpost/pull/23), `5f37f6ca2df6ac75760b7548919a5a0634c91a6c` |
| App container | `mixpost-mixpost-1`, running, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Release identity | `/root/mixpost/frozen-release.json`; image `peachy-release.json` also matches the installed package |
| Previous tested image, retained locally | `peachy/mixpost-pro:7.5.3-auto-recovery-0c36cb0-20261118` |
| Previous immutable ID | `sha256:b6db5d929f9bf018bb228415ff699e37c46d275d66caed623673b026ce9b23a4` |
| Recovery/evidence root | `/root/mixpost/backups/v760-upgrade-20261118/` |

## Observed evidence

| Stage | Result |
| --- | --- |
| Source | 85 effective custom files reviewed: 82 unchanged, three rebased for video covers and the upstream cleanup schedule; all 69 registered sources preserved |
| CI | Source merge's frozen release guard, asset build and PHP/Laravel tests passed; observed separately from deployment |
| Exact candidate | All 177 frozen checks passed; complete frontend rebuilt and matched byte for byte; startup wrapper retained |
| Isolated rehearsal | All 96 tables restored; 36 regression suites and 21 scheduler checks passed; all 92 business-table fingerprints preserved after eight owned fixture rows were removed |
| Migration | Only media `parent_id`, `is_visible`, parent index and self foreign key added; existing columns/data preserved; every original media row visible and unparented before traffic reopened |
| Analytics preservation | Signed BIGINT, all 91 accepted dates and real-importer boundary/repeat cases passed on the exact candidate, through migrations, before traffic and in final acceptance |
| Cutover | App only; three PHP bind files rebased. Both Compose files, all 53 mounts, environment, credentials, networks, MySQL and Redis preserved |
| Storage | 10 MiB multipart and 12 MiB local-delivery recovery probes verified exact bytes, lost-acknowledgment recovery and cleanup as the app user |
| Authenticated browser | Existing sign-in, normal upload, deliberate interruption at 36%, readable error, successful retry and 24-second 640×360 cloud playback passed; 501 MiB rejected against the 500 MiB limit with no video chunk transferred |
| Cleanup | Both synthetic media rows, all six cloud objects and their versions, two upload sessions and leases removed; UI confirmed no matching files; network conditions restored |
| Final acceptance | All 318 served asset hashes and 38 final checks passed; checkpoint wrapper returned `state: accepted`; operation-owned isolated services removed and deployment lock released |

The size check sent an initiation request that returned HTTP 422; it sent no
video chunks. Do not describe this as rejection before every request.

[Automatic publishing recovery](customizations/automatic-publishing-recovery.md),
Story capture, TTB comment sync, local uploads, provider safeguards, Google SSO,
X analytics/cost controls and the existing upload ingress configuration survive.
No live test social post, comment or email was sent. Provider behavior used fakes
in isolation; a new natural publication and fresh Google OAuth handshake were
not claimed. See the [7.6.0 release record](mixpost-v7-6-0-upgrade.md).

## Recovery

Use `cutover/drained/database.sql`, its exact Compose/override/gate snapshots and
`cutover/ROLLBACK.txt` under the recovery root above. This consistent post-repair
checkpoint preserves the Facebook BIGINT schema and corrected history. The old
Facebook repair's own dump is pre-repair undo material and must not replace it.

The 7.6 media migration means an old image cannot undo the database change.
Stop writes and publishing, preserve/reconcile later user writes, provider IDs,
cover rows, Story captures and inbox activity before any matching database
restore. Restore the previous exact image with its three old PHP files and
matching retained wrapper/177-check gate; recreate only the app. Verify the
BIGINT/all-91-date contract after recovery. Do not blindly rewind later data.

The pre-update current and previous images were exported, checksummed and
reloaded from `exact-current-and-previous-images.tar.gz`. Keep those images,
current candidate, recovery bundles and startup evidence locally. Older evidence
follows [checkpoint storage](checkpoint-storage.md).
