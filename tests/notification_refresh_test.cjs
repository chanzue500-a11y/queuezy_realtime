const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('dist/includes/header.php', 'utf8');
const script = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .find((match) => match[1].includes('const updateAdminNotifications'))[1]
  .replace(/<\?=[\s\S]*?\?>/g, 'null');

class Element {
  constructor(tagName = 'div') {
    this.tagName = tagName;
    this.children = [];
    this.dataset = {};
    this.textContent = '';
    this.events = {};
    this.classes = new Set();
    this.classList = {
      contains: (name) => this.classes.has(name),
      toggle: (name) => {
        if (this.classes.has(name)) { this.classes.delete(name); return false; }
        this.classes.add(name); return true;
      },
      remove: (name) => this.classes.delete(name),
    };
  }
  appendChild(child) { child.parent = this; this.children.push(child); }
  remove() { this.parent.children = this.parent.children.filter((child) => child !== this); }
  querySelector(tagName) { return this.children.find((child) => child.tagName === tagName); }
  addEventListener(name, callback) { this.events[name] = callback; }
  setAttribute() {}
}

(async () => {
  const elements = Object.fromEntries(['adminNotificationButton', 'adminNotificationPanel', 'adminSidebarNotificationLink', 'adminNotificationContent']
    .map((id) => [id, new Element()]));
  const field = new Element();
  field.dataset.notificationField = 'queue_message';
  const scheduled = [];
  const stored = new Map();
  let fail = false;
  let snapshot = { ok: true, key: 'first', has_activity: true, message: '2 waiting, 0 called, 1 being served.', queue_message: '2 waiting, 0 called, 1 being served.' };
  const document = {
    getElementById: (id) => elements[id] || elements.adminNotificationButton.children.find((child) => child.id === id),
    createElement: (tag) => new Element(tag),
    querySelectorAll: () => [field],
    addEventListener() {},
  };
  vm.runInNewContext(script, {
    document,
    localStorage: { getItem: (key) => stored.get(key), setItem: (key, value) => stored.set(key, value) },
    window: { setTimeout: (callback) => scheduled.push(callback) },
    fetch: async () => ({ ok: !fail, json: async () => snapshot }),
  });
  const settle = () => new Promise((resolve) => setImmediate(resolve));
  await settle();
  assert.equal(elements.adminNotificationContent.textContent, snapshot.message);
  assert.equal(field.textContent, snapshot.queue_message);
  assert.equal(elements.adminSidebarNotificationLink.children.length, 1, 'New activity should create sidebar badge');
  assert.equal(elements.adminNotificationButton.children.length, 1, 'New activity should create bell dot');
  assert.equal(scheduled.length, 1, 'Refresh should schedule one follow-up');

  elements.adminNotificationButton.events.click({ stopPropagation() {} });
  assert.equal(elements.adminSidebarNotificationLink.children.length, 0, 'Opening notification marks current state read');
  elements.adminNotificationButton.events.click({ stopPropagation() {} });
  await scheduled.shift()();
  assert.equal(elements.adminSidebarNotificationLink.children.length, 0, 'Read state should survive identical refresh');

  snapshot = { ...snapshot, key: 'second', message: '0 waiting, 0 called, 2 being served.' };
  await scheduled.shift()();
  assert.equal(elements.adminSidebarNotificationLink.children.length, 1, 'New state should restore unread badge');
  assert.equal(elements.adminNotificationContent.textContent, snapshot.message, 'Use API role-specific message');

  fail = true;
  await scheduled.shift()();
  assert.match(elements.adminNotificationContent.textContent, /temporarily unavailable/, 'Outage should not present old status as current');
  assert.match(field.textContent, /unavailable/);
  fail = false;
  snapshot = { ...snapshot, key: 'empty', has_activity: false, message: 'No active queues or new updates today.' };
  await scheduled.shift()();
  assert.equal(elements.adminNotificationContent.textContent, snapshot.message);
  assert.equal(elements.adminSidebarNotificationLink.children.length, 0, 'No activity should clear badge');
  assert.equal(elements.adminNotificationButton.children.length, 0, 'No activity should clear dot');
  assert.equal(scheduled.length, 1, 'Refresh must continue after failures without overlapping requests');
  console.log('PASS: live refresh messages, read state, new badges, empty state, and outage recovery.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
