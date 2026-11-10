<template>
  <k-section
    v-if="state.canAccess"
    :headline="state.headline"
    :buttons="buttons"
    class="k-content-watch-history-section"
  >
    <ul v-if="state.entries.length" class="k-cw-section-list">
      <li
        v-for="(entry, index) in state.entries"
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
        <span v-if="state.history?.enableDiff || state.history?.enableRestore" class="k-cw-section-actions">
          <!-- fixed slots: diff, restore; empty slots keep their width -->
          <k-button
            v-if="state.history?.enableDiff"
            v-bind="slotAttrs(canDiff(entry))"
            icon="split"
            size="xs"
            :class="{'k-cw-diff-same': index > 0 && entry.same_as_current}"
            v-cw-tooltip="index > 0 && entry.same_as_current ? $t('tearoom1.content-watch.equal') : 'View changes'"
            :aria-label="index > 0 && entry.same_as_current ? $t('tearoom1.content-watch.equal') : 'View changes'"
            @click="openDiff(entry)"
          />
          <k-button
            v-if="state.history?.enableRestore"
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
  </k-section>
</template>

<script>
import {formatDistance} from 'date-fns';
import {editorHelp, editorLabel} from '../editor.js';
import {formatAbsolute} from '../time.js';

/**
 * The `contentwatch` field: the section of the same name until Kirby 5.
 * Its props come from ContentWatchField::state() in PHP. After a save
 * the field asks its own endpoint again, as the history is no form value.
 */
export default {
  inheritAttrs: false,

  props: {
    areaUrl: String,
    canAccess: Boolean,
    endpoints: Object,
    entries: {
      type: Array,
      default: () => []
    },
    headline: String,
    history: {
      type: Object,
      default: null
    }
  },

  data() {
    return {
      // answer of the field endpoint, replaces the props until they change
      loaded: null
    };
  },

  computed: {
    buttons() {
      return [{
        icon: 'clock',
        text: 'All changes',
        click: this.openArea
      }];
    },

    state() {
      return this.loaded ?? {
        areaUrl: this.areaUrl,
        canAccess: this.canAccess,
        entries: this.entries,
        headline: this.headline,
        history: this.history
      };
    }
  },

  watch: {
    // a view reload brings fresh props
    entries() {
      this.loaded = null;
    }
  },

  created() {
    // saving the page adds a new entry
    this.$events.on('model.update', this.fetch);
  },

  unmounted() {
    this.$events.off('model.update', this.fetch);
  },

  methods: {
    async fetch() {
      if (!this.endpoints?.field) {
        return;
      }

      const response = await this.$api.get(this.endpoints.field);

      this.loaded = {
        areaUrl: response.areaUrl,
        canAccess: response.canAccess,
        entries: response.entries || [],
        headline: response.headline,
        history: response.history
      };
    },

    canDiff(entry) {
      return this.state.history?.enableDiff && entry.has_snapshot && entry.previous?.has_snapshot;
    },

    canRestore(entry, index) {
      return this.state.history?.enableRestore && entry.has_snapshot && index > 0 && !entry.same_as_current;
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
      this.$panel.dialog.open({
        component: 'k-content-watch-restore-dialog',
        props: {
          version: entry.version,
          language: entry.language ? this.languageName(entry.language) : null
        },
        on: {
          submit: () => this.restore(entry)
        }
      });
    },

    async restore(entry) {
      try {
        const response = await this.$api.post('/content-watch/restore', {
          dirPath: this.state.history.dirPath,
          fileKey: this.state.history.fileKey,
          entryId: entry.entry_id ?? null,
          timestamp: entry.time
        });

        if (response.status !== 'success') {
          throw new Error(response.message);
        }

        this.$panel.dialog.close();
        window.panel.notification.success(this.$t('tearoom1.content-watch.restore.done', {version: entry.version}));

        // reload the form with the restored content and the new history entry
        await window.panel.view.reload();
        await this.fetch();
      } catch (error) {
        window.panel.notification.error(error.message || 'Failed to restore content');
      }
    },

    openDiff(entry) {
      this.$panel.dialog.open({
        component: 'k-content-watch-field-diff-dialog',
        props: {
          entry,
          entries: this.state.entries,
          history: this.state.history
        }
      });
    },

    openArea() {
      // Navigate inside the panel instead of a full page reload
      this.$go(this.state.areaUrl);
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

    formatAbsolute
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
