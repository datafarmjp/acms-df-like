'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/df-like.js'), 'utf8');

class Button {
  constructor(id) {
    this.attributes = {
      'data-object-type': 'entry',
      'data-object-id': String(id),
      'data-entry-id': String(id),
      'data-blog-id': '1',
      'aria-pressed': 'false',
    };
    this.disabled = false;
    this.label = {textContent: 'いいね'};
    this.count = {textContent: '0'};
    this.classes = new Set();
    this.classList = {
      toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name),
    };
  }

  getAttribute(name) { return this.attributes[name] || null; }
  setAttribute(name, value) { this.attributes[name] = value; }
  removeAttribute(name) { delete this.attributes[name]; }
  matches(selector) { return selector === '.js-df-like-button'; }
  closest(selector) { return this.matches(selector) ? this : null; }
  querySelectorAll() { return []; }
  querySelector(selector) {
    return selector === '.df-like-button__label' ? this.label : this.count;
  }
}

function createPage(initialButtons, readyState = 'complete') {
  const buttons = initialButtons.slice();
  const listeners = new Map();
  const requests = [];
  const document = {
    readyState,
    querySelector: () => null,
    querySelectorAll(selector) {
      const objectId = selector.match(/\[data-object-id="([^"]+)"\]/);
      return objectId ? buttons.filter(button => button.getAttribute('data-object-id') === objectId[1]) : buttons;
    },
    addEventListener(name, callback) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(callback);
    },
  };
  const window = {location: {href: 'https://example.test/faq/'}, console: {warn() {}}};
  const context = vm.createContext({
    window,
    document,
    console: window.console,
    FormData: class {
      constructor() { this.fields = new Map(); }
      append(name, value) { this.fields.set(name, value); }
      get(name) { return this.fields.get(name); }
    },
    fetch(url, options) {
      return new Promise((resolve, reject) => {
        requests.push({url, options, resolve, reject});
      });
    },
  });
  const runScript = () => vm.runInContext(source, context);
  const dispatch = (name, event = {}) => {
    (listeners.get(name) || []).forEach(callback => callback(event));
  };
  runScript();
  return {
    buttons, requests, listeners, window, runScript, dispatch,
    fragment(items) { return {querySelectorAll: () => items}; },
    click(button) {
      let prevented = false;
      dispatch('click', {target: button, preventDefault() { prevented = true; }});
      assert(prevented, 'Like clicks prevent default navigation');
    },
  };
}

function respond(request, body) {
  request.resolve({ok: true, text: () => Promise.resolve(JSON.stringify(body))});
}

const flush = () => new Promise(resolve => setImmediate(resolve));

function assertRequest(request, id, action) {
  assert.equal(request.options.body.get('entry_id'), String(id));
  assert.equal(request.options.body.get(action), '1');
  assert.equal(request.options.method, 'POST');
}

async function testInitialAndDynamicButtons() {
  const first = new Button(1);
  const duplicate = new Button(1);
  const page = createPage([first, duplicate]);
  assert.equal(page.requests.length, 1, 'Duplicate entry buttons share an initial status request');
  assertRequest(page.requests[0], 1, 'ACMS_POST_DFLikeStatus');

  // Script tags inserted by htmx can execute before or after its swap event.
  page.runScript();
  page.dispatch('htmx:afterSwap', {detail: {target: page.fragment([first, duplicate])}});
  assert.equal(page.requests.length, 1, 'Replay and swap do not duplicate a pending request');
  assert.equal(page.listeners.get('click').length, 1, 'Replayed assets install only one click listener');
  assert.equal(page.listeners.get('htmx:afterSwap').length, 1);
  respond(page.requests[0], {status: 'success', liked: true, count: 3});
  await flush();
  assert.equal(first.count.textContent, '3');
  assert.equal(duplicate.label.textContent, 'いいね済み');

  const second = new Button(2);
  const third = new Button(3);
  page.buttons.push(second, third);
  page.dispatch('htmx:afterSwap', {detail: {target: page.fragment([second])}});
  assert.equal(page.requests.length, 2, 'Swap refresh is scoped to its target');
  assertRequest(page.requests[1], 2, 'ACMS_POST_DFLikeStatus');
  page.runScript();
  assert.equal(page.requests.length, 3, 'Script replay discovers other new buttons');
  assertRequest(page.requests[2], 3, 'ACMS_POST_DFLikeStatus');
  page.dispatch('htmx:afterSwap', {target: third});
  assert.equal(page.requests.length, 3, 'A like button can itself be the swap root');

  // A second instance of the same entry arriving while its status is pending joins it.
  const secondDuplicate = new Button(2);
  page.buttons.push(secondDuplicate);
  page.dispatch('htmx:afterSwap', {target: secondDuplicate});
  assert.equal(page.requests.length, 3);
  respond(page.requests[1], {status: 'success', liked: false, count: 7});
  respond(page.requests[2], {status: 'success', liked: true, count: 9});
  await flush();
  assert.equal(second.count.textContent, '7');
  assert.equal(secondDuplicate.count.textContent, '7');
  assert.equal(third.count.textContent, '9');
  page.runScript();
  assert.equal(page.requests.length, 3, 'Already synchronized nodes do not fetch again');

  const replacement = new Button(2);
  page.buttons.splice(page.buttons.indexOf(second), 1, replacement);
  page.dispatch('htmx:afterSwap', {target: replacement});
  assert.equal(page.requests.length, 4, 'Replacing cached HTML refreshes the new node');
  respond(page.requests[3], {status: 'success', liked: true, count: 8});
  await flush();
  assert.equal(replacement.label.textContent, 'いいね済み');
  assert.equal(secondDuplicate.count.textContent, '8');
}

