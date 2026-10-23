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
            v-bind="slotAttrs(canDiff(entry))"
            icon="split"
            size="xs"
            :class="{'k-cw-diff-same': index > 0 && entry.same_as_current}"
            :title="index > 0 && entry.same_as_current ? $t('tearoom1.content-watch.equal') : 'View changes'"
            :aria-label="index > 0 && entry.same_as_current ? $t('tearoom1.content-watch.equal') : 'View changes'"
            @click="openDiff(entry)"
          />
          <k-button
            v-if="history?.enableRestore"
            v-bind="slotAttrs(canRestore(entry, index))"
            icon="undo"
            size="xs"
            title="Restore this version"
            @click="confirmRestore(entry)"
          />
        </span>
        <span class="k-cw-section-meta">
          <span class="k-cw-section-editor" :title="editorHelp(entry.editor)">{{ entryEditor(entry) }}</span>
          <span class="k-cw-section-time" :title="formatAbsolute(entry.time)">
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
  </k-section>
</template>

<script>
import {formatDistance} from 'date-fns';
import {editorHelp, editorLabel} from '../editor.js';

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
  },

  destroyed() {
    this.$events.off('model.update', this.fetch);
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

    canDiff(entry) {
      return this.history?.enableDiff && entry.has_snapshot && entry.previous?.has_snapshot;
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

    async openDiff(entry) {
      const isOpen = this.diff !== null;
      this.diff = {entry, html: null, loading: true};

      if (!isOpen) {
        this.$refs.diffDialog.open();
      }

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

/* the version equals the current content: the diff only says "No changes" */
.k-cw-section-actions .k-cw-diff-same {
  --button-color-text: var(--color-text-dimmed);
  --button-color-icon: var(--color-text-dimmed);
  opacity: 0.6;
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

.k-cw-section-diff-header .k-button-group {
  margin-inline-start: auto;
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
