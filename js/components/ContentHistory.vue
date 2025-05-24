<template>
  <k-panel-inside class="k-content-history-view">
    <section v-if="files.length" class="k-section">
      <k-header class="k-section-header">
        Content History
        <k-button-group slot="right">
          <k-button icon="refresh" @click="refresh"/>
        </k-button-group>
      </k-header>

      <k-grid gutter="large">
        <k-column width="1/1">
          <k-input
              type="text"
              :placeholder="$t('search') + '...'"
              v-model="search"
              @input="updateSearch"
              icon="search"
          />
        </k-column>
      </k-grid>

      <k-collection
          v-if="filteredFiles.length"
          :items="items"
          layout="list"
          @action="open"
      />

      <k-empty v-else icon="page" :text="$t('no.files.found')"/>

      <k-loader v-if="isLoading"/>
    </section>

    <section v-if="lockedPages.length" class="k-section">
      <k-header class="k-section-header">
        <k-headline>Locked pages</k-headline>
      </k-header>
      <k-collection :items="lockItems"/>
    </section>
  </k-panel-inside>
</template>

<script>
import {formatDistance} from 'date-fns';

export default {
  props: {
    files: Array,
    lockedPages: {
      type: Array,
      default: []
    },
  },

  data() {
    return {
      isLoading: false,
      search: '',
      filteredFiles: [],
      lockedPages: this.lockedPages
    };
  },

  created() {
    this.filteredFiles = this.files || [];
  },

  computed: {
    items() {
      return this.filteredFiles.map(file => {
        const modifiedDate = new Date(file.modified * 1000);
        const timeAgo = formatDistance(modifiedDate, new Date(), {addSuffix: true});
        const editorName = file.editor?.name || file.editor?.email || 'Unknown';

        return {
          id: file.id,
          text: file.title,
          info: `${editorName} / ${file.modified_formatted} (${timeAgo})`,
          link: file.panel_url,
          icon: 'page',
          options: [{
            icon: 'edit',
            click: () => this.open(file.id)
          }]
        };
      });
    },
    lockItems() {
      const items = []

      this.lockedPages.forEach(lock => {
        items.push({
          text: lock.file,
          info: lock.user + ' / ' + lock.date + ' (' + this.formatRelative(lock.date) + ')',
        })
      })

      return items
    },
  },

  methods: {
    refresh() {
      this.isLoading = true;
      window.location.reload();
    },

    open(id) {
      const file = this.filteredFiles.find(f => f.id === id);
      if (file?.panel_url) {
        window.location.href = '/panel' + file.panel_url;
      }
    },

    updateSearch() {
      if (!this.files) return;

      if (!this.search.length) {
        this.filteredFiles = this.files;
        return;
      }

      const searchLower = this.search.toLowerCase();
      this.filteredFiles = this.files.filter(file =>
          file.title.toLowerCase().includes(searchLower) ||
          file.path.toLowerCase().includes(searchLower)
      );
    },
    formatRelative(date) {
      return formatDistance(new Date(date), new Date(), {
        addSuffix: true
      })
    }
  }
};
</script>
