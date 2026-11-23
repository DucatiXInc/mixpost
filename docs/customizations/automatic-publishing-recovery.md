# Automatic Instagram publishing recovery

Deployed and accepted on November 18, 2026, from [PR #21](https://github.com/DucatiXInc/mixpost/pull/21). See the [current production record](../current-production-release.md) for exact image, verification and rollback evidence.

Instagram and Instagram Login publishing recover from explicit, pre-container
rejections. Recovery remains within the existing account publishing job; the
destination stays publishing while it waits. Other destinations are not retried.

* Code 110/subcode 2207018 or code 210/subcode 2207066 may remove only the exact
  requested usernames named in Meta's user-facing response, including when Meta
  returns multiple usernames in a comma-separated list. Generic errors and lists
  containing an unknown username never authorize removal. Changes live in an
  account-specific version. Mixpost preserves caption mentions, media, the
  original version, other invites and other destinations. A later named
  rejection can remove another invite.
* Code 9004/subcode 2207052 or -2/subcode 2207003 from container creation may recover
  the rejected cloud asset. The URL must match a selected media original or
  conversion, in the same workspace, on a configured S3 disk. Recovery uses the
  authenticated storage adapter rather than downloading an arbitrary error URL.
  It streams bounded bytes, checks recorded size and available checksum, persists
  a local public copy and private receipt, verifies its entire public SHA-256,
  then promotes only that asset's storage location. Source objects are retained.
* Storage waits use durable state and increasing delays, capped at 30 minutes and
  12 source reads. Restarting a worker preserves the deadline. Failed verification
  cannot promote media or authorize publication. A recovered local copy and receipt
  permit resumption after a crash. Five GiB of free storage remains reserved.
* There is no automatic replay after an accepted container, a lost response,
  `media_publish` error, live post ID or live publication marker. Unknown failures
  retain normal failure handling. The existing container and upload safeguards
  remain required.

The collaborator alert customization queues one email when Instagram publishes
after an automatic collaborator removal. It names the removed handles, identifies
the Instagram destination and links to the post. **To** is the user from the
post's latest `SCHEDULED` activity and **Cc** is `dan@ducatix.com`. Editing the
schedule time alone does not change the recipient. At delivery, Mixpost checks
that the destination still matches the published recovery and that the recorded
scheduler is still a member of that workspace. The message goes directly to Dan
if he scheduled it or if the scheduler has no recorded user, no email address,
or no current workspace membership. The recorded destination ID prevents
repeated finalization from sending the same notice again. Successful storage-only
recovery is silent.

Unrecovered terminal failures continue to email `socials@ducatix.com` with the
recovery details and final explanation. Mail delivery rechecks the current post
and suppresses stale failure notices during active retries and after recovery.
Recovery history remains in the post.

## Update contract

Preserve the registered `InstagramPublishingRecovery.php`,
`PublishingMediaFallback.php`, `PostPublishingRecoveryNotification.php` and their
integration in `AccountPublishPost.php`, `ManagesInstagramResources.php` and
`FinalizePostPublishing.php`. New helpers are baked into the frozen image, not
injected into a running worker. Preserve public media, private receipts, recovery
state in version options, existing queues, mail configuration and both Compose
files. Do not delete recovered media on worker restart or storage reconciliation.
For recipient routing, reapply or rebase the registered
`FinalizePostPublishing.php` bind override and bake the registered
`PostPublishingRecoveryNotification.php` into every candidate. Preserve the
post scheduling activity that records the actor. Drain any queued success
notices from the older workspace-wide implementation before switching workers.

Every exact candidate must pass the frozen `instagram-publishing-recovery` suite
and source fingerprints. The restored-database rehearsal must exercise sequential
collaborator removal, account/workspace isolation, durable storage retry,
verification failures, promotion, restart recovery, terminal publication guards,
silent storage-only success, collaborator notice routing, email rendering and
deduplication, and stale queued-mail suppression. Use the actual Laravel mail
channel with the in-memory transport to verify the `To` and `Cc` headers for the
recorded scheduler, Dan, missing history, automation and former members. Provider
HTTP is faked in rehearsal.

Upstream code can replace this implementation only after passing the same
failure/recovery contract. Release notes alone do not supersede it. Follow the
frozen release playbook, register check, rollback and checkpoint acceptance wrapper.
Rollback retains recovered public files and existing recovery evidence; do not
restore a stale database over later real posts.
