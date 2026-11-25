<template>
  <!-- opened with panel.dialog.open() by the contentwatch field -->
  <k-dialog
    :visible="visible"
    size="huge"
    :cancel-button="false"
    :submit-button="$t('close')"
    class="k-content-watch-diff-dialog"
    @cancel="$emit('cancel')"
    @submit="$emit('close')"
  >
    <header v-if="diff" class="k-cw-section-diff-header">
      <strong v-if="diff.mode === 'current'">v{{ diff.entry.version }} → {{ $t('tearoom1.content-watch.diff.current') }}</strong>
      <strong v-else>v{{ diff.entry.previous.version }} → v{{ diff.entry.version }}</strong>
      <span>{{ entryEditor(diff.entry) }} · {{ formatAbsolute(diff.entry.time) }}</span>

      <!-- same toolbar as in the Content Watch area, the arrow keys step too -->
      <div class="k-cw-diff-toolbar">
        <k-button-group class="k-cw-diff-modes" role="group" :aria-label="$t('tearoom1.content-watch.diff.mode.label')">
          <k-button
            v-for="mode in modes"
            :key="mode"
            size="sm"
            :variant="diff.mode === mode ? 'filled' : null"
            :current="diff.mode === mode"
            :autofocus="diff.mode === mode"
            :disabled="!canDiff(diff.entry, mode)"
            @click="mode !== diff.mode && openDiff(diff.entry, mode)"
          >{{ $t('tearoom1.content-watch.diff.mode.' + mode) }}</k-button>
        </k-button-group>
        <k-button-group>
          <k-button
            icon="angle-left"
            size="sm"
            variant="filled"
            :disabled="!diffNeighbour(1)"
            @click="openDiff(diffNeighbour(1), diff.mode)"
          >{{ $t('tearoom1.content-watch.diff.step.older') }}</k-button>
          <k-button
            icon="angle-right"
            icon-after
            size="sm"
            variant="filled"
            :disabled="!diffNeighbour(-1)"
            @click="openDiff(diffNeighbour(-1), diff.mode)"
          >{{ $t('tearoom1.content-watch.diff.step.newer') }}</k-button>
        </k-button-group>
      </div>
    </header>

    <!-- while the next pair loads, the last result stays dimmed so the dialog doesn't jump -->
    <k-loader v-if="diff?.loading && !diff.loaded"/>
    <div v-else-if="diff?.html" class="k-content-watch-diff-content" :class="{'k-cw-diff-stale': diff.loading}" v-html="diff.html"></div>
    <k-empty v-else icon="document" text="No diff available" :class="{'k-cw-diff-stale': diff?.loading}"/>
  </k-dialog>
</template>

<script>
import {editorLabel} from '../editor.js';
import {formatAbsolute} from '../time.js';
import {CURRENT, PREVIOUS, isTypingTarget} from '../diff-pairs.js';

/**
 * Diff of one version against the one before it ('previous') or against
 * the current content ('current'), with buttons and arrow keys to step
 * through the versions of the field
 */
export default {
  // the Panel passes its dialog listeners, the events below are emitted explicitly
  inheritAttrs: false,

  props: {
    // the version to show first
    entry: Object,
    entries: {
      type: Array,
      default: () => []
    },
    history: Object,
    mode: {
      type: String,
      default: PREVIOUS
    },
    visible: Boolean
  },

  emits: ['cancel', 'close'],

  data() {
    return {
      diff: null,
      // counts the requests: Vue 3 hands out proxies, so the diff object can't be compared
      diffRequest: 0,
      modes: [PREVIOUS, CURRENT]
    };
  },

  created() {
    this.openDiff(this.entry, this.mode);
    window.addEventListener('keydown', this.onKeydown);
  },

  unmounted() {
    // answers still on their way belong to the closed dialog
    this.diffRequest++;
    window.removeEventListener('keydown', this.onKeydown);
  },

  methods: {
    canDiff(entry, mode = PREVIOUS) {
      if (!this.history?.enableDiff || !entry?.has_snapshot) {
        return false;
      }

      return mode === CURRENT || Boolean(entry.previous?.has_snapshot);
    },

    async openDiff(entry, mode = PREVIOUS) {
      // keep the last result on screen until the new one is there
      const request = ++this.diffRequest;
      this.diff = {entry, mode, html: this.diff?.html ?? null, loaded: this.diff?.loaded ?? false, loading: true};

      const pair = mode === CURRENT
        ? {fromEntryId: entry.entry_id ?? null, toEntryId: CURRENT, fromTimestamp: entry.time, toTimestamp: null}
        : {fromEntryId: entry.previous.entry_id ?? null, toEntryId: entry.entry_id ?? null, fromTimestamp: entry.previous.time, toTimestamp: entry.time};

      try {
        const response = await this.$api.post('/content-watch/diff', {
          dirPath: this.history.dirPath,
          fileKey: this.history.fileKey,
          ...pair
        });

        // ignore late answers when the user moved on to another version or mode
        if (request === this.diffRequest) {
          this.diff = {entry, mode, html: response.diff, loaded: true, loading: false};
        }
      } catch (error) {
        if (request === this.diffRequest) {
          this.diff = {entry, mode, html: null, loaded: true, loading: false};
        }
        window.panel.notification.error('Error loading diff: ' + (error.message || 'Unknown error'));
      }
    },

    // direction 1 = older, -1 = newer
    diffNeighbour(direction) {
      const index = this.entries.indexOf(this.diff?.entry) + direction;
      const entry = this.entries[index];

      return entry && this.canDiff(entry, this.diff?.mode) ? entry : null;
    },

    // arrow keys step through versions while the dialog is open
    onKeydown(event) {
      if (!this.diff || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || isTypingTarget(event.target)) {
        return;
      }

      const direction = {ArrowLeft: 1, ArrowRight: -1}[event.key];
      const entry = direction ? this.diffNeighbour(direction) : null;

      if (entry) {
        event.preventDefault();
        this.openDiff(entry, this.diff.mode);
      }
    },

    entryEditor(entry) {
      return editorLabel(entry.editor, this.$t.bind(this));
    },

    formatAbsolute
  }
};
</script>
