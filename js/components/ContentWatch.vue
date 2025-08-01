<template>
  <k-panel-inside class="k-content-watch-view">
    <k-header class="k-section-header">
      <!-- Tab navigation -->
      <div class="k-content-watch-tabs">
        <k-button-group>
          <k-button
              :class="{'k-button-active': tab === 'content'}"
              @click="tab = 'content'"
              icon="edit-line">
            Content Changes
          </k-button>
          <k-button
              :class="{'k-button-active': tab === 'locked'}"
              @click="tab = 'locked'"
              icon="lock">
            Locked Pages
          </k-button>
        </k-button-group>
      </div>
    </k-header>

    <!-- Content Watch Tab -->
    <section v-if="tab === 'content'" class="k-content-watch-section">
      <k-grid v-if="files.length">
        <k-column width="1/2">
          <k-input
              class="k-content-watch-search"
              type="text"
              :placeholder="$t('search') + '...'"
              v-model="search"
              @input="filterFiles"
              icon="search"
          />
        </k-column>
        <k-column width="1/2" class="k-content-watch-buttons">
          <k-button-group>
            <k-button :class="{'k-button-active': showOnlyPages}" @click="toggleShowOnlyPages" icon="page">Pages only
            </k-button>
            <k-button :class="{'k-button-active': !showOnlyPages}" @click="toggleShowAll" icon="file-document">All
              files
            </k-button>
            <k-button icon="refresh" @click="refresh"/>
          </k-button-group>
        </k-column>
      </k-grid>

      <div v-if="files.length && paginatedFiles.length" class="k-content-watch-files">
        <div
            v-for="(file, index) in paginatedFiles"
            :key="file.id"
            class="k-content-watch-file"
            :class="{'k-content-watch-file-open': expandedFiles.includes(file.id)}"
        >
          <div v-if="layoutStyle === 'default'" class="k-content-watch-file-header" @click="toggleFileExpand(file.id)">
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

          <div v-if="layoutStyle === 'compact'" class="k-content-watch-file-header k-content-watch-file-header-compact"
               @click="toggleFileExpand(file.id)">
            <div class="k-content-watch-file-info">
              <span class="k-content-watch-file-path">
                <strong>{{ file.title }}</strong> ~ {{ file.path_short }}
              </span>
              <span class="k-content-watch-file-editor">
                 {{ formatRelative(file.modified) }} by <strong>{{
                  file.editor.name || file.editor.email || 'Unknown'
                }}</strong>
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
                <div class="k-timeline-item-language">
                  {{ entry.language }}
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
                    v-if="enableDiff && entry.has_snapshot"
                    @click.stop="viewDiff(file, entry, entryIndex)"
                    icon="split"
                    class="k-diff-button"
                    title="View changes"
                  />
                  <k-button
                      v-if="enableRestore && entry.has_snapshot && entryIndex > 0"
                      @click.stop="confirmRestore(file, entry)"
                      icon="undo"
                      class="k-restore-button"
                      title="Restore this version"
                  />
                  <k-button
                      v-if="enableRestore && entryIndex === 0"
                      icon="check"
                      style="cursor: default"
                      class="k-restore-button"
                      title="Current version"
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

      <!-- Pagination Controls -->
      <div v-if="files.length && filteredFiles.length" class="k-content-watch-pagination">
        <div class="k-content-watch-pagination-info">
          Showing {{ paginationStart + 1 }} - {{ Math.min(paginationStart + pageSize, filteredFiles.length) }} of
          {{ filteredFiles.length }} items
        </div>
        <div class="k-content-watch-pagination-controls">
          <k-button-group>
            <k-button @click.stop.prevent="prevPage" :disabled="currentPage <= 1" icon="angle-left">Previous</k-button>
            <span class="k-content-watch-pagination-page-info">{{ currentPage }} / {{ totalPages }}</span>
            <k-button @click.stop.prevent="nextPage" :disabled="currentPage >= totalPages" icon="angle-right"
                      icon-after>Next
            </k-button>
          </k-button-group>
        </div>
        <div class="k-content-watch-pagination-pagesize">
          <k-select-field
              :value="pageSize"
              :options="pageSizeOptions"
              @input="changePageSize"/>
        </div>
      </div>

      <k-empty v-if="files.length && !filteredFiles.length" icon="page" :text="$t('no.files.found')"/>
      <k-empty v-if="!files.length" icon="page" text="No content change data available"/>

      <k-loader v-if="isLoading"/>
    </section>

    <!-- Locked Pages Tab -->
    <section v-if="tab === 'locked'" class="k-content-watch-section">
      <k-grid v-if="lockedPages.length">
        <k-column width="1/2">
          <k-input
              class="k-content-watch-search"
              type="text"
              :placeholder="$t('search') + '...'"
              v-model="lockedSearch"
              @input="filterLockedPages"
              icon="search"
          />
        </k-column>
        <k-column width="1/2" class="k-content-watch-buttons">
          <k-button-group>
            <k-button icon="refresh" @click="refresh"/>
          </k-button-group>
        </k-column>
      </k-grid>
      <k-collection v-if="filteredLockedPages.length" :items="lockItems" class="k-content-watch-locked"/>
      <k-empty v-else icon="lock" text="No locked pages found"/>
    </section>

    <!-- Confirmation dialog for restore -->
    <k-dialog
        class="k-content-watch-restore-dialog"
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

    <!-- Diff dialog for comparing versions -->
    <k-dialog
        class="k-content-watch-diff-dialog"
        ref="diffDialog"
        size="large"
        :button="$t('close')"
        cancelButton=""
        submitButton="Close"
        @close="closeDiff"
    >
      <div v-if="diffTarget" class="k-content-watch-diff-header">
        <div>
          <div class="k-content-watch-diff-file-info">
            Page:<strong>{{ diffTarget.file?.title }}</strong>
            <span class="k-content-watch-diff-path"> ~ {{ diffTarget.file?.path_short }}</span>
          </div>

          <div class="k-content-watch-diff-current-version">
            <strong>Current version:</strong> v{{ diffTarget.entry?.version }} / {{ diffTarget.entry?.language }} / {{ diffTarget.entry?.time_formatted }}
            <span class="k-content-watch-diff-editor">
              ({{ diffTarget.entry?.editor.name || diffTarget.entry?.editor.email || 'Unknown' }})
            </span>
          </div>
        </div>

        <div class="k-content-watch-diff-version-select">
          <div class="k-content-watch-diff-compare-version">
            <strong>Compare with:</strong>
            <span v-if="diffCompareVersionId !== null && diffTarget" class="k-content-watch-diff-version-number">
              v{{ getVersionNumber(diffCompareVersionId) }}
            </span>
          </div>

          <k-select-field
              :options="diffVersionOptions"
              @input="changeCompareVersion"
              :value="diffCompareVersionId"
              placeholder="Select version to compare"
          />
        </div>
      </div>

      <k-loader v-if="isDiffLoading" />

      <div v-else-if="diffContent" class="k-content-watch-diff-content">
        <pre class="k-content-watch-diff-code">{{ diffContent }}</pre>
      </div>

      <k-empty v-else icon="document" text="No diff available" />
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
    },
    enableDiff: {
      type: Boolean,
      default: false
    },
    defaultPageSize: {
      type: Number,
      default: 10
    },
    layoutStyle: {
      type: Text,
      default: 'default'
    }
  },

  data() {
    return {
      isLoading: false,
      search: '',
      lockedSearch: '',
      filteredFiles: [],
      filteredLockedPages: [],
      expandedFiles: [],
      restoreTarget: null,
      showOnlyPages: true,
      currentPage: 1,
      layoutStyle: this.layoutStyle,
      pageSize: this.defaultPageSize,
      pageSizeOptions: [
        {text: '10 per page', value: 10},
        {text: '20 per page', value: 20},
        {text: '50 per page', value: 50}
      ],
      tab: 'content', // Default tab is content
      diffTarget: null,
      diffVersionOptions: [],
      diffCompareVersionId: null,
      diffContent: null,
      isDiffLoading: false
    };
  },

  created() {
    this.filteredFiles = this.files || [];
    this.filteredLockedPages = this.lockedPages || [];
    this.filterFiles();
    this.filterLockedPages();
  },

  computed: {
    totalPages() {
      return Math.max(1, Math.ceil(this.filteredFiles.length / (this.pageSize || 10)));
    },

    paginationStart() {
      return (this.currentPage - 1) * (this.pageSize || 10);
    },

    paginatedFiles() {
      const start = this.paginationStart;
      const end = start + (this.pageSize || 10);
      // Ensure we're only returning the current page's files
      return this.filteredFiles.slice(start, end);
    },

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

      this.filteredLockedPages.forEach(lock => {
        items.push({
          text: '<span class="k-content-watch-file-path"><strong>' + lock.title + '</strong><br>' + lock.id + '</span>',
          info: lock.user + ' <br> ' + lock.date + ' (' + this.formatRelative(lock.time) + ')',
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
      this.openFile(file);
    },

    openFile(file) {
      if (file?.panel_url) {
        // window.open(file.panel_url, '_blank').focus();
        window.location.href = file.panel_url;
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

    filterFiles() {
      const searchLower = this.search.toLowerCase();
      let filtered = this.files;

      // First apply page/file filter
      if (this.showOnlyPages) {
        filtered = filtered.filter(file => file.panel_url && file.panel_url.indexOf('/files/') === -1 && !file.is_media_file);
      }

      // Then apply search filter
      this.filteredFiles = filtered.filter(
          file =>
              file.title.toLowerCase().includes(searchLower) ||
              file.path.toLowerCase().includes(searchLower)
      );

      // Reset to first page when filtering changes
      this.currentPage = 1;
    },

    filterLockedPages() {
      const searchLower = this.lockedSearch.toLowerCase();

      // Then apply search filter
      this.filteredLockedPages = this.lockedPages.filter(
          page =>
              page.title.toLowerCase().includes(searchLower) ||
              page.path.toLowerCase().includes(searchLower)
      );
    },

    toggleShowOnlyPages() {
      this.showOnlyPages = true;
      this.filterFiles();
    },

    toggleShowAll() {
      this.showOnlyPages = false;
      this.filterFiles();
    },

    prevPage() {
      if (this.currentPage > 1) {
        this.currentPage--;
        // window.scrollTo(0, 0);
      }
      return false;
    },

    nextPage() {
      if (this.currentPage < this.totalPages) {
        this.currentPage++;
        // window.scrollTo(0, 0);
      }
      return false;
    },

    changePageSize(size) {
      // Normalize the value - it could be coming as a string or as an object
      let newSize;

      if (typeof size === 'object' && size !== null) {
        // It might be the option object itself
        newSize = size.value || 10;
      } else {
        // Otherwise parse it as a number
        newSize = parseInt(size, 10) || 10;
      }

      // Update page size
      this.pageSize = newSize;

      // Always reset to first page when changing page size
      this.currentPage = 1;
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
          // this.$store.dispatch('notification/success', 'Content restored successfully');
          this.refresh();
        } else {
          window.panel.notification.error(response.message || 'Failed to restore content');
        }
      } catch (error) {
        window.panel.notification.error('Error restoring content: ' + (error.message || 'Unknown error'));
      } finally {
        this.isLoading = false;
        this.restoreTarget = null;
      }
    },

    viewDiff(file, entry, entryIndex) {
      this.diffTarget = {file, entry};

      // Create version options with version numbers
      this.diffVersionOptions = file.history
        .filter((_, i) => i !== entryIndex) // Remove current version from options
        .map((entry, i) => ({
          text: 'v' + entry.version + ' / ' + entry.language + ' / ' +
            this.formatRelative(entry.time) + ' (' + (entry.editor.name || entry.editor.email || 'Unknown') + ')',
          value: i < entryIndex ? i : i + 1 // Adjust index based on filtering
        }));

      // Always default to comparing with the previous version
      const compareIndex = entryIndex < this.diffVersionOptions.length ? entryIndex + 1 : null;
      console.log(entryIndex + ' - ' + compareIndex);
      this.diffCompareVersionId = compareIndex;

      this.$refs.diffDialog.open();

      // Load the initial diff if we have a valid compare version
      this.loadDiff(compareIndex);
    },

    closeDiff() {
      this.diffTarget = null;
      this.diffVersionOptions = [];
      this.diffCompareVersionId = null;
      this.diffContent = null;
    },

    changeCompareVersion(versionId) {
      this.diffCompareVersionId = versionId;
      this.loadDiff(versionId);
    },

    loadDiff(versionId) {

      if (versionId === null) {
        this.diffContent = null;
        return;
      }

      this.isDiffLoading = true;
      this.diffContent = null;

      const file = this.diffTarget.file;
      const entry = this.diffTarget.entry;
      const compareEntry = file.history[versionId];

      this.$api.post('/content-watch/diff', {
        dirPath: file.dir_path,
        fileKey: file.uid,
        fromTimestamp: compareEntry.time,
        toTimestamp: entry.time
      })
      .then(response => {
        this.diffContent = response.diff;
      })
      .catch(error => {
        window.panel.notification.error('Error loading diff: ' + (error.message || 'Unknown error'));
      })
      .finally(() => {
        this.isDiffLoading = false;
      });
    },

    getVersionNumber(versionId) {
      const file = this.diffTarget.file;
      const entry = file.history[versionId];
      return entry.version;
    }
  }
};
</script>

<style>
.k-content-watch-view {
  .k-content-watch-section, .k-content-watch-locked {
    margin-top: 1rem;
  }

  .k-content-watch-file {
    border: 1px solid var(--color-border);
    border-radius: 4px;
    margin-bottom: 0.5rem;
    overflow: hidden;
    transition: all 0.3s ease;
  }

  .k-content-watch-buttons .k-button-group {
    justify-content: flex-end;
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
    background-color: var(--item-color-back);
  }

  .k-content-watch-file-header-compact {
    padding: 0.2rem 0.6rem;
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

  .k-button-group .k-button {
    margin-inline: 0.5rem;
  }

  .k-content-watch-file-timeline {
    padding: 0.5rem 1rem;
    border-top: 1px solid var(--color-border);
  }

  .k-timeline-list {
    --colums: 20;
    display: grid;
    align-items: center;
    grid-template-columns: repeat(var(--colums), minmax(0, 1fr));
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

  .k-timeline-item-language {
    font-size: 0.8rem;
    grid-column: span 1;
  }

  .k-timeline-item-time {
    font-size: 0.8rem;
    grid-column: span 4;
  }

  .k-timeline-item-time-rel {
    font-size: 0.7rem;
    grid-column: span 4;
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
    display: inline-flex;
    justify-content: end;
    gap: 0.5rem;
    font-size: 0.8rem;
    grid-column: span 2;
  }

  .k-timeline-item-line {
    grid-column: span var(--colums);
    border-bottom: 1px solid var(--color-border);
  }

  .k-restore-button {
    height: auto !important;
    line-height: 1 !important;
    color: var(--color-positive) !important;
  }

  .k-diff-button {
    height: auto !important;
    line-height: 1 !important;
    color: var(--color-orange-400) !important;
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

  .k-button-active {
    background-color: var(--color-gray-200);
    color: var(--color-black);
    font-weight: 500;
  }

  /* Custom tab styles */

  .k-content-watch-view .k-button-active {
    border-bottom: 2px solid var(--color-gray-500);
  }

  .k-content-watch-files {
    margin-top: 1rem;
  }

  /* Pagination styles */

  .k-content-watch-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
    padding: 0.5rem 0;
    border-top: 1px solid var(--color-border);
  }

  .k-content-watch-pagination-info {
    font-size: 0.875rem;
    color: var(--color-gray-600);
  }

  .k-content-watch-pagination-controls {
    display: flex;
    align-items: center;
  }

  .k-content-watch-pagination-page-info {
    display: inline-block;
    padding: 0 1rem;
    font-size: 0.875rem;
    font-weight: 500;
  }

  .k-content-watch-pagination-pagesize {
    width: 150px;
  }

  .k-content-watch-diff-dialog .k-dialog-body {
    display: grid;
    gap: 0.5rem;
  }

  .k-content-watch-diff-header {
    padding: 1rem;
    border-bottom: 1px solid var(--color-border);
  }

  .k-content-watch-diff-file-info {
    font-size: 1rem;
    font-weight: 500;
  }

  .k-content-watch-diff-path {
    font-size: 0.875rem;
    opacity: 0.7;
  }

  .k-content-watch-diff-current-version {
    font-size: 0.875rem;
    margin-top: 0.25rem;
  }

  .k-content-watch-diff-editor {
    font-size: 0.875rem;
    opacity: 0.7;
  }

  .k-content-watch-diff-version-select {
    margin-top: 1rem;
  }

  .k-content-watch-diff-compare-version {
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
  }

  .k-content-watch-diff-content {
    padding: 1rem;
  }

  .k-content-watch-diff-code {
    font-size: 0.875rem;
    font-family: monospace;
    white-space: pre-wrap;
  }
  .k-content-watch-diff-dialog {
    .k-button-group.k-dialog-buttons {
      grid-template-columns: 1fr;
    }
    .k-dialog-button-cancel {
      display: none;
    }
  }
}
</style>
