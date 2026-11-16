# TTB Instagram comment availability

This account-109-only patch was deployed with Dan's approval on 2026-11-13 at
20:22:39 UTC. It adds no database migration and never prunes inbox history.

The initial November 13 deployment used
`peachy/mixpost-pro:7.5.1-ttb-comment-sync-v5-20261113`, image
`sha256:c0981ce600be1884bc22203299ad0f0440ee185502090bac5fa87cc17cb6b541`.
The [accepted 7.5.3 update](../../docs/mixpost-v7-5-3-upgrade.md) now runs in
production and preserves this behavior under the expanded 155-check gate.
For the November 13 release, the reviewed v3 source was unchanged; the complete frontend was rebuilt on the
deployment host and artifact permissions corrected before the exact frozen
gate passed. Evidence, fresh full/row/configuration checkpoints and rollback
instructions are in
`/root/mixpost/backups/ttb-comment-sync-cutover-20261113T194248Z/`.
Local report copies are in `/tmp/mixpost-ttb-comment-sync-deploy-20261113/`.

All 115 frozen checks, 126 rehearsal checks, three additional regression
smokes and isolated authenticated browser states passed. Production health,
317 served assets, authenticated inbox, interrupted upload/retry, playback and
controlled storage recovery passed; the synthetic upload was fully cleaned.
All 92 data/schema fingerprints matched before reopening. TTB's 43 threads,
217 messages, 175 authors, post 2314 and six existing story rows were preserved.
The scheduler's first rotation completed 42 threads and safely skipped one
incomplete scan.

A separately approved live test from **@dtastical** subsequently passed. The
native Instagram comment was removed, then the untouched scheduler recorded
complete absence checks at **21:15:16** and **21:45:16 UTC**. It marked the saved
copy unavailable after about 49 minutes. The open inbox updated without reload,
kept history, hid the unavailable comment's Reply/Like controls and selected an
available earlier reply target. Immediate native deletion-webhook delivery was
not observed; this proves the scheduled fallback. No manual reconciliation,
marker edit or shortened grace was used. All original history, post 2314 and
story capture were preserved; the original thread unread flag was restored.
Evidence and three fresh full/row checkpoints are under
`/root/mixpost/backups/ttb-live-deletion-20261113T205149Z/`; local report copies
are under `/tmp/mixpost-ttb-live-deletion-20261113/`.
Further manual production writes and live tests require Dan's next OK.

