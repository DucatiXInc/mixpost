# Current production release

Automatic Instagram publishing recovery was deployed and accepted on
**November 18, 2026**. The actual final read-only verifier ran through the
checkpoint completion wrapper and returned **`state: accepted`**.
Recheck runtime identity before another operation.

| Item | Verified value |
| --- | --- |
| Package | Mixpost Pro Team `7.5.3` |
| Package source | `1f67267fc3b222ed31f3d4999e49dee1856b7e58` |
| Composer lock SHA-256 | `06864cdce0f652b10122c113b1867d200e7c7c21d7575445e9451d521fe1479b` |
| Frozen image | `peachy/mixpost-pro:7.5.3-auto-recovery-0c36cb0-20261118` |
| Immutable image ID | `sha256:b6db5d929f9bf018bb228415ff699e37c46d275d66caed623673b026ce9b23a4` |
| Runtime customization source | `0c36cb0130f7858c59401fdfafcc797e8089096d` |
| Source merge | [PR #21](https://github.com/DucatiXInc/mixpost/pull/21), `9e3dccd0da9c8ce68e283f24553f8135ad97038e` |
| App container | `mixpost-mixpost-1`, running, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Release identity | `/root/mixpost/frozen-release.json` |
| Previous tested image, retained locally | `peachy/mixpost-pro:7.5.3-15eab5c-20261116` |
| Previous immutable ID | `sha256:fddbe8204282b56b3e39a752d1538f3bf19d39370304cac50113e8215d073046` |
| Recovery/evidence root | `/root/mixpost/backups/automatic-publish-recovery-20261118T054048Z/` |

## Observed evidence

| Stage | Result |
| --- | --- |
| Source | Account-scoped storage/collaborator recovery, final summary emails, carousel child reuse, registration and required upgrade checks merged in PR #21 |
| CI | Frozen release guard, asset build and PHP/Laravel tests passed on merge `9e3dccd`; CI was observed separately from deployment |
| Exact candidate | All 177 frozen checks passed; complete frontend rebuilt and matched byte for byte; all 69 registered customizations and wrapper match |
| Isolated rehearsal | 36 recovery integration checks and six existing compatibility suites passed against restored MySQL; all 92 business-table fingerprints preserved after owned fixture cleanup |
| Analytics preservation | Signed BIGINT and 91 accepted historical dates passed before/after both migrations in isolation, real-importer boundary/repeat checks, live pre-traffic checks and final acceptance |
| Cutover | App only; four existing bind files changed, including the wrapper. Both Compose files, all 53 mounts, MySQL/Redis identities, environment and networks preserved. Schema and all business fingerprints matched before reopening |
| Storage | Controlled 10 MiB and 12 MiB recovery probes passed byte verification, lost-acknowledgment recovery and cleanup. The actual new fallback recovered an identical 12 MiB cloud original, verified its public copy and promoted only the owned test asset |
| Authenticated browser | Existing sign-in survived. Interrupted upload at 34% showed the readable error, retry succeeded, and the 24-second 640×360 video played through the new public fallback URL. A 501 MiB file was rejected against the 500 MiB limit before chunk transfer |
| Cleanup | One synthetic media row, three owned cloud objects/all their versions, its public fallback, private receipt and upload session were removed. Browser network conditions restored; the original Instagram/X/Bluesky post remained published with unchanged delivery IDs |
| Final acceptance | Actual 38-check read-only verifier and all 317 served asset hashes passed through the checkpoint wrapper; `state: accepted`; deployment lock released |

The [recovery contract](customizations/automatic-publishing-recovery.md) and
[update playbook](mixpost-update-playbook.md#automatic-publishing-recovery-preservation)
remain required on every future exact candidate. The startup guard requires the
new 22-check suite; release notes alone cannot retire this behavior.

Provider rejections and summary email behavior were tested with fakes in the
isolated rehearsal. No live test social post or email was sent, and no natural
collaborator failure has yet been witnessed under this release. The real storage
fallback and authenticated browser upload/retry/playback were exercised live.
A fresh Google OAuth handshake was not performed.

## Recovery and earlier evidence

Use `cutover/drained/database.sql`, its exact configuration/override/gate snapshots
and `cutover/ROLLBACK.txt` under the recovery root above. This is a fresh
post-Facebook-repair checkpoint. The old Facebook repair's own database dump is
pre-repair undo material and must not overwrite corrected history.

Rollback uses the previous exact image and its matching old wrapper, three PHP
bind files and 155-check gate. Preserve later publication IDs, account-specific
recovery state and recovered public media. Schema did not change; never rewind
later writes blindly. Current and previous images were exported, checksummed and
successfully reloaded from `exact-current-and-previous-images.tar.gz`; retain it.

The [upstream 7.5.3 upgrade](mixpost-v7-5-3-upgrade.md), [Story capture](instagram-story-media.md),
[TTB comment availability](../ops/comment-sync/README.md), local uploads, provider
safeguards and X analytics cadence remain preserved. Older evidence and archive
receipts follow [checkpoint storage](checkpoint-storage.md).
