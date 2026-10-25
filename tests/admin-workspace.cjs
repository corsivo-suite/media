const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM(`<div data-corsivo-workspace><form>
<input name="_wp_http_referer" value="/wp-admin/options.php">
<nav><a href="#first" data-corsivo-section-link>First</a><a href="#second" data-corsivo-section-link>Second</a></nav>
<section id="panel-first" data-corsivo-section="first"><input name="first" value="initial"></section>
<section id="panel-second" data-corsivo-section="second"><input name="second" value="other" required></section>
</form></div>`, { url: 'https://example.test/wp-admin/options.php#first', runScripts: 'outside-only' });
const { window } = dom;
const { document } = window;
window.eval(fs.readFileSync(path.join(__dirname, '../assets/admin-workspace.js'), 'utf8'));
const first = document.querySelector('[name="first"]');
first.value = 'unsaved change';
document.querySelector('[href="#second"]').click();
assert.equal(document.querySelector('#panel-first').hidden, true);
assert.equal(document.querySelector('#panel-second').hidden, false);
assert.equal(document.querySelector('[href="#second"]').getAttribute('aria-current'), 'page');
assert.equal(first.disabled, false, 'Navigation must not disable fields.');
assert.equal(new window.FormData(document.querySelector('form')).get('first'), 'unsaved change');
document.querySelector('[href="#first"]').click();
assert.equal(document.querySelector('[name="first"]'), first, 'Navigation must preserve the control node.');
assert.equal(first.value, 'unsaved change');
const dirty = new window.Event('beforeunload', { cancelable: true });
window.dispatchEvent(dirty);
assert.equal(dirty.defaultPrevented, true, 'Unsaved changes must be protected when leaving.');
document.querySelector('[name="second"]').dispatchEvent(new window.Event('invalid', { bubbles: false }));
assert.equal(document.querySelector('#panel-second').hidden, false, 'Invalid hidden fields must become focusable.');
assert.equal(document.querySelector('[name="_wp_http_referer"]').value, '/wp-admin/options.php#second');
window.history.replaceState(null, '', '#unknown');
window.dispatchEvent(new window.Event('hashchange'));
assert.equal(document.querySelector('#panel-first').hidden, false, 'Unknown deep links must resolve to a real panel.');
document.querySelector('form').dispatchEvent(new window.Event('submit'));
const saving = new window.Event('beforeunload', { cancelable: true });
window.dispatchEvent(saving);
assert.equal(saving.defaultPrevented, false, 'A legitimate submit must not show an unsaved prompt.');
dom.window.close();
console.log('Workspace navigation: 13 assertions passed.');
