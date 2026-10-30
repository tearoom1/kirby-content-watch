/**
 * Immediate tooltip for the Panel: v-cw-tooltip="text"
 * Works on elements and on components such as k-button, an empty value shows nothing.
 * The Panel has no tooltip of its own and the browser title comes late and unstyled.
 *
 * One element on the body serves all triggers, so no overflow container clips it.
 * The surface is styled in tooltip.css.
 */

// distance to the trigger and to the window edge in px
const GAP = 6;
const EDGE = 8;

const TOOLTIP_ID = 'k-cw-tooltip';

let tooltip = null;
let activeTrigger = null;

/**
 * Where to put the tooltip: under the trigger on its left edge, right-aligned
 * near the right window edge, above the trigger near the bottom window edge.
 *
 * @param {{top: number, right: number, bottom: number, left: number}} trigger
 * @param {{width: number, height: number}} size of the tooltip
 * @param {{width: number, height: number}} viewport
 * @returns {{top: number, left: number}}
 */
export function tooltipPosition(trigger, size, viewport) {
  const maxLeft = viewport.width - EDGE - size.width;
  const maxTop = viewport.height - EDGE - size.height;

  let left = trigger.left;

  if (left > maxLeft) {
    left = trigger.right - size.width;
  }

  let top = trigger.bottom + GAP;
  const above = trigger.top - GAP - size.height;

  if (top > maxTop && above >= EDGE) {
    top = above;
  }

  // the window edge always wins
  return {
    top: Math.max(EDGE, Math.min(top, maxTop)),
    left: Math.max(EDGE, Math.min(left, maxLeft))
  };
}

function tooltipElement() {
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = TOOLTIP_ID;
    tooltip.className = 'k-cw-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.appendChild(tooltip);
  }

  return tooltip;
}

function show(trigger) {
  hide();

  const text = trigger._cwTooltip.text;

  if (!text) {
    return;
  }

  const element = tooltipElement();
  element.textContent = text;
  element.hidden = false;

  // measure with the text in place, wrapping decides the height
  const {top, left} = tooltipPosition(
    trigger.getBoundingClientRect(),
    element.getBoundingClientRect(),
    {width: document.documentElement.clientWidth, height: document.documentElement.clientHeight}
  );

  element.style.top = top + 'px';
  element.style.left = left + 'px';

  trigger.setAttribute('aria-describedby', TOOLTIP_ID);
  activeTrigger = trigger;

  // a fixed tooltip does not follow its trigger
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  document.addEventListener('keydown', onKeydown);
}

function hide() {
  if (!activeTrigger) {
    return;
  }

  tooltip.hidden = true;
  activeTrigger.removeAttribute('aria-describedby');
  activeTrigger = null;

  window.removeEventListener('scroll', hide, true);
  window.removeEventListener('resize', hide);
  document.removeEventListener('keydown', onKeydown);
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    hide();
  }
}

function onPointerEnter(event) {
  // no hover on touch, a tap only triggers the element
  if (event.pointerType !== 'touch') {
    show(event.currentTarget);
  }
}

function onFocus(event) {
  // keyboard only, a click must not bring back the tooltip it just closed
  if (event.currentTarget.matches(':focus-visible')) {
    show(event.currentTarget);
  }
}

/**
 * Keeps a trigger usable without a mouse and without the title attribute.
 * k-button derives its aria-label from title and ignores an aria-label attribute,
 * so icon-only triggers get their name here. It also clears the name on each
 * of its renders, see bind().
 */
function update(trigger, text) {
  const state = trigger._cwTooltip;
  state.text = text;

  if (state.named || (!trigger.hasAttribute('aria-label') && !trigger.textContent.trim())) {
    state.named = true;

    if (text) {
      trigger.setAttribute('aria-label', text);
    } else {
      trigger.removeAttribute('aria-label');
    }
  }

  // text and icons need a tab stop to show the tooltip on keyboard focus
  if (state.tabStop || !trigger.matches('a[href], button, input, select, textarea, [tabindex]')) {
    state.tabStop = true;

    if (text) {
      trigger.setAttribute('tabindex', '0');
    } else {
      trigger.removeAttribute('tabindex');
    }
  }
}

const directive = {
  bind(el, binding, vnode) {
    el._cwTooltip = {text: null, named: false, tabStop: false};
    update(el, binding.value);

    // a component renders on its own, after the hooks of this directive
    vnode.componentInstance?.$on('hook:updated', () => update(el, el._cwTooltip.text));

    el.addEventListener('pointerenter', onPointerEnter);
    el.addEventListener('pointerleave', hide);
    el.addEventListener('focus', onFocus);
    el.addEventListener('blur', hide);
    el.addEventListener('click', hide);
  },

  componentUpdated(el, binding) {
    if (binding.value === binding.oldValue) {
      return;
    }

    update(el, binding.value);

    if (activeTrigger === el) {
      show(el);
    }
  },

  unbind(el) {
    if (activeTrigger === el) {
      hide();
    }

    el.removeEventListener('pointerenter', onPointerEnter);
    el.removeEventListener('pointerleave', hide);
    el.removeEventListener('focus', onFocus);
    el.removeEventListener('blur', hide);
    el.removeEventListener('click', hide);
  }
};

export default {
  install(Vue) {
    Vue.directive('cw-tooltip', directive);
  }
};
