<template>
  <k-section
    v-if="canAccess"
    :headline="headline"
    :buttons="buttons"
    class="k-content-watch-history-section"
  >
    <k-items
      v-if="entries.length"
      :items="items"
      layout="list"
    />
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
    },

    items() {
      return this.entries.map(entry => {
        const editor = entry.editor?.name || entry.editor?.email || 'Unknown';
        const info = [this.formatRelative(entry.time)];

        if (entry.language) {
          info.push(entry.language.toUpperCase());
        }

        return {
          id: entry.entry_id || entry.version,
          text: `v${entry.version} · ${this.actionLabel(entry)} ${editor}`,
          info: info.join(' · '),
          image: {
            icon: this.actionIcon(entry),
            back: 'transparent',
            color: 'var(--color-text-dimmed)'
          }
        };
      });
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

    actionLabel(entry) {
      if (entry.restored_from || entry.restored_from_id) {
        return 'restored by';
      }

      if (entry.action === 'moved') {
        return 'moved by';
      }

      if (entry.action === 'duplicated') {
        return 'duplicated by';
      }

      return 'edited by';
    },

    actionIcon(entry) {
      if (entry.restored_from || entry.restored_from_id) {
        return 'undo';
      }

      return {
        moved: 'parent',
        duplicated: 'copy'
      }[entry.action] || 'edit';
    },

    formatRelative(time) {
      return formatDistance(new Date(time * 1000), new Date(), {addSuffix: true});
    }
  }
};
</script>
