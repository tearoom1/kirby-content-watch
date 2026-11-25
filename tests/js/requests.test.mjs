// Run with: pnpm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {latestRequest, ownDialog} from '../../js/requests.js';

// a request whose answer arrives when the test says so
function deferred (value) {
  let resolve;
  const promise = new Promise(r => {
    resolve = () => r(value);
  });

  return {request: () => promise, resolve};
}

test('a late answer of an older request is dropped', async () => {
  const latest = latestRequest();
  const applied = [];

  const pageA = deferred('history of A');
  const pageB = deferred('history of B');

  const runA = latest.run(pageA.request, value => applied.push(value));
  const runB = latest.run(pageB.request, value => applied.push(value));

  // B answers first, A comes in late
  pageB.resolve();
  await runB;
  pageA.resolve();

  assert.equal(await runA, false);
  assert.deepEqual(applied, ['history of B']);
});

test('invalidate drops the answer of a pending request', async () => {
  const latest = latestRequest();
  const applied = [];
  const pageA = deferred('history of A');

  const run = latest.run(pageA.request, value => applied.push(value));

  // the field gets the props of page B before A answers
  latest.invalidate();
  pageA.resolve();

  assert.equal(await run, false);
  assert.deepEqual(applied, []);
});

test('the answer of the only request is applied', async () => {
  const latest = latestRequest();
  const applied = [];

  assert.equal(await latest.run(async () => 'fresh', value => applied.push(value)), true);
  assert.deepEqual(applied, ['fresh']);
});

// close() like Kirby's dialog: with an id, only that dialog closes
function fakeDialog () {
  let next = 1;

  return {
    id: null,
    isOpen: false,
    open () {
      this.id = 'dialog-' + next++;
      this.isOpen = true;
    },
    close (id) {
      if (this.isOpen === false || (id !== undefined && id !== this.id)) {
        return;
      }

      this.id = null;
      this.isOpen = false;
    }
  };
}

test('closing the restore dialog leaves a dialog opened later alone', () => {
  const dialog = fakeDialog();

  dialog.open();
  const restore = ownDialog(dialog);

  // dismissed while the restore request is pending, then another dialog opens
  dialog.close();
  dialog.open();

  restore.close();

  assert.equal(dialog.isOpen, true);
  assert.equal(dialog.id, 'dialog-2');
});

test('the restore dialog closes when it is still open', () => {
  const dialog = fakeDialog();

  dialog.open();
  ownDialog(dialog).close();

  assert.equal(dialog.isOpen, false);
});

test('without an id nothing is closed', () => {
  const dialog = fakeDialog();

  dialog.open();
  dialog.id = null;
  ownDialog(dialog).close();

  assert.equal(dialog.isOpen, true);
});
