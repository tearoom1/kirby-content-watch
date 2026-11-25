// Run with: npm test (node's built-in test runner, no dependencies)
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {buildTimeline, dayKey, entryMatches, groupByDay, relativeDay, rowGroup} from '../../js/timeline.js';

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

test('entryMatches applies the author filter to every single change', () => {
  const editorKey = editor => editor?.id || 'outside';
  const today = at('2026-10-23T00:00:00');
  // Alice edited the page yesterday, Bob today
  const entries = [
    {time: at('2026-10-23T10:00:00'), editor: {id: 'bob'}, language: 'de'},
    {time: at('2026-10-22T10:00:00'), editor: {id: 'alice'}, language: 'de'}
  ];

  const pick = filters => entries.filter(entry => entryMatches(entry, {editorKey, ...filters}));

  assert.deepEqual(pick({since: today, author: 'alice'}), []);
  assert.deepEqual(pick({author: 'alice'}).map(entry => entry.editor.id), ['alice']);
  assert.deepEqual(pick({since: today}).map(entry => entry.editor.id), ['bob']);
  assert.deepEqual(pick({language: 'fr'}), []);
  // changes outside the Panel have no editor
  assert.equal(entryMatches({time: today, editor: null}, {author: 'outside', editorKey}), true);
});

test('starting points stored in one scan collapse into their own group', () => {
  const scan = at('2026-10-23T12:00:00');
  const baselines = Array.from({length: 150}, (_, i) => ({id: 'b' + i, group: 'baseline', time: scan + (i % 3)}));

  const items = buildTimeline([
    ...baselines,
    {id: 'deploy-1', group: 'outside', time: scan + 1},
    {id: 'deploy-2', group: 'outside', time: scan + 2},
    {id: 'edit', group: null, time: scan + 2}
  ]);

  // at the same time a Panel change comes first, then the outside group, then the starting points
  assert.deepEqual(items.map(item => item.type), ['row', 'outside', 'baseline']);
  assert.equal(items[0].row.id, 'edit');
  assert.deepEqual(items[1].rows.map(row => row.id), ['deploy-2', 'deploy-1']);
  assert.equal(items[2].rows.length, 150);
});

test('a single starting point stays a normal row', () => {
  const items = buildTimeline([
    {id: 'b', group: 'baseline', time: at('2026-10-23T12:00:00')},
    {id: 'o', group: 'outside', time: at('2026-10-23T12:00:01')}
  ]);

  assert.deepEqual(items.map(item => item.type), ['row', 'row']);
});

test('rowGroup falls back to the outside flag', () => {
  assert.equal(rowGroup({outside: true}), 'outside');
  assert.equal(rowGroup({outside: false}), null);
  assert.equal(rowGroup({group: 'baseline', outside: false}), 'baseline');
});
