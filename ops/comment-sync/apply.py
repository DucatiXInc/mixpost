#!/usr/bin/env python3
"""Apply the TTB-only reconciliation patch to an exported, effective frozen Pro source."""
import argparse
import difflib
import hashlib
import json
from pathlib import Path
import shutil

HERE = Path(__file__).resolve().parent

def apply(source, evidence):
    changes = []
    def edit(relative, old, new):
        path = source / relative
        before = path.read_text()
        if before.count(old) != 1:
            raise RuntimeError('Review changed upstream context: ' + relative)
        after = before.replace(old, new, 1)
        path.write_text(after)
        changes.append({'path': relative, 'before_sha256': hashlib.sha256(before.encode()).hexdigest(),
                        'after_sha256': hashlib.sha256(after.encode()).hexdigest(),
                        'patch': ''.join(difflib.unified_diff(before.splitlines(True), after.splitlines(True),
                                                            fromfile='a/' + relative, tofile='b/' + relative))})
    edit('src/SocialProviders/Meta/Concerns/InstagramManagesCommentImport.php',
         '    public function fetchComments(',
         '''    public function fetchCommentSnapshot(string $providerPostId, int $budget = 100): array
    {
        return (new \\Inovector\\Mixpost\\Support\\InstagramCommentSnapshot(
            fn (string $id, string $edge, array $params) => $this->http()::withToken($this->getAccessToken()['access_token'])
                ->connectTimeout(5)->timeout(10)->get("{$this->resolveApiDomain()}/$id/$edge", $params),
            $budget
        ))->fetch($providerPostId);
    }

    public function fetchComments(''')
    edit('src/Schedule.php', "        $schedule->command('mixpost:prune-temporary-directory')->hourly();",
         """        $schedule->call(fn () => app(\\Inovector\\Mixpost\\Support\\TtbInstagramCommentSync::class)->run())
            ->name('peachy:ttb-instagram-comment-sync')->everyFiveMinutes()->withoutOverlapping(15);

        $schedule->command('mixpost:prune-temporary-directory')->hourly();""")
    edit('src/Jobs/Inbox/ProcessInboundWebhookJob.php',
         '                app(DeleteInboxMessage::class)($account->id, (string) $messageId);',
         '''                if (! app(\\Inovector\\Mixpost\\Support\\TtbInstagramCommentSync::class)->preserveDeletion($account, (string) $messageId)) {
                    app(DeleteInboxMessage::class)($account->id, (string) $messageId);
                }''')
    edit('src/Actions/Inbox/IngestInboundMessage.php',
         "        $direction = $message['direction'] ?? MessageDirection::INBOUND;\n\n        return Message::updateOrCreate(",
         """        $direction = $message['direction'] ?? MessageDirection::INBOUND;

        // A positive observation restores availability, retaining unrelated cached metadata.
        if ($accountId === 109 && $provider === 'instagram' && (int) \\Inovector\\Mixpost\\Models\\Account::withoutWorkspace()->whereKey($accountId)->value('workspace_id') === 12) {
            $existing = Message::withoutWorkspace()->where('account_id', $accountId)
                ->where('provider_message_id', $message['provider_message_id'])->first();
            $data = array_replace($existing?->data ?? [], $message['data'] ?? []);
            unset($data[\\Inovector\\Mixpost\\Support\\InstagramCommentAvailability::KEY]);
            $message['data'] = $data ?: null;
        }

        return Message::updateOrCreate(""")
    edit('src/Actions/Inbox/IngestInboundMessage.php',
         "        if ($message->wasChanged('body')) {",
         "        if ($message->wasChanged('body') || ($message->account_id === 109 && $message->provider === 'instagram' && $message->wasChanged('data'))) {")
    for action, parameter, exception in [('SendReply', 'inReplyTo', 'InboxReplyException'), ('LikeMessage', 'message', 'InboxLikeException')]:
        edit('src/Actions/Inbox/' + action + '.php', '        $account = $conversation->account;',
             f'''        if (($${parameter}->data[\\Inovector\\Mixpost\\Support\\InstagramCommentAvailability::KEY]['state'] ?? null) === 'unavailable') {{
            throw new {exception}('This comment is no longer available on Instagram.');
        }}

        $account = $conversation->account;'''.replace('$$', '$'))
    edit('resources/js/Components/Inbox/MessageCard.vue',
         "const isOutbound = computed(() => props.message.direction === 'OUTBOUND')",
         """const isOutbound = computed(() => props.message.direction === 'OUTBOUND')
const unavailable = computed(
  () => props.message.data?.peachy_instagram_availability?.state === 'unavailable'
)""")
    edit('resources/js/Components/Inbox/MessageCard.vue',
         '      <div v-if="!isOutbound" class="mt-s flex items-center gap-m">',
         '''      <p v-if="unavailable" class="mt-xs text-xs text-fg-neutral-secondary" role="status">
        No longer available on Instagram. Saved here for your records.
      </p>
      <div v-if="!isOutbound && !unavailable" class="mt-s flex items-center gap-m">''')
    edit('resources/js/Composables/useInboxBroadcast.js',
         '      if (existing) existing.body = message.body',
         '''      if (existing) {
        existing.body = message.body
        existing.data = message.data
      }''')
    edit('resources/js/Composables/useReplyTarget.js',
         '  const replyTarget = ref(null)',
         '''  const replyTarget = ref(null)
  const canReply = message => Boolean(message && message.direction === 'INBOUND' &&
    message.data?.peachy_instagram_availability?.state !== 'unavailable')''')
    edit('resources/js/Composables/useReplyTarget.js',
         ".find(message => message.direction === 'INBOUND')", '.find(canReply)')
    edit('resources/js/Composables/useReplyTarget.js',
         'const targetMessage = computed(() => replyTarget.value || latestInbound.value)',
         'const targetMessage = computed(() => canReply(replyTarget.value) ? replyTarget.value : latestInbound.value)')
    edit('resources/js/Composables/useReplyTarget.js',
         'const hasExplicitTarget = computed(() => replyTarget.value !== null)',
         'const hasExplicitTarget = computed(() => canReply(replyTarget.value))')
    edit('resources/js/Composables/useReplyTarget.js',
         ".filter(message => message.direction === 'INBOUND')", '.filter(canReply)')
    edit('resources/js/Composables/useReplyTarget.js',
         '    replyTarget.value = message', '    replyTarget.value = canReply(message) ? message : null')
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         "import { nextTick, ref, toRef, watch } from 'vue'", "import { computed, nextTick, ref, toRef, watch } from 'vue'")
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         '  replyTarget,\n', '')
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         "} = useReplyTarget(toRef(props, 'messages'))",
         """} = useReplyTarget(toRef(props, 'messages'))

const showEditor = computed(() => Boolean(targetMessage.value) || !props.messages.some(
  message => message.data?.peachy_instagram_availability?.state === 'unavailable'
))""")
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         '  if (!value) {', '  if (!value || !targetMessage.value) {')
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         'messageId: replyTarget.value?.id || null', 'messageId: targetMessage.value.id')
    edit('resources/js/Components/Inbox/ConversationComposer.vue',
         '    <ReplyEditor\n', '''    <p v-if="!showEditor" class="text-sm text-fg-neutral-secondary" role="status">
      No available comments to reply to. Saved history is still shown above.
    </p>
    <ReplyEditor
      v-if="showEditor"
''')
    for file in (HERE / 'src').glob('*.php'):
        target = source / 'src/Support' / file.name
        if target.exists():
            raise RuntimeError('Refusing to replace existing support class: ' + file.name)
        shutil.copy2(file, target)
        changes.append({'path': 'src/Support/' + file.name, 'before_sha256': None,
                        'after_sha256': hashlib.sha256(target.read_bytes()).hexdigest()})
    evidence.mkdir(parents=True, exist_ok=True)
    (evidence / 'patch-manifest.json').write_text(json.dumps(changes, indent=2) + '\n')
    (evidence / 'effective-source.patch').write_text(''.join(c.get('patch', '') for c in changes))

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    args = parser.parse_args()
    apply(args.source.resolve(), args.evidence.resolve())
