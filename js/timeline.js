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

/**
 * Flat list of timeline items, newest first.
 *
 * Each row is {time, outside, ...}. Consecutive rows changed outside the
 * Panel within `window` seconds of each other (and on the same day) become
 * one item {type: 'outside', rows, time, day}; everything else stays
 * {type: 'row', row, time, day}.
 */
export function buildTimeline(rows, {window = COLLAPSE_WINDOW, minGroup = 2} = {}) {
  const sorted = [...rows].sort((a, b) => b.time - a.time);
  const items = [];
  let cluster = [];

  const flush = () => {
    if (cluster.length >= minGroup) {
      items.push({type: 'outside', rows: cluster, time: cluster[0].time, day: dayKey(cluster[0].time)});
    } else {
      cluster.forEach(row => items.push({type: 'row', row, time: row.time, day: dayKey(row.time)}));
    }
    cluster = [];
  };

  sorted.forEach(row => {
    const last = cluster[cluster.length - 1];
    const joins = row.outside && last &&
      last.time - row.time <= window &&
      dayKey(last.time) === dayKey(row.time);

    if (!joins) {
      flush();
    }

    if (row.outside) {
      cluster.push(row);
    } else {
      items.push({type: 'row', row, time: row.time, day: dayKey(row.time)});
    }
  });

  flush();

  return items;
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
