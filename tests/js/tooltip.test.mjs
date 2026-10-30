// Run with: npm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {tooltipPosition} from '../../js/tooltip.js';

const viewport = {width: 1000, height: 800};
const size = {width: 200, height: 40};

const trigger = (left, top) => ({left, top, right: left + 30, bottom: top + 30});

test('sits under the trigger on its left edge', () => {
  assert.deepEqual(tooltipPosition(trigger(100, 100), size, viewport), {top: 136, left: 100});
});

test('aligns to the left near the right window edge', () => {
  const {left} = tooltipPosition(trigger(900, 100), size, viewport);
  assert.equal(left, 730);
  assert.ok(left + size.width <= viewport.width);
});

test('moves above the trigger near the bottom window edge', () => {
  assert.equal(tooltipPosition(trigger(100, 760), size, viewport).top, 714);
});

test('stays in the window when neither side has room', () => {
  const small = {width: 300, height: 60};
  const position = tooltipPosition({left: 10, right: 290, top: 10, bottom: 50}, {width: 280, height: 45}, small);
  assert.deepEqual(position, {top: 8, left: 10});
});

test('never starts past the left window edge', () => {
  const wide = {width: 400, height: 40};
  assert.equal(tooltipPosition(trigger(200, 100), wide, {width: 416, height: 800}).left, 8);
});
