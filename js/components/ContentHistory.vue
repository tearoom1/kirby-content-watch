<template>
  <k-panel-inside class="k-content-history-view">
    <k-header>
      {{ $t('content-history') }}
      <k-button-group slot="right">
        <k-button icon="refresh" @click="refresh" />
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
    
    <k-empty v-else icon="page" :text="$t('no.files.found')" />
    
    <k-loader v-if="isLoading" />
  </k-panel-inside>
</template>

<script>
import { formatDistance } from 'date-fns';

export default {
  props: {
    files: Array
  },
  
  data() {
    return {
      isLoading: false,
      search: '',
      filteredFiles: []
    };
  },
  
  created() {
    this.filteredFiles = this.files || [];
  },
  
  computed: {
    items() {
      return this.filteredFiles.map(file => {
        const modifiedDate = new Date(file.modified * 1000);
        const timeAgo = formatDistance(modifiedDate, new Date(), { addSuffix: true });
        
        return {
          id: file.id,
          text: file.title,
          info: `${this.$t('modified')}: ${file.modified_formatted} (${timeAgo})`,
          link: file.panel_url,
          icon: 'page',
          options: [{
            text: this.$t('edit'),
            icon: 'edit',
            click: () => this.open(file.id)
          }]
        };
      });
    }
  },
  
  methods: {
    refresh() {
      this.isLoading = true;
      window.location.reload();
    },
    
    open(id) {
      const file = this.filteredFiles.find(f => f.id === id);
      if (file?.panel_url) {
        window.location.href = file.panel_url;
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
    }
  }
};
</script>

<style>
.k-content-history-view {
  /* Custom styles here if needed */
}
</style>
