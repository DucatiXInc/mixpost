# Current production release

Final read-only production acceptance: **November 16, 2026, 10:39 AM PST**
(`2026-11-16T18:39:25Z`). The checkpoint completion wrapper returned
**`state: accepted`**. Recheck runtime identity before another operation.

| Item | Verified value |
| --- | --- |
| Package | Mixpost Pro Team `7.5.3` |
| Package source | `1f67267fc3b222ed31f3d4999e49dee1856b7e58` |
| Composer lock SHA-256 | `06864cdce0f652b10122c113b1867d200e7c7c21d7575445e9451d521fe1479b` |
| Frozen image | `peachy/mixpost-pro:7.5.3-15eab5c-20261116` |
| Immutable image ID | `sha256:fddbe8204282b56b3e39a752d1538f3bf19d39370304cac50113e8215d073046` |
| Runtime customization source | `15eab5ccefe9029a607be933ae13e7eb4db07195` |
| App container | `mixpost-mixpost-1`, running, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Release identity | `/root/mixpost/frozen-release.json` |
| Previous tested image, retained locally | `peachy/mixpost-pro:7.5.1-ttb-comment-sync-v5-20261113` |
| Previous immutable ID | `sha256:c0981ce600be1884bc22203299ad0f0440ee185502090bac5fa87cc17cb6b541` |
| Recovery/evidence root | `/root/mixpost/backups/v753-upgrade-20261116/` |

## Evidence for 7.5.3

| Evidence | Observed result |
| --- | --- |
| Source | [PR #16](https://github.com/DucatiXInc/mixpost/pull/16): preserved/rebased customizations and expanded startup contract; [#17](https://github.com/DucatiXInc/mixpost/pull/17) and [#18](https://github.com/DucatiXInc/mixpost/pull/18): portable rehearsal and database preservation tools; [#19](https://github.com/DucatiXInc/mixpost/pull/19): complete register-test fixture |
| CI | Guard, PHP/Laravel tests and asset build passed on source merge `1e6e6a6`; CI is separate from production acceptance |
| Exact frozen candidate | 155 required checks; complete frontend rebuild matched byte for byte; all 66 registered customizations and wrapper match source |
| Isolated rehearsal | All 96 tables restored from the fresh post-repair backup; 34 regression suites, 21 scheduler checks and five additional BIGINT importer checks passed; all 92 business-table fingerprints matched after owned fixture teardown |
| Database preservation | No new migrations; normal migrations and upgrade-database preserve schema/data. Signed BIGINT and all 91 accepted Facebook dates passed explicit isolated before/after, live pre-traffic and final read-only gates |
| Cutover | App only; MySQL/Redis identities, environment, networks and all 53 mounts preserved; all business/schema fingerprints matched before reopening |
| Served assets | All 317 active files matched the exact image's hashes |
| Storage | 10 MiB multipart and 12 MiB delivery probes passed lost-acknowledgment recovery, byte readback and cleanup, including owned object versions |
| Authenticated browser | Dashboard/accounts/library, normal upload, interrupted chunk transfer at 12%, readable interruption message, retry and 24-second 640×360 playback passed; a 501 MiB file was rejected against the 500 MiB limit before chunk bytes were sent |
| Fixture cleanup | Both 15,557,103-byte originals passed hash readback; both media rows, six objects/all their versions and owned upload sessions were removed; personal library empty again; network conditions and original workspace/accounts page restored |
| Final completion | Actual 36-check read-only verifier ran through checkpoint-maintenance and returned `state: accepted`; deployment lock released |

The temporary isolated database/cache/app, their two volumes/internal network,
inert build container and temporary credential files were removed. Database
checkpoints, source/build evidence, exact recovery images and the image archive
remain retained. Source, CI, rehearsal, cutover, storage and browser results are
separate evidence; the passing Lite CI does not prove the licensed runtime.

## Recovery and persistent contracts

Use `cutover/drained/database.sql`, its configuration/override/gate snapshots and
`cutover/ROLLBACK.txt` under this release's recovery root. This is a **post-repair**
backup. The older Facebook repair's own `database.sql` is pre-repair undo material
and must not replace corrected history during application rollback.

The old image requires its matching old wrapper, three previous bind files and
115-check gate; the new wrapper requires 155 checks. Keep the pair together.
Both previous exact images were exported, checksummed and successfully reloaded
from `current-and-previous-images.tar.gz`; the accepted archive and immutable
images remain local. Never prune them without a separately verified recovery plan.

[Story capture](instagram-story-media.md), [TTB comment availability](../ops/comment-sync/README.md),
local upload/recovery, provider safeguards, X read-cost controls and the
three-hour first-72-hours X schedule remain active. Story and TTB schedules and
Horizon were checked; no manual reconciliation or live comment test was run.
Existing SSO settings/history were preserved, and the Google login button and
existing authenticated session worked. A fresh Google OAuth handshake and a
naturally scheduled provider publication were not witnessed for this release.
No test social post or comment was sent.

## Retained earlier evidence

Story capture was shipped in [PR #10](https://github.com/DucatiXInc/mixpost/pull/10),
with acceptance recorded in [PR #11](https://github.com/DucatiXInc/mixpost/pull/11).
The November 13 TTB release's native-deletion fallback test passed via two
complete absence scans; immediate native webhook delivery was not observed.
Those historical tests do not prove new provider permissions or a new live
publication. See the feature runbooks and [checkpoint storage](checkpoint-storage.md)
for older evidence and archive receipts. Annex's existing SELECT-only reader
was previously verified; this update did not deploy an Annex display change.
