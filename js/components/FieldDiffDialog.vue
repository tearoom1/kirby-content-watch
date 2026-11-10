<template>
  <!-- opened with panel.dialog.open() by the contentwatch field -->
  <k-dialog
    :visible="visible"
    size="huge"
    cancel-button=""
    :submit-button="$t('close')"
    class="k-content-watch-diff-dialog"
    @cancel="$emit('cancel')"
    @submit="$emit('close')"
  >
    <header v-if="diff" class="k-cw-section-diff-header">
      <strong>v{{ diff.entry.previous.version }} → v{{ diff.entry.version }}</strong>
      <span>{{ entryEditor(diff.entry) }} · {{ formatAbsolute(diff.entry.time) }}</span>
      <k-button-group>
        <k-button
          icon="angle-left"
          size="xs"
          variant="filled"
          :disabled="!diffNeighbour(1)"
          @click="openDiff(diffNeighbour(1))"
        >Older</k-button>
        <k-button
          icon="angle-right"
          icon-after
          size="xs"
          variant="filled"
          :disabled="!diffNeighbour(-1)"
          @click="openDiff(diffNeighbour(-1))"
        >Newer</k-button>
      </k-button-group>
    </header>
    <k-loader v-if="diff?.loading"/>
    <div v-else-if="diff?.html" class="k-content-watch-diff-content" v-html="diff.html"></div>
    <k-empty v-else icon="document" text="No diff available"/>
  </k-dialog>
</template>

<script>
import {editorLabel} from '../editor.js';
import {formatAbsolute} from '../time.js';

/**
 * Diff of one version against the one before it, with
 * buttons to step through the versions of the field
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
    visible: Boolean
  },

  emits: ['cancel', 'close'],

  data() {
    return {
      diff: null
    };
  },

  created() {
    this.openDiff(this.entry);
  },

  methods: {
    canDiff(entry) {
      return this.history?.enableDiff && entry.has_snapshot && entry.previous?.has_snapshot;
    },

    async openDiff(entry) {
      this.diff = {entry, html: null, loading: true};

      try {
        const response = await this.$api.post('/content-watch/diff', {
          dirPath: this.history.dirPath,
          fileKey: this.history.fileKey,
          fromEntryId: entry.previous.entry_id ?? null,
          toEntryId: entry.entry_id ?? null,
          fromTimestamp: entry.previous.time,
          toTimestamp: entry.time
        });

        // ignore late answers when the user moved on to another version
        if (this.diff?.entry === entry) {
          this.diff = {entry, html: response.diff, loading: false};
        }
      } catch (error) {
        this.diff = {entry, html: null, loading: false};
        window.panel.notification.error('Error loading diff: ' + (error.message || 'Unknown error'));
      }
    },

    // direction 1 = older, -1 = newer
    diffNeighbour(direction) {
      const index = this.entries.indexOf(this.diff?.entry) + direction;
      const entry = this.entries[index];

      return entry && this.canDiff(entry) ? entry : null;
    },

    entryEditor(entry) {
      return editorLabel(entry.editor, this.$t.bind(this));
    },

    formatAbsolute
  }
};
</script>
