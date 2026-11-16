import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
const root = process.env.MIXPOST_FRONTEND_SOURCE_DIR
assert.ok(root, 'Set the exact candidate source path')
const source = fs.readFileSync(`${root}/resources/js/Composables/useReplyTarget.js`, 'utf8')
const context = {
  ref: value => ({ value }),
  computed: getter => Object.defineProperty({}, 'value', { get: getter })
}
vm.runInNewContext(source.replace(/^import .*\n/gm, '').replace('export default function', 'function') +
  '\nglobalThis.subject = useReplyTarget;', context)
const older = { id: 'older', direction: 'INBOUND', body: 'saved older comment' }
const latest = { id: 'latest', direction: 'INBOUND', body: 'latest comment' }
const messages = { value: [older, latest] }
const target = context.subject(messages)
assert.equal(target.targetMessage.value.id, 'latest')
console.log('PASS available comments retain the normal latest-comment target')
target.setTarget(latest)
latest.data = { peachy_instagram_availability: { state: 'unavailable' } }
assert.equal(target.targetMessage.value.id, 'older')
assert.equal(target.hasExplicitTarget.value, false)
assert.equal(target.multipleInbound.value, false)
console.log('PASS a newly unavailable explicit/default target falls back to an available comment')
target.setTarget(latest)
assert.equal(target.targetMessage.value.id, 'older')
older.data = { peachy_instagram_availability: { state: 'unavailable' } }
assert.equal(target.targetMessage.value, null)
console.log('PASS an all-unavailable history supplies no reply target')
latest.data = { liked: true }
assert.equal(target.targetMessage.value.id, 'latest')
assert.equal(messages.value.length, 2)
assert.equal(older.body, 'saved older comment')
console.log('PASS reappearance restores a usable target while retaining history')
const composer = fs.readFileSync(`${root}/resources/js/Components/Inbox/ConversationComposer.vue`, 'utf8')
const emitted = []
const composerContext = {
  ...context,
  defineProps: () => ({ messages: messages.value, conversationId: 'thread', sending: false }),
  defineEmits: () => (...event) => emitted.push(event),
  defineExpose: () => {},
  useReplyTarget: context.subject,
  submitShortcutLabel: () => 'Ctrl Enter',
  toRef: (object, key) => Object.defineProperty({}, 'value', { get: () => object[key] }),
  watch: () => {},
  nextTick: callback => callback()
}
vm.runInNewContext(composer.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*\n/gm, '') +
  '\nglobalThis.composer = { text, submit, showEditor };', composerContext)
composerContext.composer.text.value = 'local test, never sent'
composerContext.composer.submit('reply')
assert.equal(emitted[0][1].messageId, 'latest')
latest.data = { peachy_instagram_availability: { state: 'unavailable' } }
assert.equal(composerContext.composer.showEditor.value, false)
composerContext.composer.text.value = 'must not be emitted'
composerContext.composer.submit('reply')
assert.equal(emitted.length, 1)
console.log('PASS composer emits the available message ID and blocks an unavailable-only thread')
console.log('RESULT 5 checks passed')
