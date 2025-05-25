<template>
  <k-panel-inside class="k-content-watch-view">
    <section v-if="files.length" class="k-section">
      <k-header class="k-section-header">
        <k-headline>Content Watch</k-headline>
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

      <div v-if="filteredFiles.length" class="k-content-watch-files">
        <div
            v-for="(file, index) in filteredFiles"
            :key="file.id"
            class="k-content-watch-file"
            :class="{'k-content-watch-file-open': expandedFiles.includes(file.id)}"
        >
          <div class="k-content-watch-file-header" @click="toggleFileExpand(file.id)">
            <div class="k-content-watch-file-info">
              <span class="k-content-watch-file-path">
                <strong>{{ file.title }}</strong>
                <br>{{ file.path_short }}
              </span>
              <span class="k-content-watch-file-editor">
                {{ file.editor.name || file.editor.email || 'Unknown' }}<br>
                  {{ formatRelative(file.modified) }}
              </span>
            </div>
            <div class="k-content-watch-file-actions">
              <k-button icon="angle-down" :class="{'k-button-rotated': expandedFiles.includes(file.id)}"/>
              <k-button @click.stop="openFile(file)" icon="edit"/>
            </div>
          </div>

          <div v-if="expandedFiles.includes(file.id)" class="k-content-watch-file-timeline">
            <div v-if="file.history && file.history.length > 0" class="k-timeline-list">
              <div v-for="(entry, entryIndex) in file.history" :key="entryIndex" class="k-timeline-item">
                <div class="k-timeline-item-version">
                  v{{ entry.version }}
                </div>
                <div class="k-timeline-item-time">
                  {{ entry.time_formatted }}
                </div>
                <div class="k-timeline-item-time-rel">
                  {{ formatRelative(entry.time) }}
                </div>
                <span class="k-timeline-item-editor-label">
                    {{ entry.restored_from ? 'restored by' : 'edited by' }}
                  </span>
                <span class="k-timeline-item-editor">
                    {{ entry.editor.name || entry.editor.email || 'Unknown' }}
                  </span>
                <div class="k-timeline-item-actions">
                  <k-button
                      v-if="enableRestore && entry.has_snapshot && entryIndex > 0"
                      @click.stop="confirmRestore(file, entry)"
                      icon="refresh"
                      class="k-restore-button"
                      title="Restore this version"
                  />
                </div>
                <div class="k-timeline-item-line"></div>
              </div>
            </div>
            <k-empty v-else icon="history" text="No history entries found"/>
            <div class="k-timeline-footer">
              <span>Showing changes for the last {{ retentionDays }} days (max {{ retentionCount }})</span>
            </div>
          </div>
        </div>
      </div>

      <k-empty v-else icon="page" :text="$t('no.files.found')"/>

      <k-loader v-if="isLoading"/>
    </section>

    <section v-if="lockedPages.length" class="k-section">
      <k-header class="k-section-header">
        <k-headline>Locked pages</k-headline>
      </k-header>
      <k-collection :items="lockItems" class="k-content-watch-locked"/>
    </section>

    <!-- Confirmation dialog for restore -->
    <k-dialog
        v-if="enableRestore"
        ref="restoreDialog"
        :button="$t('restore')"
        theme="positive"
        icon="refresh"
        @submit="restoreContent"
    >
      <k-text>Are you sure you want to restore this version?</k-text>
      <k-text v-if="restoreTarget">
        <strong>File:</strong> {{ restoreTarget.file?.title }}<br>
        <strong>Version:</strong> {{ restoreTarget.entry?.time_formatted }}
        ({{ formatRelative(restoreTarget.entry?.time) }})
      </k-text>
      <k-text>This will overwrite the current content with this previous version.</k-text>
    </k-dialog>
  </k-panel-inside>
</template>

<script>
import {formatDistance} from 'date-fns';

