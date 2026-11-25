/**
 * Readable name of the editor of a change.
 * Entries without a Panel user were changed outside the Panel
 * (deploy, FTP, git, scripts), baselines are the starting point: the
 * content as Content Watch first found it. See ContentWatchController::getEditor().
 *
 * @param {object|null} editor
 * @param {function} t the Panel translation function ($t)
 */
export function editorLabel(editor, t) {
  if (editor?.baseline) {
    return t('tearoom1.content-watch.editor.baseline');
  }

  if (!editor || editor.outside || editor.id === 'unknown') {
    return t('tearoom1.content-watch.editor.outside');
  }

  if (editor.deleted) {
    return t('tearoom1.content-watch.editor.deleted');
  }

  return editor.name || editor.email || editor.id;
}

/**
 * Tooltip explaining the editor label, for starting points and changes outside the Panel
 */
export function editorHelp(editor, t) {
  if (editor?.baseline) {
    return t('tearoom1.content-watch.editor.baseline.help');
  }

  if (!editor || editor.outside || editor.id === 'unknown') {
    return t('tearoom1.content-watch.editor.outside.help');
  }

  return null;
}
