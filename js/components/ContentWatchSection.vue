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
        <span class="k-cw-section-meta">
          <span class="k-cw-section-editor">{{ entryEditor(entry) }}</span>
          <span class="k-cw-section-time" :title="formatAbsolute(entry.time)">
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

export default {
  data() {
    return {
      headline: null,
      canAccess: false,
      entries: [],
      areaUrl: null
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
    const response = await this.load();
    this.headline = response.headline;
    this.canAccess = response.canAccess;
    this.entries = response.entries || [];
    this.areaUrl = response.areaUrl;
  },

  methods: {
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
      return entry.editor?.name || entry.editor?.email || 'Unknown';
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
