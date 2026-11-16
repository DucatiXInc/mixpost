# X post refresh schedule

The production scheduler uses rolling UTC publication-age windows for authorized,
active X accounts. The newest posts refresh every three hours during their first
72 hours. The windows are disjoint so the daily import does not reread them.

| Post age | Cadence | UTC dispatch |
| --- | --- | --- |
| 0–3 days | Every three hours | 00:05, 03:05, 06:05, 09:05, 12:05, 15:05, 18:05, 21:05 |
| 3–7 days | Daily | 00:05 |
| 7–30 days | Every two days | 00:20 on the existing UTC interval days |
| 30–90 days | Weekly | Monday 00:40 |
| Older than 90 days | Every 30 days | 01:00 on the existing UTC interval days |

The first two windows meet at exactly 72 hours before dispatch. These are refresh
opportunities, not a guarantee of an API response at that minute. Queue delays,
provider failures, permissions and the configured analytics range still apply.
The full-history analytics setting preserves these explicit windows. Restricted
7/30-day settings and disabled analytics must continue to constrain actual reads.

The import refreshes cumulative metrics and the UTC-day history reading. Multiple
refreshes on the same day update that day's reading; they do not create a separate
three-hour history series. Imports exclude replies and reposts, paginate at up
to 100 posts per request with a 60-second next-page delay, and preserve the
[media-read cost controls](x-media-read-costs.md). Recent posts have eight daily
refresh opportunities instead of one, with API usage depending on returned posts.

## Implementation and acceptance

The mounted `Schedule.php` is registered in the customization register and
deployment manifest. Rebase the two newest X windows onto the effective live
scheduler rather than replacing it wholesale: the production version also
contains TTB reconciliation that predates this change and may not be mirrored
in the repository scheduler. Preserve that callback, Story capture registration,
staged-media delivery, all other provider jobs, both Compose files and the startup
guard. No image, asset, schema or provider-import change is required.

Run `php ops/production-overrides/tests/TwitterAnalyticsCadenceTest.php` against
the repository source and again with `MIXPOST_SCHEDULE_PATH` pointing at the exact
production candidate. It checks dispatch frequencies, rolling bounds, eligibility,
older cadence predicates and absence of the overlapping old 0–7-day daily window.
The frozen validator, exact source register check, isolated restored-database
scheduler rehearsal and live schedule/startup checks remain required. Do not
dispatch live imports merely as a scheduler test.

Checkpoint the full database, every bind override, both Compose files, effective
configuration, release record and matching startup gates before installation.
A scheduler-only installation can pause cron briefly, preserve the existing
bind-mounted file inode, install the tested bytes and their matching gate, verify
the runtime, and resume cron without restarting the app or its workers. Keep the
old file and gate together for rollback; no database rollback is needed for this
change, and later business data must be preserved. Finish with the read-only
checkpoint acceptance wrapper. Report source/CI, rehearsal, installation, storage
and authenticated browser evidence separately.

Retirement requires demonstrating the same refresh windows, usage controls and
other production schedules against the exact replacement candidate.
