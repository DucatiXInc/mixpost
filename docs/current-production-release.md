# Current production release

Read-only runtime verification: **November 13, 2026, 11:42 PM PST**
(`2026-11-14T07:42:27Z`). Recheck before any deployment or recovery operation.

| Item | Verified value |
| --- | --- |
| Package | Mixpost Pro Team `7.5.1` |
| App image | `peachy/mixpost-pro:7.5.1-ttb-comment-sync-v5-20261113` |
| Immutable image ID | `sha256:c0981ce600be1884bc22203299ad0f0440ee185502090bac5fa87cc17cb6b541` |
| Container | `mixpost-mixpost-1`, running, 53 mounts |
| Effective Compose files | `/root/mixpost/docker-compose.yml` and `/root/mixpost/docker-compose.dashboard-overrides.yml` |
| Release record | `/root/mixpost/frozen-release.json` |
| Previous image, present locally | `peachy/mixpost-pro:7.5.1-ig-stories-20261113` |
| Previous immutable image ID | `sha256:0ddd17529272f5ac216b533b3b89729a7b44a268acc1eaa6bc71da18ee9dab8f` |

The previous image's presence was checked directly. Recovery also needs its
matching overrides, wrapper, passing gate and reviewed data plan. Image presence
alone is not a new restore rehearsal. Preserve both exact images before Docker
cleanup; saved configuration cannot recreate an unavailable frozen image.

## Evidence by release

| Evidence | Story capture release | Current TTB comment release |
| --- | --- | --- |
| Source | [PR #10](https://github.com/dtk79/mixpost/pull/10), merge `d34150c`; acceptance recorded in [PR #11](https://github.com/dtk79/mixpost/pull/11) | Reviewed local/private source; uncommitted additions remain; no push or CI claimed |
| CI | Release guard, PHP/Laravel matrix and asset build passed for the Story source | No separate TTB CI run claimed |
| Exact candidate | 115 behavior/build checks; 28 Story checks included | 115 frozen checks; complete frontend rebuilt on deployment host |
| Isolated rehearsal | 31 suites, including 13 Story MySQL checks; 91 original business tables preserved | 126 checks and three additional smokes; 92 data/schema fingerprints matched before reopening |
| Cutover | November 13, 10:30 AM PST; app only | November 13, 12:22:39 PM PST; app only; Story capture preserved |
| Storage | Five first-capture objects passed public byte readback; controlled probes cleaned | Controlled recovery and synthetic upload cleanup passed |
| Authenticated browser | Upload interruption/retry and playback passed; 315 served assets checked | Inbox, upload interruption/retry and playback passed; 317 served assets checked |

These are recorded acceptance results, not tests rerun by this documentation
refresh. The refresh directly checked the running image, effective Compose files,
mount count, previous image, registered schedule and Horizon status. Horizon was
running; the hourly Story task and production analytics schedules were present.

Private evidence roots:

- Story release: `/root/mixpost/backups/ig-stories-20261113/`.
- Current cutover: `/root/mixpost/backups/ttb-comment-sync-cutover-20261113T194248Z/`.
- Subsequent approved live deletion test: `/root/mixpost/backups/ttb-live-deletion-20261113T205149Z/`.

Check [archive receipts](checkpoint-storage.md) when locating superseded evidence;
do not assume every historical path remains local.

## Active behavior and limits

[Story capture](instagram-story-media.md) preserves images, video covers and
available videos hourly. Initial capture had two cover-only videos because Meta
omitted video URLs. Annex's existing read-only database connection was verified;
this is not evidence that Annex's display integration was deployed.

[TTB comment availability](../ops/comment-sync/README.md) is restricted to
account 109 in workspace 12. The approved native-deletion test passed via two
scheduled complete absence scans, about 49 minutes after deletion. Immediate
native deletion-webhook delivery was not observed. Saved history remains intact.
Further live tests and manual production writes require separate authorization.

The original local working copy used for the documentation review was behind the
merged Story release. These docs were reconciled onto current main for shipping;
Story source and release tools are included in that merged baseline. The current
TTB comment patch remains local/private source, as recorded above. Reconcile
its effective source and tests with the reviewed release tools before preparing
another candidate; a Git checkout alone does not capture every live change.

## November 14 pre-ship recheck

The app image tag and immutable ID above still matched production during the
read-only shipping check. At `2026-11-14T15:44:38Z` (07:44 AM PST), the authorized
checkpoint maintenance timer was loaded but disabled/inactive, with no next
activation. See [checkpoint storage](checkpoint-storage.md). No runtime or timer
configuration was changed by this documentation release.
