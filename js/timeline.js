/**
 * Timeline of all changes: pure helpers, see timeline.test.js
 */

/** Changes outside the Panel closer together than this (seconds) form one group, e.g. a deploy */
export const COLLAPSE_WINDOW = 5 * 60;

/**
 * Local calendar day of a unix timestamp, e.g. "2026-10-23"
 */
export function dayKey(time) {
  const date = new Date(time * 1000);
  const pad = number => String(number).padStart(2, '0');

  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
}

/**
 * "today", "yesterday" or null for older days
 */
export function relativeDay(key, now = Date.now() / 1000) {
  if (key === dayKey(now)) {
    return 'today';
  }

  // noon avoids daylight saving edge cases
  const today = new Date(now * 1000);
  const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1, 12);

  return key === dayKey(yesterday.getTime() / 1000) ? 'yesterday' : null;
}

/** Order of rows with the same time: changes first, then the groups */
const GROUP_RANK = {outside: 1, baseline: 2};

/**
 * Group a row collapses into: 'outside' for a change outside the Panel,
 * 'baseline' for a starting point, null for a change in the Panel
 */
export function rowGroup(row) {
  if (row.group !== undefined) {
    return row.group;
  }

  return row.outside ? 'outside' : null;
}

/**
 * Flat list of timeline items, newest first.
 *
 * Each row is {time, group, ...} (or {time, outside} for a change outside
 * the Panel). Rows of the same group within `window` seconds of each other
 * (and on the same day) become one item {type: group, rows, time, day}:
 * changes outside the Panel, e.g. a deploy, as 'outside', the starting
 * points stored in one scan as 'baseline'. The two groups may interleave,
 * a change in the Panel ends both. Everything else stays
 * {type: 'row', row, time, day}.
 */
export function buildTimeline(rows, {window = COLLAPSE_WINDOW, minGroup = 2} = {}) {
  const rank = row => GROUP_RANK[rowGroup(row)] || 0;
  const sorted = [...rows].sort((a, b) => b.time - a.time || rank(a) - rank(b));

  // one slot per item; a group fills the slot of its newest row
  const slots = [];
  const open = {};

  const close = group => {
    const cluster = open[group];
    if (!cluster) {
      return;
    }

    slots[cluster.slot] = cluster.rows.length >= minGroup
      ? {type: group, rows: cluster.rows, time: cluster.rows[0].time, day: dayKey(cluster.rows[0].time)}
      : {type: 'row', row: cluster.rows[0], time: cluster.rows[0].time, day: dayKey(cluster.rows[0].time)};

    delete open[group];
  };

  sorted.forEach(row => {
    const group = rowGroup(row);

    if (!group) {
      Object.keys(open).forEach(close);
      slots.push({type: 'row', row, time: row.time, day: dayKey(row.time)});
      return;
    }

    const cluster = open[group];
    const last = cluster?.rows[cluster.rows.length - 1];
    const joins = last && last.time - row.time <= window && dayKey(last.time) === dayKey(row.time);

    if (joins) {
      cluster.rows.push(row);
      return;
    }

    close(group);
    open[group] = {slot: slots.length, rows: [row]};
    slots.push(null);
  });

  Object.keys(open).forEach(close);

  return slots;
}

/**
 * Items grouped by day, keeping their order: [{day, items}]
 */
export function groupByDay(items) {
  const days = [];

  items.forEach(item => {
    const current = days[days.length - 1];
    if (current && current.day === item.day) {
      current.items.push(item);
    } else {
      days.push({day: item.day, items: [item]});
    }
  });

  return days;
}

/**
 * Whether a single change passes the period, language and author filters.
 * The timeline and the CSV export apply it to every entry, so both list
 * the same changes. `editorKey` maps an editor to its author filter value.
 */
export function entryMatches(entry, {since = 0, language = '', author = '', editorKey}) {
  if ((entry.time ?? 0) < since) {
    return false;
  }

  if (language && entry.language !== language) {
    return false;
  }

  return !author || editorKey(entry.editor) === author;
}
