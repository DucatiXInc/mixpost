# X video processing recovery

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

This recovery does not replay tweet creation after a lost create response. It
only retries status reads before publication. The existing pipeline preserves
published IDs and thread checkpoints.

Successful automatic X recovery sends no email. Final unrecovered
failures still alert the existing recipient. Both ordinary and recovery mail
recheck fresh post state at delivery and suppress stale alerts after recovery or
while another publishing run is active.

Preserve `ManagesTwitterResources.php`, notification/finalizer overrides, the
`twitter-processing-recovery` frozen suite and mounted startup guard. Every
candidate must pass all 18 exact-source cases and restored-MySQL pipeline/mail
checks, including restart persistence and no duplicate publication. Rehearsal
fakes provider transport and mail; live retry is only the explicitly authorized
post/account. Follow the frozen release, register, rollback and final checkpoint
acceptance procedures. Rollback retains current data and later provider IDs;
never restore a stale dump over a live publication.

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
