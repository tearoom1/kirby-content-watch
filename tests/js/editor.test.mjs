// Run with: pnpm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {editorHelp, editorLabel} from '../../js/editor.js';

const t = key => key.replace('tearoom1.content-watch.', '');

test('a starting point has its own label and explanation', () => {
  const editor = {id: 'baseline', name: '', email: '', baseline: true};

  assert.equal(editorLabel(editor, t), 'editor.baseline');
  assert.equal(editorHelp(editor, t), 'editor.baseline.help');
});

test('changes outside the Panel, deleted and known users', () => {
  assert.equal(editorLabel({id: 'unknown', outside: true}, t), 'editor.outside');
  assert.equal(editorHelp(null, t), 'editor.outside.help');
  assert.equal(editorLabel({id: 'x', deleted: true}, t), 'editor.deleted');
  assert.equal(editorLabel({id: 'x', name: 'Ada'}, t), 'Ada');
  assert.equal(editorHelp({id: 'x', name: 'Ada'}, t), null);
});
