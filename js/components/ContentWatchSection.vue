<template>
  <k-section
    v-if="canAccess"
    :headline="headline"
    :buttons="buttons"
    class="k-content-watch-history-section"
  >
    <ul v-if="entries.length" class="k-cw-section-list">
      <li
        v-for="(entry, index) in entries"
        :key="entry.entry_id || entry.version"
        class="k-cw-section-item"
        :class="{'k-cw-section-item-current': index === 0}"
      >
        <!-- same row anatomy as the timeline in the Content Watch area -->
        <span class="k-cw-section-version">v{{ entry.version }}</span>
        <span v-if="entry.language" class="k-cw-section-language">{{ entry.language }}</span>
        <span
          class="k-cw-section-tag"
          :class="'k-cw-section-tag-' + entryAction(entry)"
        >{{ entryAction(entry) }}</span>
        <span v-if="history?.enableDiff || history?.enableRestore" class="k-cw-section-actions">
          <!-- fixed slots: diff, restore; empty slots keep their width -->
          <k-button
            v-if="history?.enableDiff"
            v-bind="slotAttrs(canDiff(entry, 'previous'))"
            icon="split"
            size="xs"
            v-cw-tooltip="$t('tearoom1.content-watch.diff.view')"
            :aria-label="$t('tearoom1.content-watch.diff.view')"
            @click="openDiff(entry, 'previous')"
          />
          <k-button
            v-if="history?.enableRestore"
            v-bind="slotAttrs(canRestore(entry, index))"
            icon="undo"
            size="xs"
            v-cw-tooltip="'Restore this version'"
            @click="confirmRestore(entry)"
          />
        </span>
        <span class="k-cw-section-meta">
          <span class="k-cw-section-editor" v-cw-tooltip="editorHelp(entry.editor)">{{ entryEditor(entry) }}</span>
          <span class="k-cw-section-time" v-cw-tooltip="formatAbsolute(entry.time)">
            · {{ formatRelative(entry.time) }}
          </span>
        </span>
      </li>
    </ul>
    <k-empty v-else icon="clock" text="No changes recorded yet"/>

    <k-dialog
      v-if="history?.enableRestore"
      ref="restoreDialog"
      :submit-button="restoreTarget ? $t('tearoom1.content-watch.restore.confirm.submit', {version: restoreTarget.version}) : $t('restore')"
      theme="positive"
      icon="undo"
      @submit="restore"
    >
      <k-text v-if="restoreTarget">
        <p>
          <strong>{{ $t('tearoom1.content-watch.restore.confirm.title', {version: restoreTarget.version}) }}</strong>
          {{ restoreTarget.language
            ? $t('tearoom1.content-watch.restore.confirm.text.language', {version: restoreTarget.version, language: languageName(restoreTarget.language)})
            : $t('tearoom1.content-watch.restore.confirm.text', {version: restoreTarget.version}) }}
        </p>
        <ul>
          <li>{{ $t('tearoom1.content-watch.restore.confirm.history') }}</li>
          <li v-if="restoreTarget.language">{{ $t('tearoom1.content-watch.restore.confirm.languages') }}</li>
        </ul>
      </k-text>
    </k-dialog>

    <k-dialog
      v-if="history?.enableDiff"
      ref="diffDialog"
      size="huge"
      cancel-button=""
      :submit-button="$t('close')"
      class="k-content-watch-diff-dialog"
      @submit="$refs.diffDialog.close()"
      @close="diff = null"
    >
      <header v-if="diff" class="k-cw-section-diff-header">
        <strong v-if="diff.mode === 'current'">v{{ diff.entry.version }} → {{ $t('tearoom1.content-watch.diff.current') }}</strong>
        <strong v-else>v{{ diff.entry.previous.version }} → v{{ diff.entry.version }}</strong>
        <span>{{ entryEditor(diff.entry) }} · {{ formatAbsolute(diff.entry.time) }}</span>

        <!-- same toolbar as in the Content Watch area, the arrow keys step too -->
        <div class="k-cw-diff-toolbar">
          <k-button-group class="k-cw-diff-modes" role="group" :aria-label="$t('tearoom1.content-watch.diff.mode.label')">
            <k-button
              v-for="mode in ['previous', 'current']"
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
  </k-section>
</template>

<script>
import {formatDistance} from 'date-fns';
import {editorHelp, editorLabel} from '../editor.js';
import {CURRENT, PREVIOUS, isTypingTarget} from '../diff-pairs.js';

