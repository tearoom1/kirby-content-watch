/**
 * Date and time of a change in the Panel language, e.g. for a tooltip
 *
 * @param {number} time Unix timestamp in seconds
 */
export function formatAbsolute(time) {
  const panel = window.panel || {};
  const language = (panel.language && panel.language.code) || window.navigator.language || 'en';

  return new Date(time * 1000).toLocaleString(language, {
    dateStyle: 'medium',
    timeStyle: 'short'
  });
}
