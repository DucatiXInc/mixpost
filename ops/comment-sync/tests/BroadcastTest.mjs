import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

const sourceDir = process.env.MIXPOST_FRONTEND_SOURCE_DIR
assert.ok(sourceDir, 'Set the exact exported candidate package path')
const source = fs.readFileSync(`${sourceDir}/resources/js/Composables/useInboxBroadcast.js`, 'utf8')
const handlers = new Map()
const context = {
  useBroadcast: () => ({
    connectBroadcastPrivate: () => ({ listen: (event, handler) => handlers.set(event, handler) }),
    leaveBroadcastChannel: () => {}
  }),
  onMounted: callback => callback(),
  onUnmounted: () => {}
}
vm.runInNewContext(
  source.replace(/^import .*\n/gm, '').replace('export default useInboxBroadcast', '') +
    '\nglobalThis.subject = useInboxBroadcast;',
  context
)
const message = { id: 'saved-message', body: 'original saved text', data: { liked: true }, author: 'retained' }
const active = { value: { id: 'thread', messages: [message], unread: false } }
const list = { value: [{ id: 'another-thread' }, { id: 'thread', last_message: 'original saved text' }] }
context.subject({ workspaceId: 'local', list, activeConversation: active, counts: { value: {} }, filter: () => ({}) })
const updated = handlers.get('Inbox.InboxMessageUpdated')
assert.equal(typeof updated, 'function')
updated({ conversation: { id: 'thread', last_message: 'original saved text' }, message: {
  id: 'saved-message', body: 'original saved text', data: { liked: true, peachy_instagram_availability: { state: 'unavailable' } }
} })
assert.equal(message.data.peachy_instagram_availability.state, 'unavailable')
assert.equal(message.body, 'original saved text')
assert.equal(message.author, 'retained')
assert.equal(active.value.messages.length, 1)
assert.equal(active.value.unread, false)
assert.equal(list.value[1].id, 'thread')
console.log('PASS availability broadcast updates metadata without deleting history or reordering threads')
updated({ conversation: { id: 'thread', last_message: 'original saved text' }, message: {
  id: 'saved-message', body: 'original saved text', data: { liked: true }
} })
assert.equal(message.data.peachy_instagram_availability, undefined)
assert.equal(message.data.liked, true)
assert.equal(active.value.messages.length, 1)
console.log('PASS recovery broadcast clears only availability metadata in the open thread')
console.log('RESULT 2 checks passed')