The existing Instagram webhook parser handles `verb=remove`, but a native
deletion was not observed in the earlier live test. This patch retains a verified
deletion event as an unavailable marker instead of deleting its saved message.
It also supplies a periodic fallback when no deletion event arrives. Meta's
[Instagram webhook guide](https://developers.facebook.com/docs/graph-api/webhooks/getting-started/webhooks-for-instagram/)
does not establish a reliable native-deletion delivery contract for this case.

## Behavior

- Restrict reconciliation to Facebook-connected Instagram account **109**, in
  workspace **12**. Other accounts and direct messages retain existing behavior.
- Every five minutes, inspect up to ten existing comment conversations, rotating
  through closed and open threads. Use a lock, a total 100-request ceiling and
  time limits. Stop the batch on an unsuccessful/incomplete scan, including
  throttling. The next rotation can retry the thread.
- Read all root-comment pages and every reply edge, including additional reply
  pages. Reconstruct requests using known endpoints and opaque cursors; never
  follow or log a Graph paging URL. Reject malformed/error responses, duplicate
  IDs, repeated/missing cursors and capped/incomplete results.
- Two complete observations of absence, at least ten minutes apart, mark a
  saved comment **unavailable**. At the current 43 Instagram conversations,
  successful small threads complete a rotation in about 30 minutes; fallback
  confirmation can therefore take about an hour. Errors or large capped threads
  delay confirmation rather than risking history.
- Keep the message's body, author, parent, timestamps and unrelated metadata.
  Keep the conversation, status, unread state and activity timestamp. The inbox
  shows “No longer available on Instagram. Saved here for your records.” Reply
  and like controls are hidden, and the server rejects those actions too.
- The reply composer chooses an available incoming comment and submits that
  exact message ID. If all saved comments are unavailable, it keeps the history
  visible and hides the composer. A selected comment becoming unavailable also
  drops out of the reply target.
- A complete scan or positive ingestion that finds the comment again removes
  only this patch's marker, retaining unrelated metadata such as liked state.
  The existing update broadcast refreshes an open thread without a reload.
- A failed scan resets pending evidence. It never turns API denial, expired
  credentials, an inaccessible post, timeout, throttling or a partial result
  into deletion evidence. New/concurrently changed messages are skipped.

The implementation uses one namespaced key in the existing message `data` JSON,
plus disposable cache keys for the rotation cursor and locks. It changes no
account token, permissions, post schedule, imported media, Annex data, story
capture implementation or collaborative-media collector.

## Applying and checking

The applicator, custom support classes and tests are versioned in this directory.
The November 16 update reconciles them against the effective production package.
The frozen gate now fingerprints the patched source and requires the snapshot,
broadcast and reply-target checks. The restored-database test remains a separate
required rehearsal. Do not recreate this patch from the Lite package.

Export the exact effective licensed Pro package, including all current vendor
bind overrides, into a private local staging directory. Keep the application's
current files, Compose files, mounted startup guard and image baseline alongside
it. Never substitute the repository's Lite package.

```bash
python3 ops/comment-sync/apply.py --source /PRIVATE/STAGED/PRO \
  --evidence /PRIVATE/AUDIT/patch
php ops/comment-sync/tests/SnapshotTest.php
```

The applicator requires each original context exactly once, refuses to replace
existing support classes and records before/after hashes and a vendor patch.
It intentionally stops on reapplication or changed upstream context. Rebuild
the complete frontend and bake it with the changed source into a new frozen
image. Existing vendor mounts must point to their reviewed candidate versions
during rehearsal and, only after approval, promotion.

Run `SnapshotTest.php` against the **baked** support classes using
`SYNC_SOURCE_DIR=/var/www/html/vendor/inovector/mixpost-pro-team/src/Support`.
Run `DatabaseTest.php` in a disposable app on an internal-only network, against
a separately restored database whose host starts with `mixpost-local-rehearsal-`.
Use fake provider HTTP, mail and broadcasting; start no publishing workers or
scheduler. Require a successful `RESULT` line as well as process success.
Run `BroadcastTest.mjs` and `ReplyTargetTest.mjs` with
`MIXPOST_FRONTEND_SOURCE_DIR` pointing at the exact exported candidate package.
They verify open-thread updates and composer targeting, including a selected
comment becoming unavailable and an unavailable-only history. Compare all
Mixpost, story-capture and user table fingerprints before and after fixtures
using `tests/Fingerprint.php`.

Use the current production-matching release validator and startup guard. The
merged story-capture release expanded their contract during preparation. Preserve
its 28 story checks
as well as the other required suites. Isolated gate evidence is **not** a
production promotion check: that check must bind to the then-current production
image, effective Compose configuration, mount inventory and checkpoint.

## Future deployment and rollback

Before deployment, re-check concurrent production changes, preserve the new
story-capture image/app files and any later work, and review the exact candidate
diff. Take fresh full/affected-row and configuration/gate checkpoints. Re-run the
current frozen validator on the exact image in its deployment environment,
retaining every required suite. Install only after Dan's explicit OK. Preserve
both Compose files and recreate only the app; retain MySQL, Redis, networks,
credentials, volumes and the mounted startup guard.

Start with a read-only reconciliation preview against current account 109,
review any unavailable candidates, then verify the authenticated inbox.
A live native-comment deletion/reappearance test requires separate approval.
Live webhook delivery and production storage are not proved by HTTP fakes.

Rollback needs Dan's OK and a new checkpoint. Restore the matching prior image,
overrides and gate together. This patch needs no schema rollback or whole-DB
replacement. Review and clear only the namespaced availability metadata if
desired, retaining every other message field and metadata key; flush only its
rotation/lock cache keys. Do not restore an old database over subsequent user
activity. Preserve any intervening story data and other work.