async function testToggleWinsOverStaleStatus() {
  const button = new Button(10);
  const page = createPage([button]);
  page.click(button);
  assert.equal(page.requests.length, 2);
  assertRequest(page.requests[1], 10, 'ACMS_POST_DFLikeToggle');
  assert(button.disabled);

  const insertedDuringToggle = new Button(10);
  page.buttons.push(insertedDuringToggle);
  page.dispatch('htmx:afterSwap', {target: insertedDuringToggle});
  assert(insertedDuringToggle.disabled, 'New copies cannot start a concurrent toggle');
  page.click(insertedDuringToggle);
  assert.equal(page.requests.length, 2, 'Status and extra toggles wait for the active toggle');
  respond(page.requests[1], {status: 'success', action: 'like', liked: true, count: 11});
  await flush();
  assert.equal(button.count.textContent, '11');
  assert.equal(insertedDuringToggle.getAttribute('aria-pressed'), 'true');
  assert.equal(insertedDuringToggle.disabled, false, 'Copies inserted during a toggle are re-enabled');

  respond(page.requests[0], {status: 'success', liked: false, count: 10});
  await flush();
  assert.equal(button.count.textContent, '11', 'Late status cannot overwrite a newer toggle');
  assert.equal(button.getAttribute('aria-pressed'), 'true');
  page.runScript();
  assert.equal(page.requests.length, 2, 'The successful toggle also synchronizes inserted copies');

  const other = new Button(20);
  const otherPage = createPage([other]);
  otherPage.click(other);
  respond(otherPage.requests[0], {status: 'success', liked: true, count: 30});
  await flush();
  assert.equal(other.count.textContent, '0', 'Status cannot update UI while a toggle is pending');
  respond(otherPage.requests[1], {status: 'success', action: 'like', liked: true, count: 31});
  await flush();
  assert.equal(other.count.textContent, '31');

  // A fragment removed while posting may later be restored from a DOM cache.
  const removed = new Button(21);
  const removedPage = createPage([removed]);
  removedPage.click(removed);
  removedPage.buttons.length = 0;
  respond(removedPage.requests[1], {status: 'success', action: 'like', liked: true, count: 1});
  await flush();
  assert.equal(removed.disabled, false, 'Detached copies are not left permanently disabled');
}

async function testRetryAndDomReady() {
  const button = new Button(30);
  const page = createPage([button], 'loading');
  assert.equal(page.requests.length, 0);
  page.dispatch('DOMContentLoaded');
  assert.equal(page.requests.length, 1);
  respond(page.requests[0], {status: 'failure', message: 'Temporary status failure'});
  await flush();
  page.dispatch('htmx:afterSwap', {target: button});
  assert.equal(page.requests.length, 2, 'A failed status can be retried on the next refresh');
  respond(page.requests[1], {status: 'success', liked: false, count: 5});
  await flush();
  assert.equal(button.count.textContent, '5');
  page.window.dfLikeSyncButtons();
  assert.equal(page.requests.length, 2, 'The public refresh defaults to document and respects synchronized nodes');
}

(async () => {
  await testInitialAndDynamicButtons();
  await testToggleWinsOverStaleStatus();
  await testRetryAndDomReady();
  process.stdout.write('PASS: initial/dynamic button synchronization, script replay, scoped refresh, retries, and stale-status protection\n');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
