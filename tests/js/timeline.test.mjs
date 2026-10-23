// Run with: npm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {buildTimeline, dayKey, groupByDay, relativeDay} from '../../js/timeline.js';

// fixed local times, the npm script runs with TZ=UTC
const at = (iso) => Date.parse(iso + 'Z') / 1000;

test('dayKey uses the local calendar day', () => {
  assert.equal(dayKey(at('2026-10-23T23:59:00')), '2026-10-23');
  assert.equal(dayKey(at('2026-10-24T00:00:00')), '2026-10-24');
});

test('relativeDay names today and yesterday only', () => {
  const now = at('2026-10-23T10:00:00');
  assert.equal(relativeDay('2026-10-23', now), 'today');
  assert.equal(relativeDay('2026-10-22', now), 'yesterday');
  assert.equal(relativeDay('2026-10-21', now), null);
  // across a month boundary
  assert.equal(relativeDay('2026-09-30', at('2026-10-01T08:00:00')), 'yesterday');
});

test('rows are sorted newest first and grouped by day', () => {
  const items = buildTimeline([
    {id: 'a', time: at('2026-10-22T09:00:00')},
    {id: 'b', time: at('2026-10-23T11:00:00')},
    {id: 'c', time: at('2026-10-23T08:00:00')}
  ]);

  assert.deepEqual(items.map(item => item.row.id), ['b', 'c', 'a']);

  const days = groupByDay(items);
  assert.deepEqual(days.map(day => day.day), ['2026-10-23', '2026-10-22']);
  assert.deepEqual(days[0].items.map(item => item.row.id), ['b', 'c']);
});

test('changes outside the Panel within five minutes collapse into one item', () => {
  const deploy = Array.from({length: 12}, (_, i) => ({
    id: 'page-' + i,
    outside: true,
    time: at('2026-10-23T12:00:00') + i * 2
  }));

  const items = buildTimeline([
    ...deploy,
    {id: 'edit', time: at('2026-10-23T13:00:00')}
  ]);

  assert.equal(items.length, 2);
  assert.equal(items[0].type, 'row');
  assert.equal(items[1].type, 'outside');
  assert.equal(items[1].rows.length, 12);
  assert.equal(items[1].rows[0].id, 'page-11');
});

test('a gap of more than five minutes or a Panel change splits the groups', () => {
  const base = at('2026-10-23T12:00:00');
  const items = buildTimeline([
    {id: 'o1', outside: true, time: base},
    {id: 'o2', outside: true, time: base + 60},
    {id: 'o3', outside: true, time: base + 60 + 301},
    {id: 'o4', outside: true, time: base + 60 + 302},
    {id: 'p', time: base + 1000},
    {id: 'o5', outside: true, time: base + 1001}
  ]);

  assert.deepEqual(items.map(item => item.type === 'outside' ? item.rows.map(r => r.id).join('+') : item.row.id), [
    'o5', 'p', 'o4+o3', 'o2+o1'
  ]);
});

test('a single change outside the Panel stays a normal row', () => {
  const items = buildTimeline([{id: 'x', outside: true, time: at('2026-10-23T12:00:00')}]);
  assert.equal(items[0].type, 'row');
});

test('outside groups do not span midnight', () => {
  const items = buildTimeline([
    {id: 'late', outside: true, time: at('2026-10-22T23:59:00')},
    {id: 'early', outside: true, time: at('2026-10-23T00:01:00')}
  ]);

  assert.deepEqual(items.map(item => item.type), ['row', 'row']);
  assert.deepEqual(groupByDay(items).map(day => day.day), ['2026-10-23', '2026-10-22']);
});
