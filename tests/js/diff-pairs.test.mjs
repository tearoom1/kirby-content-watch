// Run with: npm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {CURRENT, PREVIOUS, canCompare, focusOf, isTypingTarget, modeOf, pairFor, previousIndex, step} from '../../js/diff-pairs.js';

// newest first, two languages mixed
const history = [
  {version: 3, language: 'de', has_snapshot: true},
  {version: 2, language: 'en', has_snapshot: true},
  {version: 2, language: 'de', has_snapshot: true},
  {version: 1, language: 'en', has_snapshot: true},
  {version: 1, language: 'de', has_snapshot: true}
];

test('previousIndex stays in the same language', () => {
  assert.equal(previousIndex(history, 0), 2);
  assert.equal(previousIndex(history, 1), 3);
  assert.equal(previousIndex(history, 4), -1);
});

test('the oldest version has nothing to compare with in previous mode', () => {
  assert.equal(canCompare(history, 4, PREVIOUS), false);
  assert.equal(canCompare(history, 4, CURRENT), true);
  assert.equal(canCompare(history, 0, PREVIOUS), true);
});

test('a missing snapshot on either side rules the pair out', () => {
  const gaps = [
    {version: 2, language: '', has_snapshot: true},
    {version: 1, language: '', has_snapshot: false}
  ];
  assert.equal(canCompare(gaps, 0, PREVIOUS), false);
  assert.equal(canCompare(gaps, 1, CURRENT), false);
});

test('pairFor puts the older version on the from side', () => {
  assert.deepEqual(pairFor(history, 0, PREVIOUS), {from: 2, to: 0});
  assert.deepEqual(pairFor(history, 2, CURRENT), {from: 2, to: CURRENT});
});

test('modeOf and focusOf read a pair back', () => {
  assert.equal(modeOf(history, 2, 0), PREVIOUS);
  assert.equal(modeOf(history, 4, CURRENT), CURRENT);
  assert.equal(modeOf(history, 4, 0), null);
  assert.equal(focusOf(2, 0), 0);
  assert.equal(focusOf(2, CURRENT), 2);
});

test('step walks one language and skips what the mode cannot show', () => {
  assert.equal(step(history, 0, PREVIOUS, 1), 2);
  // v1 de has no version before it
  assert.equal(step(history, 2, PREVIOUS, 1), -1);
  assert.equal(step(history, 2, CURRENT, 1), 4);
  assert.equal(step(history, 4, CURRENT, -1), 2);
  assert.equal(step(history, 0, CURRENT, -1), -1);
});

test('isTypingTarget spares fields', () => {
  assert.equal(isTypingTarget({tagName: 'SELECT'}), true);
  assert.equal(isTypingTarget({tagName: 'DIV', isContentEditable: true}), true);
  assert.equal(isTypingTarget({tagName: 'BUTTON'}), false);
  assert.equal(isTypingTarget(null), false);
});
