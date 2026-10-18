const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/contact.js'), 'utf8');

function harness(postResponse) {
  const fields = Object.fromEntries(['name', 'email', 'company', 'parts', 'csrfToken'].map(name => [name, {
    value: name === 'email' ? 'visitor@example.com' : 'Test detail',
    validity: '', setCustomValidity(value) { this.validity = value; }
  }]));
  const events = {};
  const form = { action: 'https://zapeks.com/inquiry.php', resets: 0, attributes: {},
    elements: { namedItem: name => fields[name] }, addEventListener: (name, handler) => { events[name] = handler; },
    reportValidity: () => true, reset() { this.resets++; },
    setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; }
  };
  const status = { dataset: {}, textContent: '', focus() {} };
  const button = { disabled: false, textContent: 'Send inquiry' };
  const elements = { 'inquiry-form': form, status, 'send-inquiry': button, 'csrf-token': fields.csrfToken };
  const requests = [];
  class TestFormData extends Map {
    constructor() { super(Object.entries(fields).map(([name, field]) => [name, field.value])); }
  }
  const context = { document: { getElementById: id => elements[id] }, location: { protocol: 'https:' },
    FormData: TestFormData, URLSearchParams, Error, TypeError, SyntaxError,
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (options.method !== 'POST') return { ok: true, json: async () => ({ csrfToken: 'test-token' }) };
      return postResponse(options);
    }
  };
  vm.runInNewContext(source, context);
  return { form, status, button, fields, requests, events, submit: () => events.submit({ preventDefault() {} }) };
}

test('submits directly and clears the form only after acceptance', async () => {
  const page = harness(async () => ({ ok: true, status: 200, json: async () => ({ ok: true, message: 'Submitted.', reference: 'ZAP-TEST' }) }));
  await page.submit();
  const post = page.requests.find(request => request.options.method === 'POST');
  assert.equal(post.url, 'https://zapeks.com/inquiry.php');
  assert.equal(post.options.body.get('email'), 'visitor@example.com');
  assert.equal(post.options.body.get('csrfToken'), 'test-token');
  assert.equal(page.form.resets, 1);
  assert.equal(page.status.dataset.state, 'success');
  assert.match(page.status.textContent, /ZAP-TEST/);
  assert.equal(page.button.disabled, false);
  assert.equal(page.form.attributes['aria-busy'], undefined);
});

test('server rejection keeps fields and provides validation feedback', async () => {
  const page = harness(async () => ({ ok: false, status: 422, json: async () => ({ ok: false, message: 'Check your email.', errors: { email: 'Invalid email.' } }) }));
  await page.submit();
  assert.equal(page.form.resets, 0);
  assert.equal(page.status.dataset.state, 'error');
  assert.equal(page.status.textContent, 'Check your email.');
  assert.equal(page.fields.email.validity, 'Invalid email.');
  page.events.input({ target: page.fields.email });
  assert.equal(page.fields.email.validity, '');
  assert.equal(page.button.disabled, false);
});

test('a second click cannot submit a second message while sending', async () => {
  let finish;
  const response = new Promise(resolve => { finish = resolve; });
  const page = harness(() => response);
  const first = page.submit();
  await page.submit();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(page.requests.filter(request => request.options.method === 'POST').length, 1);
  assert.equal(page.button.disabled, true);
  finish({ ok: true, status: 200, json: async () => ({ ok: true, message: 'Submitted.' }) });
  await first;
  assert.equal(page.button.disabled, false);
});

test('mail service failures keep the inquiry available to retry', async () => {
  const page = harness(async () => ({ ok: false, status: 503, json: async () => ({ ok: false, message: 'Please try later.' }) }));
  await page.submit();
  assert.equal(page.form.resets, 0);
  assert.equal(page.status.dataset.state, 'error');
  assert.equal(page.fields.email.value, 'visitor@example.com');
  assert.equal(page.button.textContent, 'Send inquiry');
});
