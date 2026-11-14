# Mixpost project documentation

Production runs a frozen Pro Team application on `mixpost-hetzner`; this checkout
contains the Lite package and registered production customizations. Use the
running release record and exact Pro source when planning an update.

## Operations and releases

| Document | Use it for |
| --- | --- |
| [Production runbook](production-runbook.md) | Host details, read-only health checks, queues and configuration |
| [Current production release](current-production-release.md) | November 13 verified runtime and separate release evidence |
| [Update playbook](mixpost-update-playbook.md) | Candidate preparation, rehearsal, checkpoint, app-only cutover and rollback |
| [Frozen release gates](frozen-release-gates.md) | Exact-source behavior checks, complete assets and startup evidence |
| [Instance alterations](mixpost-instance-alterations.md) | Customization intent and dated incident/repair history |
| [Checkpoint storage](checkpoint-storage.md) | Local recovery material, verified encrypted archives and retrieval |
| [Generated deployment summary](../DEPLOY.md) | Infra's generated deployment entry point |

## Features and troubleshooting

| Document | Use it for |
| --- | --- |
| [Instagram Story media](instagram-story-media.md) | Hourly durable captures and the Annex display contract |
| [TTB comment availability](../ops/comment-sync/README.md) | Account-109 reconciliation, preserved history, tests and rollback |
| [Instagram standalone OAuth](instagram-standalone-oauth.md) | Meta app identities, callbacks, account connection and review history |
| [Instagram media download timeout](instagram-media-download-timeout.md) | Public storage URLs and bounded container-creation retry |
| [Bluesky publishing resilience](bluesky-publishing-resilience.md) | Mentions, optional thumbnails and partial-thread failures |
| [Post failure notifications](post-failure-notifications.md) | Aggregate failure emails and sanitized provider explanations |
| [Video upload recovery](video-upload-recovery.md) | October 10 incident history and recovery acceptance cases |
| [Threads video optimization](threads-video-optimization.md) | Provider-safe derivatives and readiness checks |
| [Imported-thumbnail storage](mixpost-thumbnail-storage.md) | Repeat-import reuse and guarded cleanup history |
| [Health endpoint](mixpost-health-endpoint.md) | Liveness response and its limits |
| [Domain migration plan](posting-domain-migration.md) | Proposed move to `posting.ducatix.com`; not evidence of cutover |

Dated acceptance records describe checks performed for that release. They do not
prove a later image, a new provider permission, or a current browser session.
Local source, committed source, CI, rehearsal, deployment, storage checks and
authenticated browser proof must be reported separately.
