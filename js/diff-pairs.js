// Which two versions the diff dialog compares.
//
// History lists are newest first and may mix languages. Two modes:
// - 'previous': what a version changed, compared with the version before it
//   in the same language
// - 'current': a version compared with the content as it is now
//
// Stepping direction: 1 = older (higher index), -1 = newer (lower index).

export const PREVIOUS = 'previous';
export const CURRENT = 'current';

// index of the version before `index` in the same language, -1 if none
export function previousIndex (history, index) {
  const entry = history[index];

  if (!entry) {
    return -1;
  }

  for (let i = index + 1; i < history.length; i++) {
    if ((history[i].language || '') === (entry.language || '')) {
      return i;
    }
  }

  return -1;
}

// whether the version at `index` can be shown in this mode
export function canCompare (history, index, mode) {
  const entry = history[index];

  if (!entry?.has_snapshot) {
    return false;
  }

  if (mode === CURRENT) {
    return true;
  }

  const previous = history[previousIndex(history, index)];
  return Boolean(previous?.has_snapshot);
}

// the two sides of the dialog for a version: {from, to}, `to` may be 'current'
export function pairFor (history, index, mode) {
  if (mode === CURRENT) {
    return {from: index, to: CURRENT};
  }

  return {from: previousIndex(history, index), to: index};
}

// the mode a free choice in the selects matches, null for any other pair
export function modeOf (history, from, to) {
  if (to === CURRENT) {
    return CURRENT;
  }

  return previousIndex(history, to) === from ? PREVIOUS : null;
}

// the version a pair is about: the newer side, or the older one against the current content
export function focusOf (from, to) {
  return to === CURRENT ? from : to;
}

// next version in that direction that can be shown in this mode,
// staying in the language of the starting version; -1 if none
export function step (history, index, mode, direction) {
  const language = history[index]?.language || '';

  for (let i = index + direction; i >= 0 && i < history.length; i += direction) {
    if ((history[i].language || '') === language && canCompare(history, i, mode)) {
      return i;
    }
  }

  return -1;
}

// arrow keys step through versions unless the user is typing or choosing in a field
export function isTypingTarget (element) {
  if (!element) {
    return false;
  }

  const tag = (element.tagName || '').toLowerCase();
  return tag === 'input' || tag === 'select' || tag === 'textarea' || element.isContentEditable === true;
}
