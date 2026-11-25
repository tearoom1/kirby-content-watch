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
            v-bind="slotAttrs(canDiff(entry, 'previous'))"
            icon="split"
            size="xs"
            v-cw-tooltip="$t('tearoom1.content-watch.diff.view')"
            :aria-label="$t('tearoom1.content-watch.diff.view')"
            @click="openDiff(entry, 'previous')"
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
import {CURRENT, PREVIOUS} from '../diff-pairs.js';
import {latestRequest, ownDialog} from '../requests.js';

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
    // a view reload or the next page with the same blueprint brings
    // fresh props; answers still on their way belong to the old ones
    entries() {
      this.reset();
    },
    history() {
      this.reset();
    },
    'endpoints.field'() {
      this.reset();
    }
  },

  created() {
    // not reactive: only the latest history request may apply its answer
    this.requests = latestRequest();

    // saving the page adds a new entry
    this.$events.on('model.update', this.fetch);
  },

  unmounted() {
    this.$events.off('model.update', this.fetch);
  },

  methods: {
    async fetch() {
      const endpoint = this.endpoints?.field;

      if (!endpoint) {
        return;
      }

      await this.requests.run(() => this.$api.get(endpoint), response => {
        this.loaded = {
          areaUrl: response.areaUrl,
          canAccess: response.canAccess,
          entries: response.entries || [],
          headline: response.headline,
          history: response.history
        };
      });
    },

    reset() {
      this.requests.invalidate();
      this.loaded = null;
    },

    // 'previous': what the version changed, 'current': the version against the content as it is now
    canDiff(entry, mode = PREVIOUS) {
      if (!this.state.history?.enableDiff || !entry?.has_snapshot) {
        return false;
      }

      return mode === CURRENT || Boolean(entry.previous?.has_snapshot);
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
          // the restore dialog is the open one while it submits
          submit: () => this.restore(entry, ownDialog(this.$panel.dialog))
        }
      });
    },

    async restore(entry, dialog) {
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

        // only the restore dialog, not one opened while the request ran
        dialog.close();
        window.panel.notification.success(this.$t('tearoom1.content-watch.restore.done', {version: entry.version}));

        // reload the form with the restored content and the new history entry
        await window.panel.view.reload();
        await this.fetch();
      } catch (error) {
        window.panel.notification.error(error.message || 'Failed to restore content');
      }
    },

    // the dialog steps through the versions itself, also with the arrow keys
    openDiff(entry, mode = PREVIOUS) {
      this.$panel.dialog.open({
        component: 'k-content-watch-field-diff-dialog',
        props: {
          entry,
          mode,
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
      // the starting point: the content as first found, not a change
      if (entry.source === 'baseline') {
        return 'baseline';
      }

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
  color: var(--color-green-700);
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
.k-cw-section-tag-baseline { --tag-color: var(--color-gray-500); }

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
