# X publishing media recovery

The November 18 9:00 AM @TrentonDucati delivery uploaded successfully (HTTP
200) but crashed because the next status response omitted `processing_info`.
The response body was not retained, so its underlying provider cause is unknown.
No tweet creation was attempted in that failed run.

Accepted video uploads now return a pending publication checkpoint containing
the exact string media ID. The existing account job persists it in MySQL and
releases itself for X's requested processing interval (1–300 seconds). A fresh
worker polls the same upload without downloading or uploading it again. Only an
explicit `succeeded` processing state allows tweet creation. Synchronous uploads
without processing information remain supported. A reported processing failure
retains its provider explanation.

Missing/unknown status, connection interruption, HTTP 408/429 and server errors
retry with exponential delays from 15 seconds to five minutes. Eight consecutive
bad status responses exhaust recovery; a valid pending response resets this
counter. The existing fixed 30-minute processing deadline survives restarts and
limits otherwise valid pending responses. Permanent permission errors terminate
with the actual provider explanation. Diagnostics retain status code, retry count
and extracted errors rather than replacing them with a PHP property error.

Video-status recovery retries polling before publication. The existing pipeline
preserves published IDs and thread checkpoints.

Successful automatic X recovery sends no email. Final unrecovered
failures still alert the existing recipient. Both ordinary and recovery mail
recheck fresh post state at delivery and suppress stale alerts after recovery or
while another publishing run is active.

Managed images are streamed through the configured storage disk to a temporary
local file before X receives the original bytes. A failed or incomplete read
releases only that destination's job for two retries, after 15 and 30 seconds.
The durable checkpoint reuses completed X media uploads across attempts. A third failure records the storage error and triggers the
normal terminal alert. The temporary copy is removed after each attempt. Local
images use their existing paths, and external images use their existing URLs.
X upload and tweet creation errors retain their existing behavior.

Preserve `ManagesTwitterResources.php`, notification/finalizer overrides, the
`twitter-processing-recovery` frozen suite and mounted startup guard. Every
candidate must pass all 28 exact-source cases and restored-MySQL pipeline/mail
checks, including restart persistence, silent image recovery, one terminal
alert after exhaustion, and no duplicate publication. Rehearsal
fakes provider transport and mail; live retry is only the explicitly authorized
post/account. Follow the frozen release, register, rollback and final checkpoint
acceptance procedures. Rollback retains current data and later provider IDs;
never restore a stale dump over a live publication.

## November 21 image-storage rehearsal

The image-storage recovery from `94cac987a7e53c296dbe323e89fad33f70e1d248`
passed an isolated frozen-image rehearsal on November 21, 2026. This is rehearsal
evidence only; production still runs the November 20 grouped Instagram release.

| Evidence | Result |
| --- | --- |
| Candidate | `peachy/mixpost-pro:7.7.0-x-image-94cac98-rehearsal-20261121` |
| Immutable candidate ID | `sha256:24aeab2e7ab81b5b03e89a768b500d76a45013d4fba077bd9daa1b064578df2c` |
| Licensed package source | Pro Team 7.7.0, `ad194735fe0cb48f15c1a1a2e4fb2822a4a81ce4` |
| Composer lock SHA-256 | `4d364ed2feeab2f37a3200b9fa99fdf308326a7052d542735d826eb2571573ce` |
| Frozen gate | All 205 checks passed, including 28 X media recovery cases; the second full frontend build matched the image assets byte for byte. All 69 registered sources and the candidate startup guard matched. |
| Restored database | A fresh consistent full dump restored all 96 tables to private MySQL. All 38 regression suites and 21 scheduler checks passed with fake provider transport and notifications. |
| Preservation | The schema and all 92 business-table fingerprints matched after migrations and owned fixture cleanup. Signed BIGINT and all 91 accepted Facebook dates were preserved. |
| Container replacement | The same exact image passed the guard after replacement, preserving stored bytes and business-table fingerprints on the isolated persistent volume. |
| Final acceptance | The actual read-only checker passed through the checkpoint operation wrapper, recording `state: accepted` at `2026-11-21T15:30:21Z`. Registration was reconciled without repeating tests or mutations. |

The installed-Pro image has no PHPUnit. The integration fixture therefore reads
the same EventFake and NotificationFake collections directly: pending retries
must dispatch no terminal failure event, and exhausted retries must produce
exactly one on-demand failure notification. Its passing fixture SHA-256 is
`31bfdd0e225225b2b40b8fb7b6035c8491e7e8563b47d5b9b63958935e6fd65f`.
The frozen runtime source and frozen-suite tests were unchanged by that fixture fix.

Private evidence is retained at
`/root/mixpost/backups/x-image-storage-rehearsal-94cac98-20261121/`, including
`accepted-report.json`, `candidate.json`, `validated/upload-gate.json`,
`rehearsal/result.json`, logs, fingerprints and the full checkpoint. The acceptance
report SHA-256 is
`401d1fbd0b758c803f1e3c2ab58565b387e73a7913e9cc3242f8795f5eefbc6e`.

The rehearsal used an internal-only network, separate MySQL/Redis/storage, inert
app containers, and no cron or Horizon. All owned rehearsal containers, volumes
and the network were removed. Production's app/MySQL/Redis identities, Compose,
environment, networks and mounted bytes remained unchanged. No live publication,
email, storage probe or authenticated browser upload was performed. PHPUnit's
health test and the SQLite audience snapshot test were unavailable; the actual
health route and audience report/queue checks passed separately. CI is separate
from this rehearsal. Any later cutover still requires the full release playbook
and fresh production-baseline checks.

## November 18 production acceptance

[PR #25](https://github.com/DucatiXInc/mixpost/pull/25) was deployed in the exact
frozen image recorded in [current production](../current-production-release.md).
All 195 frozen checks, 37 restored-database suites, 21 scheduler checks, live
storage probes and authenticated upload/interruption/retry/playback passed.
The final read-only checker returned `state: accepted` through the checkpoint
wrapper. [PR #26](https://github.com/DucatiXInc/mixpost/pull/26) records the
installed-Pro rehearsal fixtures; both source merges passed CI.

The authorized retry of post 2348 exposed X's actual HTTP 403/code 326 account
lock for @TrentonDucati. It stopped before tweet creation, preserved content and
surfaced the provider explanation. This is a genuine unrecoverable account-owner
action, so terminal alerting remains appropriate. The owner must sign in and
unlock the account before a new retry. The original failed attempt did not retain
its status response, so the later lock response cannot prove what X returned
in the original attempt. No successful live X publication is claimed.