export default {
  props: {
    files: Array,
    historyEntries: Array,
    retentionDays: {
      type: Number,
      default: 30
    },
    retentionCount: {
      type: Number,
      default: 10
    },
    lockedPages: {
      type: Array,
      default: () => []
    },
    enableRestore: {
      type: Boolean,
      default: false
    }
  },

  data() {
    return {
      isLoading: false,
      search: '',
      filteredFiles: [],
      expandedFiles: [],
      restoreTarget: null
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
      const items = [];

      this.lockedPages.forEach(lock => {
        items.push({
          text: '<span class="k-content-watch-file-path"><strong>' + lock.title + '</strong><br>' + lock.id + '</span>',
          info: lock.user + ' <br> ' + lock.date + ' (' + this.formatRelative(lock.date) + ')',
          options: [{
            icon: 'edit',
            click: () => this.open(lock.id)
          }]
        });
      });

      return items;
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

    openFile(file) {
      if (file?.panel_url) {
        window.location.href = '/panel' + file.panel_url;
      }
    },

    toggleFileExpand(id) {
      const index = this.expandedFiles.indexOf(id);
      if (index === -1) {
        this.expandedFiles.push(id);
      } else {
        this.expandedFiles.splice(index, 1);
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
      if (typeof date === 'string') {
        return formatDistance(new Date(date), new Date(), {
          addSuffix: true
        });
      }
      return formatDistance(new Date(date * 1000), new Date(), {
        addSuffix: true
      });
    },

    confirmRestore(file, entry) {
      if (!this.enableRestore) return;

      this.restoreTarget = {file, entry};
      this.$refs.restoreDialog.open();
    },

    async restoreContent() {
      if (!this.enableRestore || !this.restoreTarget) return;

      const {file, entry} = this.restoreTarget;

      this.isLoading = true;

      try {
        const response = await this.$api.post('/content-watch/restore', {
          dirPath: file.dir_path,
          fileKey: file.uid,
          timestamp: entry.time
        });

        if (response.status === 'success') {
          this.$store.dispatch('notification/success', 'Content restored successfully');
          this.refresh();
        } else {
          this.$store.dispatch('notification/error', response.message || 'Failed to restore content');
        }
      } catch (error) {
        this.$store.dispatch('notification/error', 'Error restoring content: ' + (error.message || 'Unknown error'));
      } finally {
        this.isLoading = false;
        this.restoreTarget = null;
      }
    }
  }
};
</script>

<style>
.k-content-watch-files {
  margin-top: 1rem;
}

.k-content-watch-file {
  border: 1px solid var(--color-border);
  border-radius: 4px;
  margin-bottom: 0.5rem;
  overflow: hidden;
  transition: all 0.3s ease;
}

.k-content-watch-file-open {
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.k-content-watch-file-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 1rem;
  cursor: pointer;
  background-color: var(--color-white);
}

.k-content-watch-file-info {
  display: flex;
  justify-content: space-between;
  width: 100%;
  line-height: 1.2rem;
}

.k-content-watch-file-path {
  font-size: .875rem;
  opacity: 0.7;
  margin-top: 0.25rem;
}

.k-content-watch-file-editor {
  font-size: .875rem;
  margin-top: 0.25rem;
  text-align: right;
  opacity: 0.7;
}

.k-content-watch-file-actions {
  display: flex;
  gap: 0.5rem;
}

.k-button-rotated {
  transform: rotate(180deg);
}

.k-content-watch-file-timeline {
  padding: 0.5rem 1rem;
  background-color: var(--color-light);
  border-top: 1px solid var(--color-border);
}

.k-timeline-list {
  display: grid;
  align-items: center;
  grid-template-columns: repeat(15, minmax(0, 1fr));
  margin: 0;
  padding: 0.75rem 0.5rem;
  font-size: .875rem;
  opacity: 0.7;
}

.k-timeline-item {
  display: contents;
}

.k-timeline-item > :not(:last-child) {
  padding: 0.6rem 0.5rem;
}

.k-timeline-item:last-child {
  border-bottom: none;
}

.k-timeline-item-version {
  font-size: 0.8rem;
  grid-column: span 1;
}

.k-timeline-item-time {
  font-size: 0.8rem;
  grid-column: span 3;
}

.k-timeline-item-time-rel {
  font-size: 0.7rem;
  grid-column: span 2;
}

.k-timeline-item-editor-label {
  font-size: 0.7rem;
  text-align: right;
  grid-column: span 3;
}

.k-timeline-item-editor {
  text-align: right;
  grid-column: span 5;
}

.k-timeline-item-actions {
  text-align: right;
  display: inline-block;
  font-size: 0.8rem;
  grid-column: span 1;
}
.k-timeline-item-line {
  grid-column: span 15;
  border-bottom: 1px solid var(--color-border);
}

.k-restore-button {
  height: auto !important;
  line-height: 1 !important;
  color: var(--color-positive) !important;
}

.k-timeline-footer {
  padding: 0.5rem 1rem 0;
  font-size: 0.75rem;
  color: var(--color-gray-800);
  text-align: right;
}

.k-content-watch-locked .k-item {
  padding: 0rem 0.5rem;
  height: unset;
}

.k-content-watch-locked .k-item-content {
  line-height: 1.2rem;
}

.k-content-watch-locked .k-item-content .k-item-info {
  text-align: right;
}

@media (prefers-color-scheme: dark) {
  .k-content-watch-file {
    border-color: var(--color-gray-300);
  }

  .k-content-watch-file-header {
    background-color: var(--color-gray-100);
  }

  .k-content-watch-file-timeline {
    background-color: var(--color-gray-100);
    border-color: var(--color-gray-300);
  }

  .k-timeline-item {
    border-color: var(--color-gray-300);
  }

  .k-timeline-footer {
    border-color: var(--color-gray-300);
    color: var(--color-gray-300);
  }
}
</style>