export default {
  data() {
    return {
      headline: null,
      canAccess: false,
      entries: [],
      areaUrl: null,
      history: null,
      restoreTarget: null,
      diff: null
    };
  },

  computed: {
    buttons() {
      return [{
        icon: 'clock',
        text: 'All changes',
        click: this.openArea
      }];
    }
  },

  async created() {
    await this.fetch();
    // saving the page adds a new entry
    this.$events.on('model.update', this.fetch);
    window.addEventListener('keydown', this.onDiffKeydown);
  },

  destroyed() {
    this.$events.off('model.update', this.fetch);
    window.removeEventListener('keydown', this.onDiffKeydown);
  },

  methods: {
    async fetch() {
      const response = await this.load();
      this.headline = response.headline;
      this.canAccess = response.canAccess;
      this.entries = response.entries || [];
      this.areaUrl = response.areaUrl;
      this.history = response.history;
    },

    // 'previous': what the version changed, 'current': the version against the content as it is now
    canDiff(entry, mode = PREVIOUS) {
      if (!this.history?.enableDiff || !entry?.has_snapshot) {
        return false;
      }

      return mode === CURRENT || Boolean(entry.previous?.has_snapshot);
    },

    canRestore(entry, index) {
      return this.history?.enableRestore && entry.has_snapshot && index > 0 && !entry.same_as_current;
    },

    // a button slot that doesn't apply stays in place, invisible and inert
    slotAttrs(applies) {
      return applies ? {} : {
        class: 'k-cw-slot-empty',
        disabled: true,
        tabindex: -1,
        'aria-hidden': 'true'
      };
    },

    confirmRestore(entry) {
      this.restoreTarget = entry;
      this.$refs.restoreDialog.open();
    },

    async restore() {
      const entry = this.restoreTarget;

      try {
        const response = await this.$api.post('/content-watch/restore', {
          dirPath: this.history.dirPath,
          fileKey: this.history.fileKey,
          entryId: entry.entry_id ?? null,
          timestamp: entry.time
        });

        if (response.status !== 'success') {
          throw new Error(response.message);
        }

        this.$refs.restoreDialog.close();
        window.panel.notification.success(this.$t('tearoom1.content-watch.restore.done', {version: entry.version}));

        // reload the form with the restored content and the new history entry
        await window.panel.view.reload();
        await this.fetch();
      } catch (error) {
        window.panel.notification.error(error.message || 'Failed to restore content');
      } finally {
        this.restoreTarget = null;
      }
    },

    async openDiff(entry, mode = PREVIOUS) {
      const isOpen = this.diff !== null;
      // keep the last result on screen until the new one is there
      const diff = {entry, mode, html: this.diff?.html ?? null, loaded: this.diff?.loaded ?? false, loading: true};
      this.diff = diff;

      if (!isOpen) {
        this.$refs.diffDialog.open();
      }

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
        if (this.diff === diff) {
          this.diff = {entry, mode, html: response.diff, loaded: true, loading: false};
        }
      } catch (error) {
        if (this.diff === diff) {
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

    // arrow keys step through versions while the diff dialog is open
    onDiffKeydown(event) {
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

    openArea() {
      // Navigate inside the panel instead of a full page reload
      this.$go(this.areaUrl);
    },

    entryAction(entry) {
      if (entry.restored_from || entry.restored_from_id) {
        return 'restored';
      }

      if (entry.action === 'moved' || entry.action === 'duplicated') {
        return entry.action;
      }

      return 'edited';
    },

    entryEditor(entry) {
      return editorLabel(entry.editor, this.$t.bind(this));
    },

    languageName(code) {
      const languages = window.panel?.languages || [];
      return languages.find(language => language.code === code)?.name || code.toUpperCase();
    },

    editorHelp(editor) {
      return editorHelp(editor, this.$t.bind(this));
    },

    formatRelative(time) {
      return formatDistance(new Date(time * 1000), new Date(), {addSuffix: true});
    },

    formatAbsolute(time) {
      const panel = window.panel || {};
      const language = (panel.language && panel.language.code) || window.navigator.language || 'en';

      return new Date(time * 1000).toLocaleString(language, {
        dateStyle: 'medium',
        timeStyle: 'short'
      });
    }
  }
};
</script>

<style>
.k-cw-section-list {
  margin: 0;
  padding: 0 var(--spacing-3);
  list-style: none;
  background: var(--item-color-back);
  border-radius: var(--rounded);
  box-shadow: var(--shadow);
  font-size: var(--text-sm);
}

/* row 1: version, language, tag – row 2: editor · time */
.k-cw-section-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 0.5rem;
  row-gap: 0.2rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--color-border);
}

.k-cw-section-item:last-child {
  border-bottom: none;
}

.k-cw-section-version {
  flex: 0 0 2.5rem;
  font-family: var(--font-mono);
  font-size: var(--text-xs);
  color: var(--color-text-dimmed);
}

.k-cw-section-item-current .k-cw-section-version {
  color: var(--color-positive);
}

.k-cw-section-language {
  font-family: var(--font-mono);
  font-size: 0.65rem;
  line-height: 1.1rem;
  padding-inline: 0.3rem;
  border: 1px solid var(--color-border);
  border-radius: var(--rounded-sm);
  color: var(--color-text-dimmed);
  text-transform: uppercase;
}

.k-cw-section-tag {
  --tag-color: var(--color-text-dimmed);
  flex: 0 0 5rem;
  font-size: var(--text-xs);
  font-weight: var(--font-semi);
  line-height: 1.25rem;
  text-align: center;
  border-radius: 999px;
  color: var(--tag-color);
  background: color-mix(in srgb, var(--tag-color) 14%, transparent);
}

.k-cw-section-tag-restored { --tag-color: var(--color-orange-500); }
.k-cw-section-tag-moved { --tag-color: var(--color-blue-500); }
.k-cw-section-tag-duplicated { --tag-color: var(--color-purple-500); }

.k-cw-section-actions {
  display: flex;
  margin-inline-start: auto;
  margin-block: -0.25rem;
}

.k-cw-section-actions .k-cw-slot-empty {
  visibility: hidden;
}

.k-cw-section-actions .k-button {
  --button-color-text: var(--color-text-dimmed);
}

.k-cw-section-actions .k-button:hover {
  --button-color-text: var(--color-text);
}

.k-cw-section-diff-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--color-border);
}

.k-cw-section-diff-header > span {
  color: var(--color-text-dimmed);
  font-size: var(--text-sm);
}


.k-cw-section-meta {
  flex-basis: 100%;
  min-width: 0;
  padding-left: 3rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.k-cw-section-editor {
  font-weight: var(--font-semi);
}

.k-cw-section-time {
  color: var(--color-text-dimmed);
}
</style>
