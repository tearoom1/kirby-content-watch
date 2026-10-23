<template>
  <k-panel-inside class="k-content-watch-view">

    <!-- native view header: title, header buttons, tabs -->
    <k-header class="k-content-watch-header">
      Content Watch
      <template #buttons>
        <k-button
          class="k-content-watch-sponsor-button"
          icon="heart"
          size="sm"
          variant="filled"
          responsive="true"
          link="https://www.tearoom.one/en/kirby-plugins/support"
          target="_blank"
          :text="$t('tearoom1.content-watch.support')"
        />
      </template>
    </k-header>

    <!-- Kirby renders no tabs when there is only one -->
    <k-tabs :tab="tab" :tabs="tabs"/>

    <!-- Content Watch Tab -->
    <section v-if="tab === 'content'" class="k-content-watch-section">
      <div v-if="files.length" class="k-content-watch-toolbar">
          <k-input
            class="k-content-watch-search"
            type="text"
            :placeholder="$t('search') + '...'"
            v-model="search"
            @input="filterFiles"
            icon="search"
          />
          <k-button-group class="k-content-watch-buttons">
            <k-button
              :class="{'k-button-active': view === 'page'}"
              :variant="view === 'page' ? 'filled' : null"
              icon="list-bullet"
              :title="$t('tearoom1.content-watch.view.page')"
              :aria-label="$t('tearoom1.content-watch.view.page')"
              :aria-pressed="view === 'page' ? 'true' : 'false'"
              @click="setView('page')"
            />
            <k-button
              class="k-content-watch-group-end"
              :class="{'k-button-active': view === 'timeline'}"
              :variant="view === 'timeline' ? 'filled' : null"
              icon="clock"
              :title="$t('tearoom1.content-watch.view.timeline')"
              :aria-label="$t('tearoom1.content-watch.view.timeline')"
              :aria-pressed="view === 'timeline' ? 'true' : 'false'"
              @click="setView('timeline')"
            />
            <k-button
              :class="{'k-button-active': showFilters || activeFilterCount}"
              :variant="(showFilters || activeFilterCount) ? 'filled' : null"
              icon="filter"
              :aria-expanded="showFilters ? 'true' : 'false'"
              @click="toggleFilters"
            >{{ $t('tearoom1.content-watch.filters') }}<template v-if="activeFilterCount"> ({{ activeFilterCount }})</template></k-button>
            <k-button
              icon="download"
              :title="$t('tearoom1.content-watch.export')"
              :aria-label="$t('tearoom1.content-watch.export')"
              :disabled="!filteredFiles.length"
              @click="exportCsv"
            />
            <k-button
              icon="refresh"
              :title="$t('tearoom1.content-watch.reload')"
              :aria-label="$t('tearoom1.content-watch.reload')"
              @click="refresh"
            />
          </k-button-group>
      </div>

      <div v-if="files.length && showFilters" class="k-content-watch-filters">
        <k-select-field
          :label="$t('tearoom1.content-watch.filter.show')"
          :value="showOnlyPages ? 'pages' : 'all'"
          :options="showFilterOptions"
          :empty="false"
          :required="true"
          @input="changeShowFilter"
        />
        <k-select-field
          :label="$t('tearoom1.content-watch.filter.author')"
          :value="selectedAuthor"
          :options="authorFilterOptions"
          @input="changeAuthorFilter"
        />
        <k-select-field
          label="Status"
          :value="selectedStatus"
          :options="statusFilterOptions"
          @input="changeStatusFilter"
        />
        <k-select-field
          label="Template"
          :value="selectedTemplate"
          :options="templateFilterOptions"
          @input="changeTemplateFilter"
        />
        <k-select-field
          :label="$t('tearoom1.content-watch.filter.period')"
          :value="selectedPeriod"
          :options="periodFilterOptions"
          @input="changePeriodFilter"
        />
        <k-select-field
          v-if="languageFilterOptions.length"
          :label="$t('tearoom1.content-watch.filter.language')"
          :value="selectedLanguage"
          :options="languageFilterOptions"
          @input="changeLanguageFilter"
        />
        <div v-if="activeFilterCount" class="k-content-watch-filters-reset">
          <k-button
            icon="cancel"
            @click="resetFilters"
          >{{ $t('tearoom1.content-watch.filter.reset') }}</k-button>
        </div>
      </div>

      <div v-if="view === 'page' && files.length && paginatedFiles.length" class="k-content-watch-files">
        <div
          v-for="(file, index) in paginatedFiles"
          :key="file.dir_path + '/' + file.uid"
          class="k-content-watch-file"
          :class="{'k-content-watch-file-open': expandedFiles.includes(file.id)}"
        >
          <div class="k-content-watch-file-header" @click="toggleFileExpand(file.id)">
            <div class="k-content-watch-file-info">
              <span class="k-content-watch-file-path">
                <strong class="k-content-watch-file-title k-content-watch-file-title-inline">
                  <k-icon
                    v-if="file.page_status"
                    class="k-content-watch-status-icon"
                    :class="'k-content-watch-status-icon-' + file.page_status"
                    :type="'status-' + file.page_status"
                    :title="file.page_status"
                  />
                  {{ file.title }}
                </strong> ~ {{ file.path_short }}
              </span>
              <span class="k-content-watch-file-editor">
                 {{ formatRelative(file.modified) }} by <strong :title="editorHelp(file.editor)">{{ editorLabel(file.editor) }}</strong>
              </span>
            </div>
            <div class="k-content-watch-file-actions">
              <k-button icon="angle-down"
                        :title="expandedFiles.includes(file.id) ? 'Hide history' : 'Show history'"
                        :class="{'k-button-rotated': expandedFiles.includes(file.id), 'k-button-disabled': !file.history || file.history.length === 0}"/>
              <k-button @click.stop="openFile(file)" icon="edit" title="Edit in panel"/>
            </div>
          </div>

          <div v-if="expandedFiles.includes(file.id)" class="k-content-watch-file-timeline">
            <div
              v-if="file.history && file.history.length > 0"
              class="k-timeline-list"
            >
              <div
                v-for="(entry, entryIndex) in file.history"
                :key="entry.entry_id || entryIndex"
                class="k-timeline-item"
                :class="{'k-timeline-item-current': entryIndex === 0}"
              >
                <span class="k-timeline-item-version">v{{ entry.version }}</span>
                <span v-if="entry.language" class="k-timeline-item-language">{{ entry.language }}</span>
                <span
                  class="k-timeline-item-tag"
                  :class="'k-timeline-item-tag-' + entryAction(entry)"
                >{{ entryAction(entry) }}</span>
                <span class="k-timeline-item-editor" :title="editorHelp(entry.editor)">{{ entryEditor(entry) }}</span>
                <span class="k-timeline-item-when">
                  {{ formatRelative(entry.time) }}
                  <span class="k-timeline-item-when-absolute">{{ formatAbsolute(entry.time) }}</span>
                </span>
                <div class="k-timeline-item-actions">
                  <!-- fixed slots: diff, restore; empty slots keep their width -->
                  <k-button
                    v-if="enableDiff"
                    v-bind="slotAttrs(canDiff(file, entry))"
                    icon="split"
                    class="k-diff-button"
                    :class="{'k-cw-diff-same': isSame(entry, entryIndex)}"
                    :title="isSame(entry, entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                    :aria-label="isSame(entry, entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                    @click.stop="viewDiff(file, entry, entryIndex)"
                  />
                  <k-button
                    v-if="enableRestore && entryIndex === 0"
                    icon="check"
                    style="cursor: default"
                    class="k-restore-button"
                    title="Current version"
                  />
                  <k-button
                    v-else-if="enableRestore"
                    v-bind="slotAttrs(canRestoreEntry(entry, entryIndex))"
                    icon="undo"
                    class="k-restore-button"
                    title="Restore this version"
                    @click.stop="confirmRestore(file, entry)"
                  />
                </div>
              </div>
            </div>
            <k-empty v-else icon="clock" text="No history entries found"/>
            <div class="k-timeline-footer">
              <span>Showing changes for the last {{ retentionDays }} days (max {{ retentionCount }} per language)</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Timeline: all changes, newest first, grouped by day -->
      <div v-if="view === 'timeline' && files.length && timelineDays.length" class="k-content-watch-timeline">
        <section v-for="day in timelineDays" :key="day.day" class="k-content-watch-timeline-day">
          <h3 class="k-content-watch-timeline-day-label">{{ dayLabel(day.day) }}</h3>
          <div class="k-content-watch-files">
            <template v-for="item in day.items">
              <div
                v-if="item.type === 'outside'"
                :key="'outside-' + item.time + '-' + item.rows.length"
                class="k-content-watch-file"
              >
                <div class="k-content-watch-file-header k-content-watch-timeline-row k-content-watch-timeline-group" @click="toggleOutsideGroup(item)">
                  <span class="k-content-watch-timeline-time">{{ formatTime(item.time) }}</span>
                  <span class="k-content-watch-timeline-main k-content-watch-timeline-main-wide">
                    <strong :title="$t('tearoom1.content-watch.editor.outside.help')">{{ $t('tearoom1.content-watch.editor.outside') }}</strong>
                    · {{ outsideGroupLabel(item) }}
                  </span>
                  <!-- in the actions column, right edge on the restore slot -->
                  <span class="k-timeline-item-actions">
                    <k-button
                      icon="angle-down"
                      :title="expandedGroups.includes(groupKey(item)) ? 'Hide' : 'Show'"
                      :class="{'k-button-rotated': expandedGroups.includes(groupKey(item))}"
                    />
                  </span>
                </div>
                <div v-if="expandedGroups.includes(groupKey(item))" class="k-content-watch-file-timeline">
                  <div class="k-timeline-list">
                    <div
                      v-for="row in item.rows"
                      :key="row.key"
                      class="k-timeline-item k-content-watch-timeline-row"
                    >
                      <span class="k-content-watch-timeline-time">{{ formatTime(row.time) }}</span>
                      <span class="k-content-watch-timeline-main">
                        <a :href="row.file.panel_url" class="k-content-watch-timeline-title" @click.stop>{{ row.file.title }}</a>
                        <span class="k-content-watch-timeline-path">{{ row.file.path_short }}</span>
                      </span>
                      <span class="k-content-watch-timeline-cell"><span v-if="row.entry?.language" class="k-timeline-item-language">{{ row.entry.language }}</span></span>
                      <span
                        class="k-timeline-item-tag"
                        :class="'k-timeline-item-tag-' + entryAction(row.entry)"
                      >{{ entryAction(row.entry) }}</span>
                      <span class="k-content-watch-timeline-editor"></span>
                      <span class="k-timeline-item-actions">
                        <k-button
                          v-if="enableDiff"
                          v-bind="slotAttrs(canDiff(row.file, row.entry))"
                          icon="split"
                          class="k-diff-button"
                          :class="{'k-cw-diff-same': isSame(row.entry, row.entryIndex)}"
                          :title="isSame(row.entry, row.entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                          :aria-label="isSame(row.entry, row.entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                          @click.stop="viewDiff(row.file, row.entry, row.entryIndex)"
                        />
                        <k-button
                          v-if="enableRestore"
                          v-bind="slotAttrs(canRestoreEntry(row.entry, row.entryIndex))"
                          icon="undo"
                          class="k-restore-button"
                          title="Restore this version"
                          @click.stop="confirmRestore(row.file, row.entry)"
                        />
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div v-else :key="item.row.key" class="k-content-watch-file">
                <div class="k-content-watch-file-header k-content-watch-timeline-row">
                  <span class="k-content-watch-timeline-time">{{ formatTime(item.row.time) }}</span>
                  <span class="k-content-watch-timeline-main">
                    <a :href="item.row.file.panel_url" class="k-content-watch-timeline-title">{{ item.row.file.title }}</a>
                    <span class="k-content-watch-timeline-path">{{ item.row.file.path_short }}</span>
                  </span>
                  <span class="k-content-watch-timeline-cell"><span v-if="item.row.entry?.language" class="k-timeline-item-language">{{ item.row.entry.language }}</span></span>
                  <span
                    class="k-timeline-item-tag"
                    :class="'k-timeline-item-tag-' + entryAction(item.row.entry)"
                  >{{ entryAction(item.row.entry) }}</span>
                  <span class="k-content-watch-timeline-editor" :title="editorHelp(item.row.editor) || editorLabel(item.row.editor)">{{ editorLabel(item.row.editor) }}</span>
                  <span class="k-timeline-item-actions">
                    <k-button
                      v-if="enableDiff"
                      v-bind="slotAttrs(canDiff(item.row.file, item.row.entry))"
                      icon="split"
                      class="k-diff-button"
                      :class="{'k-cw-diff-same': isSame(item.row.entry, item.row.entryIndex)}"
                      :title="isSame(item.row.entry, item.row.entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                      :aria-label="isSame(item.row.entry, item.row.entryIndex) ? $t('tearoom1.content-watch.equal') : 'View changes'"
                      @click.stop="viewDiff(item.row.file, item.row.entry, item.row.entryIndex)"
                    />
                    <k-button
                      v-if="enableRestore"
                      v-bind="slotAttrs(canRestoreEntry(item.row.entry, item.row.entryIndex))"
                      icon="undo"
                      class="k-restore-button"
                      title="Restore this version"
                      @click.stop="confirmRestore(item.row.file, item.row.entry)"
                    />
                  </span>
                </div>
              </div>
            </template>
          </div>
        </section>
      </div>

      <!-- Pagination Controls -->
      <div v-if="files.length && listLength" class="k-content-watch-pagination">
        <div class="k-content-watch-pagination-info">
          Showing {{ paginationStart + 1 }} - {{ Math.min(paginationStart + pageSize, listLength) }} of
          {{ listLength }} items
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
            :required="true"
            @input="changePageSize"/>
        </div>
      </div>

      <k-empty v-if="files.length && !listLength" icon="page" :text="view === 'timeline' ? $t('tearoom1.content-watch.timeline.empty') : $t('no.files.found')"/>
      <k-empty v-if="!files.length" icon="page" text="No content change data available"/>

      <k-loader v-if="isLoading"/>
    </section>

    <!-- Locked Pages Tab -->
    <section v-if="enableLockedPages && tab === 'locked'" class="k-content-watch-section">
      <div v-if="lockedPages.length" class="k-content-watch-toolbar">
          <k-input
            class="k-content-watch-search"
            type="text"
            :placeholder="$t('search') + '...'"
            v-model="lockedSearch"
            @input="filterLockedPages"
            icon="search"
          />
          <k-button-group class="k-content-watch-buttons">
            <k-button :class="{'k-button-active': lockedShowOnlyPages}" :variant="(lockedShowOnlyPages) ? 'filled' : null" @click="toggleLockedShowOnlyPages" icon="page">
              Pages only
            </k-button>
            <k-button :class="{'k-button-active': !lockedShowOnlyPages}" :variant="(!lockedShowOnlyPages) ? 'filled' : null" @click="toggleLockedShowAll"
                      icon="file-document">All
              files
            </k-button>
            <k-button icon="refresh" title="Reload" @click="refresh"/>
          </k-button-group>
      </div>

      <div v-if="filteredLockedPages.length" class="k-content-watch-files k-content-watch-locked">
        <div
          v-for="lock in filteredLockedPages"
          :key="lock.id + '-' + lock.time"
          class="k-content-watch-file"
        >
          <div class="k-content-watch-file-header" @click="openLockedPage(lock)">
            <div class="k-content-watch-file-info">
              <span class="k-content-watch-file-path">
                <strong class="k-content-watch-file-title k-content-watch-file-title-inline">
                  <span
                    v-if="lock.page_status"
                    class="k-content-watch-status-glyph"
                    :class="'k-content-watch-status-glyph-' + lock.page_status"
                    :title="lock.page_status"
                  ></span>
                  {{ lock.title }}
                </strong> ~ {{ lock.id }}
              </span>
              <span class="k-content-watch-file-editor">
                {{ formatRelative(lock.time) }} by <strong>{{ lock.user || 'Unknown' }}</strong>
              </span>
            </div>
            <div class="k-content-watch-file-actions">
              <k-button @click.stop="openLockedPage(lock)" icon="edit" title="Edit in panel"/>
            </div>
          </div>
        </div>
      </div>

      <k-empty v-else icon="lock" text="No locked pages found"/>
    </section>

    <!-- Diff dialog for comparing versions -->
    <k-dialog
      class="k-content-watch-diff-dialog"
      ref="diffDialog"
      size="huge"
      @close="closeDiff"
      @submit="$refs.diffDialog.close()"
    >
      <div v-if="diffTarget" class="k-content-watch-diff-header">
        <div class="k-content-watch-diff-file-info">
          <strong>{{ diffTarget.file?.title }}</strong>
          <span class="k-content-watch-diff-path"> ~ {{ diffTarget.file?.path_short }}</span>
        </div>

        <div class="k-content-watch-diff-compare-versions">
          <k-select-field
            :label="$t('tearoom1.content-watch.diff.version')"
            :options="diffFromOptions"
            :value="diffFromVersionId"
            :empty="false"
            @input="changeFromVersion"
          />
          <k-select-field
            :label="$t('tearoom1.content-watch.diff.compare')"
            :options="diffToOptions"
            :value="diffToVersionId"
            :empty="false"
            @input="changeToVersion"
          />
        </div>

        <p v-if="diffSummaryText" class="k-content-watch-diff-summary">{{ diffSummaryText }}</p>
      </div>

      <k-loader v-if="isDiffLoading"/>

      <div v-else-if="diffContent" class="k-content-watch-diff-content"
      >
        <div v-html="diffContent"></div>
      </div>

      <k-empty v-else icon="document" text="No diff available"/>

      <template #footer>
        <!-- inline confirmation before restoring the version -->
        <div v-if="diffRestoreConfirm && diffFromEntry" class="k-content-watch-diff-confirm">
          <k-text>
            <p>
              <strong>{{ t('restore.confirm.title', {version: diffFromEntry.version}) }}</strong>
              {{ diffFromEntry.language
                ? t('restore.confirm.text.language', {version: diffFromEntry.version, language: languageName(diffFromEntry.language)})
                : t('restore.confirm.text', {version: diffFromEntry.version}) }}
            </p>
            <ul>
              <li>{{ t('restore.confirm.history') }}</li>
              <li v-if="diffFromEntry.language">{{ t('restore.confirm.languages') }}</li>
            </ul>
          </k-text>
          <k-button-group>
            <k-button variant="filled" :disabled="isRestoringFromDiff" @click="diffRestoreConfirm = false">{{ $t('cancel') }}</k-button>
            <k-button
              variant="filled"
              theme="positive"
              icon="undo"
              :disabled="isRestoringFromDiff"
              @click="restoreFromDiff"
            >{{ t('restore.confirm.submit', {version: diffFromEntry.version}) }}</k-button>
          </k-button-group>
        </div>

        <k-dialog-footer class="k-content-watch-diff-footer">
          <span v-if="canRestoreFromDiff" class="k-content-watch-diff-hint">
            {{ diffFromEntry.language
              ? t('restore.hint.language', {language: diffFromEntry.language.toUpperCase()})
              : t('restore.hint') }}
          </span>
          <k-button-group>
            <k-button variant="filled" @click="$refs.diffDialog.close()">{{ $t('close') }}</k-button>
            <k-button
              v-if="canRestoreFromDiff"
              variant="filled"
              theme="positive"
              icon="undo"
              :disabled="diffRestoreConfirm"
              @click="diffRestoreConfirm = true"
            >{{ t('restore.button') }}</k-button>
          </k-button-group>
        </k-dialog-footer>
      </template>
    </k-dialog>
  </k-panel-inside>
</template>

<script>
import {formatDistance} from 'date-fns';
import {editorHelp, editorLabel} from '../editor.js';
import {buildTimeline, groupByDay, relativeDay} from '../timeline.js';

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
    enableLockedPages: {
      type: Boolean,
      default: true
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
    defaultView: {
      type: String,
      default: 'page'
    },
    initialSearch: {
      type: String,
      default: ''
    },
    initialShowAll: {
      type: Boolean,
      default: false
    },
    initialTab: {
      type: String,
      default: 'content'
    }
  },

  data() {
    return {
      isLoading: false,
      search: this.initialSearch,
      lockedSearch: '',
      filteredFiles: [],
      filteredLockedPages: [],
      selectedAuthor: '',
      selectedStatus: '',
      selectedTemplate: '',
      selectedPeriod: '',
      selectedLanguage: '',
      showFilters: false,
      lockedShowOnlyPages: true,
      expandedFiles: [],
      diffConfirmOnLoad: false,
      showOnlyPages: !this.initialShowAll,
      currentPage: 1,
      view: this.storedView() || (this.defaultView === 'timeline' ? 'timeline' : 'page'),
      expandedGroups: [],
      pageSize: this.defaultPageSize,
      pageSizeOptions: [
        {text: '10 per page', value: 10},
        {text: '20 per page', value: 20},
        {text: '50 per page', value: 50}
      ],
      diffTarget: null,
      diffVersionOptions: [],
      diffFromVersionId: null,
      diffToVersionId: null,
      diffContent: null,
      diffSummary: null,
      diffCanRestore: false,
      diffRestoreConfirm: false,
      isRestoringFromDiff: false,
      isDiffLoading: false
    };
  },

  created() {
    this.filteredFiles = this.files || [];
    this.filteredLockedPages = this.lockedPages || [];
    this.filterFiles();
    this.filterLockedPages();

    // Coming from a section link: open the history of the matching page right away
    if (this.initialSearch && this.filteredFiles.length === 1) {
      this.expandedFiles.push(this.filteredFiles[0].id);
    }
  },

  computed: {
    // tabs are links (?tab=locked), as in Kirby's own views
    tab() {
      return this.enableLockedPages && this.initialTab === 'locked' ? 'locked' : 'content';
    },

    tabs() {
      if (!this.enableLockedPages) {
        return [];
      }

      return [
        {name: 'content', label: this.$t('tearoom1.content-watch.tab.content'), link: 'content-watch'},
        {name: 'locked', label: this.$t('tearoom1.content-watch.tab.locked'), link: 'content-watch?tab=locked'}
      ];
    },

    listLength() {
      return this.view === 'timeline' ? this.timelineItems.length : this.filteredFiles.length;
    },

    totalPages() {
      return Math.max(1, Math.ceil(this.listLength / (this.pageSize || 10)));
    },

    // one row per change; files without recorded changes show their last modification
    timelineRows() {
      const since = this.selectedPeriod ? this.periodStart(this.selectedPeriod) : 0;
      const rows = [];

      this.baseFilteredFiles().forEach(file => {
        const history = file.history || [];
        const entries = history.length
          ? history.map((entry, entryIndex) => ({entry, entryIndex, time: entry.time, editor: entry.editor}))
          : [{entry: null, entryIndex: -1, time: file.modified, editor: file.editor}];

        entries.forEach(({entry, entryIndex, time, editor}) => {
          if (time < since) {
            return;
          }
          if (this.selectedLanguage && entry?.language !== this.selectedLanguage) {
            return;
          }
          if (this.selectedAuthor && (editor?.id || this.editorLabel(editor)) !== this.selectedAuthor) {
            return;
          }

          rows.push({
            key: file.dir_path + '/' + file.uid + '/' + (entry?.entry_id || time) + '/' + entryIndex,
            file,
            entry,
            entryIndex,
            time,
            editor,
            outside: !editor || editor.outside === true || editor.id === 'unknown'
          });
        });
      });

      return rows;
    },

    timelineItems() {
      return buildTimeline(this.timelineRows);
    },

    timelineDays() {
      const start = this.paginationStart;
      return groupByDay(this.timelineItems.slice(start, start + (this.pageSize || 10)));
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

    authorFilterOptions() {
      const seen = new Set();
      const options = [];

      this.files.forEach(file => {
        const name = this.fileEditorName(file);
        const value = file.editor?.id || name;

        if (seen.has(value)) {
          return;
        }

        seen.add(value);
        options.push({
          text: name,
          value
        });
      });

      return options;
    },

    statusFilterOptions() {
      const labels = {
        listed: 'Listed',
        unlisted: 'Unlisted',
        draft: 'Draft'
      };

      const statuses = [...new Set(this.files
        .map(file => file.page_status)
        .filter(Boolean)
      )];

      return [
        ...statuses.map(status => ({
          text: labels[status] || status,
          value: status
        }))
      ];
    },

    templateFilterOptions() {
      const templates = [...new Set(this.files
        .map(file => file.page_template)
        .filter(Boolean)
      )].sort((a, b) => a.localeCompare(b));

      return [
        ...templates.map(template => ({
          text: template,
          value: template
        }))
      ];
    },

    periodFilterOptions() {
      return [
        {text: 'Today', value: 'today'},
        {text: 'Last 7 days', value: '7'},
        {text: 'Last 30 days', value: '30'}
      ];
    },

    languageFilterOptions() {
      const languages = new Set();

      this.files.forEach(file => {
        (file.history || []).forEach(entry => {
          if (entry.language) {
            languages.add(entry.language);
          }
        });
      });

      return [...languages].sort().map(language => ({
        text: language.toUpperCase(),
        value: language
      }));
    },

    diffFromOptions() {
      return this.diffVersionOptions;
    },

    diffToOptions() {
      return [
        {text: this.$t('tearoom1.content-watch.diff.current'), value: 'current'},
        ...this.diffVersionOptions
      ];
    },

    diffFromEntry() {
      if (!this.diffTarget || this.diffFromVersionId === null) {
        return null;
      }

      return this.diffTarget.file.history[parseInt(this.diffFromVersionId, 10)] || null;
    },

    canRestoreFromDiff() {
      return this.enableRestore &&
        this.diffCanRestore &&
        !this.isDiffLoading &&
        this.diffFromEntry?.has_snapshot === true &&
        (this.diffSummary?.changed ?? 0) > 0;
    },

    diffSummaryText() {
      if (!this.diffSummary || !this.diffTarget) {
        return null;
      }

      const {changed, unchanged} = this.diffSummary;
      const from = this.diffTarget.file.history[parseInt(this.diffFromVersionId, 10)];
      const t = (key, data) => this.t(key, data);

      if (!changed) {
        return t('diff.summary.none', {});
      }

      const parts = [t(changed === 1 ? 'diff.summary.changed.one' : 'diff.summary.changed', {
        count: changed,
        version: from?.version ?? ''
      })];

      if (unchanged) {
        parts.push(t(unchanged === 1 ? 'diff.summary.unchanged.one' : 'diff.summary.unchanged', {count: unchanged}));
      }

      return parts.join(' · ');
    },

    // filters that differ from the defaults, "All files" included
    activeFilterCount() {
      return [
        !this.showOnlyPages,
        this.selectedAuthor,
        this.selectedStatus,
        this.selectedTemplate,
        this.selectedPeriod,
        this.selectedLanguage
      ].filter(Boolean).length;
    },

    showFilterOptions() {
      return [
        {text: this.$t('tearoom1.content-watch.filter.pages'), value: 'pages'},
        {text: this.$t('tearoom1.content-watch.filter.all'), value: 'all'}
      ];
    },

    items() {
      return this.filteredFiles.map(file => {
        const modifiedDate = new Date(file.modified * 1000);
        const timeAgo = formatDistance(modifiedDate, new Date(), {addSuffix: true});
        const editorName = this.fileEditorName(file);

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

  },

  methods: {
    canDiff(file, entry) {
      return Boolean(entry?.has_snapshot && file.history.length > 1);
    },

    canRestoreEntry(entry, entryIndex) {
      return Boolean(entry?.has_snapshot && entryIndex > 0 && !entry.same_as_current);
    },

    // an older version that equals the current content: nothing to compare or restore
    isSame(entry, entryIndex) {
      return Boolean(entryIndex > 0 && entry?.same_as_current);
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

    viewStorageKey() {
      const user = window.panel?.user?.id || 'default';
      return 'tearoom1.content-watch.view.' + user;
    },

    // view chosen in the Panel, overrides the defaultView option
    storedView() {
      try {
        const value = window.localStorage.getItem(this.viewStorageKey());
        return value === 'page' || value === 'timeline' ? value : null;
      } catch (e) {
        return null;
      }
    },

    setView(view) {
      this.view = view;
      this.currentPage = 1;

      try {
        window.localStorage.setItem(this.viewStorageKey(), view);
      } catch (e) {
        // storage not available, keep the choice for this visit only
      }
    },

    dayLabel(key) {
      const relative = relativeDay(key);
      if (relative) {
        return this.$t('tearoom1.content-watch.timeline.' + relative);
      }

      const [year, month, day] = key.split('-').map(Number);
      return new Date(year, month - 1, day).toLocaleDateString(this.panelLanguage(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      });
    },

    formatTime(time) {
      return new Date(time * 1000).toLocaleTimeString(this.panelLanguage(), {
        hour: '2-digit',
        minute: '2-digit'
      });
    },

    groupKey(item) {
      return item.time + '-' + item.rows.length;
    },

    toggleOutsideGroup(item) {
      const key = this.groupKey(item);
      const index = this.expandedGroups.indexOf(key);
      index === -1 ? this.expandedGroups.push(key) : this.expandedGroups.splice(index, 1);
    },

    outsideGroupLabel(item) {
      const count = new Set(item.rows.map(row => row.file.id)).size;
      return this.t('timeline.pages', {count});
    },

    panelLanguage() {
      const panel = window.panel || {};
      const panelLanguage = panel.language && panel.language.code;
      const viewLanguage = panel.view && panel.view.props && panel.view.props.language;
      const browserLanguage = window.navigator && window.navigator.language;

      return String(viewLanguage || panelLanguage || browserLanguage || 'en').toLowerCase();
    },

    refresh() {
      this.isLoading = true;
      window.location.reload();
    },

    open(id) {
      const file = this.filteredFiles.find(f => f.id === id);
      this.openFile(file);
    },

    openLockedPage(lock) {
      if (lock?.panel_url) {
        window.location.href = lock.panel_url;
      }
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

    // search, pages only, status and template: shared by both views
    baseFilteredFiles() {
      const searchLower = this.search.toLowerCase();
      let filtered = this.files;

      if (this.showOnlyPages) {
        filtered = filtered.filter(file => file.panel_url && file.panel_url.indexOf('/files/') === -1 && !file.is_media_file);
      }

      if (this.selectedStatus) {
        filtered = filtered.filter(file => file.page_status === this.selectedStatus);
      }

      if (this.selectedTemplate) {
        filtered = filtered.filter(file => file.page_template === this.selectedTemplate);
      }

      return filtered.filter(
        file =>
          file.title.toLowerCase().includes(searchLower) ||
          file.path.toLowerCase().includes(searchLower)
      );
    },

    filterFiles() {
      let filtered = this.baseFilteredFiles();

      if (this.selectedAuthor) {
        filtered = filtered.filter(file => (file.editor?.id || this.fileEditorName(file)) === this.selectedAuthor);
      }

      if (this.selectedPeriod) {
        const since = this.periodStart(this.selectedPeriod);
        filtered = filtered.filter(file => file.modified >= since);
      }

      if (this.selectedLanguage) {
        filtered = filtered.filter(file => (file.history || []).some(entry => entry.language === this.selectedLanguage));
      }

      this.filteredFiles = filtered;

      // Reset to first page when filtering changes
      this.currentPage = 1;
    },

    filterLockedPages() {
      const searchLower = this.lockedSearch.toLowerCase();
      let filtered = this.lockedPages;

      if (this.lockedShowOnlyPages) {
        filtered = filtered.filter(page => !!page.page_status);
      }

      // Then apply search filter
      this.filteredLockedPages = filtered.filter(
        page =>
          page.title.toLowerCase().includes(searchLower) ||
          page.path.toLowerCase().includes(searchLower)
      );
    },

    toggleLockedShowOnlyPages() {
      this.lockedShowOnlyPages = true;
      this.filterLockedPages();
    },

    toggleLockedShowAll() {
      this.lockedShowOnlyPages = false;
      this.filterLockedPages();
    },

    toggleFilters() {
      this.showFilters = !this.showFilters;
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

    changeAuthorFilter(value) {
      this.selectedAuthor = this.normalizeFilterValue(value);
      this.filterFiles();
    },

    changeStatusFilter(value) {
      this.selectedStatus = this.normalizeFilterValue(value);
      this.filterFiles();
    },

    changeTemplateFilter(value) {
      this.selectedTemplate = this.normalizeFilterValue(value);
      this.filterFiles();
    },

    exportCsv() {
      const header = ['Title', 'Path', 'Version', 'Language', 'Action', 'Editor', 'Email', 'Time'];
      const rows = [];

      const since = this.selectedPeriod ? this.periodStart(this.selectedPeriod) : 0;

      this.filteredFiles.forEach(file => {
        let entries = file.history && file.history.length ? file.history : [{
          editor: file.editor,
          time: file.modified,
          time_formatted: file.modified_formatted
        }];

        // Apply period and language filters to the single entries, not only to the files
        entries = entries.filter(entry =>
          entry.time >= since &&
          (!this.selectedLanguage || entry.language === this.selectedLanguage)
        );

        entries.forEach(entry => {
          rows.push([
            file.title,
            file.path_short,
            entry.version ? 'v' + entry.version : '',
            entry.language || '',
            entry.version ? this.entryAction(entry) : '',
            entry.editor?.name || this.editorLabel(entry.editor),
            entry.editor?.email || '',
            entry.time_formatted || ''
          ]);
        });
      });

      const csv = [header, ...rows]
        .map(row => row.map(value => this.csvValue(value)).join(','))
        .join('\r\n');

      const blob = new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8'});
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'content-watch-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    },

    csvValue(value) {
      let text = String(value ?? '');

      // Prevent spreadsheet formula injection
      if (/^[=+\-@\t\r]/.test(text)) {
        text = "'" + text;
      }

      return '"' + text.replace(/"/g, '""') + '"';
    },

    changePeriodFilter(value) {
      this.selectedPeriod = this.normalizeFilterValue(value);
      this.filterFiles();
    },

    changeLanguageFilter(value) {
      this.selectedLanguage = this.normalizeFilterValue(value);
      this.filterFiles();
    },

    periodStart(period) {
      const now = new Date();

      if (period === 'today') {
        now.setHours(0, 0, 0, 0);
        return Math.floor(now.getTime() / 1000);
      }

      return Math.floor(now.getTime() / 1000) - parseInt(period, 10) * 86400;
    },

    changeShowFilter(value) {
      this.showOnlyPages = this.normalizeFilterValue(value) !== 'all';
      this.filterFiles();
    },

    resetFilters() {
      this.showOnlyPages = true;
      this.selectedAuthor = '';
      this.selectedStatus = '';
      this.selectedTemplate = '';
      this.selectedPeriod = '';
      this.selectedLanguage = '';
      this.filterFiles();
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

    // restore buttons in the lists: the diff dialog opens with its confirmation
    confirmRestore(file, entry) {
      if (!this.enableRestore) return;

      this.viewDiff(file, entry, file.history.indexOf(entry), true);
    },

    entryAction(entry) {
      if (entry?.restored_from || entry?.restored_from_id) {
        return 'restored';
      }

      if (entry?.action === 'moved' || entry?.action === 'duplicated') {
        return entry.action;
      }

      return 'edited';
    },

    entryEditor(entry) {
      return this.editorLabel(entry?.editor);
    },

    formatAbsolute(time) {
      return new Date(time * 1000).toLocaleString(this.panelLanguage(), {
        dateStyle: 'medium',
        timeStyle: 'short'
      });
    },

    versionLabel(entry) {
      return 'v' + entry.version + (entry.language ? ' · ' + entry.language.toUpperCase() : '') + ' · ' +
        this.formatRelative(entry.time) + ' · ' + this.editorLabel(entry.editor);
    },

    // compare a version with the current content by default
    viewDiff(file, entry, entryIndex, confirmRestore = false) {
      this.diffTarget = {file, entry, entryIndex};
      this.diffConfirmOnLoad = confirmRestore;
      this.diffVersionOptions = file.history.map((historyEntry, historyIndex) => ({
        text: this.versionLabel(historyEntry),
        value: String(historyIndex),
        disabled: !historyEntry.has_snapshot
      }));

      // the newest version usually is the current content: show what it changed instead
      const fromIndex = entryIndex === 0 && file.history.length > 1 && !confirmRestore ? 1 : entryIndex;
      this.diffFromVersionId = String(fromIndex);
      this.diffToVersionId = 'current';

      this.$refs.diffDialog.open();
      this.loadDiff();
    },

    closeDiff() {
      this.diffTarget = null;
      this.diffVersionOptions = [];
      this.diffFromVersionId = null;
      this.diffToVersionId = null;
      this.diffContent = null;
      this.diffSummary = null;
      this.diffCanRestore = false;
      this.diffRestoreConfirm = false;
    },

    // translated plugin string; Kirby's $t fills the {placeholders} from data
    t(key, data = {}) {
      return this.$t('tearoom1.content-watch.' + key, data);
    },

    languageName(code) {
      const languages = window.panel?.languages || [];
      return languages.find(language => language.code === code)?.name || code.toUpperCase();
    },

    async restoreFromDiff() {
      const file = this.diffTarget?.file;
      const entry = this.diffFromEntry;

      if (!file || !entry) {
        return;
      }

      this.isRestoringFromDiff = true;

      try {
        const response = await this.$api.post('/content-watch/restore', {
          dirPath: file.dir_path,
          fileKey: file.uid,
          entryId: entry.entry_id ?? null,
          timestamp: entry.time
        });

        if (response.status !== 'success') {
          throw new Error(response.message);
        }

        window.panel.notification.success(this.t('restore.done', {version: entry.version}));
        this.refresh();
      } catch (error) {
        window.panel.notification.error(error.message || 'Failed to restore content');
        this.diffRestoreConfirm = false;
      } finally {
        this.isRestoringFromDiff = false;
      }
    },

    changeFromVersion(versionId) {
      this.diffFromVersionId = this.normalizeDiffVersionId(versionId);
      this.loadDiff();
    },

    changeToVersion(versionId) {
      this.diffToVersionId = this.normalizeDiffVersionId(versionId);
      this.loadDiff();
    },

    loadDiff() {
      if (this.diffFromVersionId === null || this.diffToVersionId === null) {
        this.diffContent = null;
        this.diffSummary = null;
        return;
      }

      this.isDiffLoading = true;
      this.diffContent = null;
      this.diffSummary = null;
      this.diffCanRestore = false;
      this.diffRestoreConfirm = false;

      const file = this.diffTarget.file;
      const fromEntry = file.history[parseInt(this.diffFromVersionId, 10)];
      const toCurrent = this.diffToVersionId === 'current';
      const toEntry = toCurrent ? null : file.history[parseInt(this.diffToVersionId, 10)];

      this.$api.post('/content-watch/diff', {
        dirPath: file.dir_path,
        fileKey: file.uid,
        fromEntryId: fromEntry?.entry_id ?? null,
        toEntryId: toCurrent ? 'current' : (toEntry?.entry_id ?? null),
        fromTimestamp: fromEntry?.time ?? null,
        toTimestamp: toEntry?.time ?? null
      })
        .then(response => {
          this.diffContent = response.diff;
          this.diffSummary = response.summary || null;
          this.isDiffLoading = false;
          this.diffCanRestore = response.canRestore === true;
          this.diffRestoreConfirm = this.diffConfirmOnLoad && this.canRestoreFromDiff;
          this.diffConfirmOnLoad = false;
        })
        .catch(error => {
          window.panel.notification.error('Error loading diff: ' + (error.message || 'Unknown error'));
        })
        .finally(() => {
          this.isDiffLoading = false;
        });
    },

    getDiffSelectionLabel(versionId) {
      if (versionId === null) {
        return 'None';
      }

      const file = this.diffTarget.file;
      const entry = file.history[parseInt(versionId, 10)];

      if (!entry) {
        return 'Unknown version';
      }

      return 'v' + entry.version + ' / ' + entry.language + ' / ' +
        entry.time_formatted + ' (' + this.editorLabel(entry.editor) + ')';
    },

    normalizeDiffVersionId(versionId) {
      if (versionId && typeof versionId === 'object') {
        return versionId.value !== undefined ? String(versionId.value) : null;
      }

      return versionId === null || versionId === undefined ? null : String(versionId);
    },

    normalizeFilterValue(value) {
      if (value && typeof value === 'object') {
        return value.value !== undefined ? String(value.value) : '';
      }

      return value === null || value === undefined ? '' : String(value);
    },

    fileEditorName(file) {
      return this.editorLabel(file.editor);
    },

    editorLabel(editor) {
      return editorLabel(editor, this.$t.bind(this));
    },

    editorHelp(editor) {
      return editorHelp(editor, this.$t.bind(this));
    }
  }
};
</script>

<style>
.k-content-watch-view {
  position: relative;

  /* space between the view switch and the next group */
  .k-content-watch-group-end {
    margin-inline-end: var(--spacing-3);
  }

  .k-content-watch-sponsor-button {
    --button-color-icon: #e0245e;
  }

  .k-content-watch-sponsor-button:hover {
    --button-color-icon: #b81d4d;
    color: var(--color-text);
  }

  .k-content-watch-locked {
    margin-top: 1rem;
  }

  .k-content-watch-file {
    border: 1px solid var(--color-border);
    border-radius: 4px;
    margin-bottom: 0.5rem;
    overflow: hidden;
    transition: all 0.3s ease;
  }

  /* search shrinks first, the buttons stay in one row */
  .k-content-watch-toolbar {
    display: flex;
    align-items: center;
    gap: var(--spacing-3);
  }

  .k-content-watch-toolbar .k-content-watch-search {
    flex: 1 1 10rem;
    min-width: 0;
  }

  .k-content-watch-buttons {
    flex: 0 0 auto;
    flex-wrap: nowrap;
    justify-content: flex-end;
  }

  .k-content-watch-file-open {
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
  }

  .k-content-watch-file-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.2rem 0.6rem;
    cursor: pointer;
    background-color: var(--item-color-back);
  }

  .k-content-watch-file-info {
    display: flex;
    justify-content: space-between;
    width: 100%;
    line-height: 1.2rem;
  }

  .k-content-watch-file-path {
    display: flex;
    gap: 0.5rem;
    font-size: .875rem;
    opacity: 0.7;
    margin-top: 0.25rem;
  }

  .k-content-watch-file-title {
    color: var(--color-text);
  }

  .k-content-watch-file-title-inline {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
  }

  .k-content-watch-status-icon {
    flex: 0 0 auto;
    color: var(--color-gray-500);
    --icon-size: 0.875rem;
  }

  .k-content-watch-status-icon-listed {
    color: var(--color-positive-light);
  }

  .k-content-watch-status-icon-unlisted {
    color: var(--color-blue-500);
  }

  .k-content-watch-status-icon-draft {
    color: var(--color-notice-light);
  }

  .k-content-watch-status-glyph {
    position: relative;
    width: 0.875rem;
    height: 0.875rem;
    border-radius: 999px;
    flex: 0 0 auto;
    margin-top: 0.05rem;
    box-sizing: border-box;
  }

  .k-content-watch-status-glyph-listed {
    background: var(--color-positive-light);
  }

  .k-content-watch-status-glyph-unlisted {
    border: 2px solid var(--color-blue-500);
    background: linear-gradient(to right, var(--color-blue-500) 50%, transparent 50%);
  }

  .k-content-watch-status-glyph-draft {
    border: 2px solid var(--color-notice-light);
    background: transparent;
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

  .k-button-disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .k-content-watch-file-timeline {
    padding: 0.5rem 1rem;
    border-top: 1px solid var(--color-border);
  }

  .k-timeline-list {
    margin: 0;
    padding: 0.25rem 0;
    font-size: var(--text-sm);
  }

  .k-timeline-item {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.475rem 0;
    border-bottom: 1px solid var(--color-border);
  }

  .k-timeline-item:last-child {
    border-bottom: none;
  }

  .k-timeline-item-version {
    flex: 0 0 2.5rem;
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    color: var(--color-text-dimmed);
  }

  .k-timeline-item-current .k-timeline-item-version {
    color: var(--color-positive);
  }

  .k-timeline-item-language {
    flex: 0 0 auto;
    font-family: var(--font-mono);
    font-size: 0.65rem;
    line-height: 1.1rem;
    padding-inline: 0.3rem;
    border: 1px solid var(--color-border);
    border-radius: var(--rounded-sm);
    color: var(--color-text-dimmed);
    text-transform: uppercase;
  }

  .k-timeline-item-tag {
    --tag-color: var(--color-text-dimmed);
    flex: 0 0 5.5rem;
    font-size: var(--text-xs);
    font-weight: var(--font-semi);
    line-height: 1.25rem;
    text-align: center;
    border-radius: 999px;
    color: var(--tag-color);
    background: color-mix(in srgb, var(--tag-color) 14%, transparent);
  }

  .k-timeline-item-tag-restored { --tag-color: var(--color-orange-500); }
  .k-timeline-item-tag-moved { --tag-color: var(--color-blue-500); }
  .k-timeline-item-tag-duplicated { --tag-color: var(--color-purple-500); }

  .k-timeline-item-editor {
    flex: 1;
    min-width: 0;
    font-weight: var(--font-semi);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .k-timeline-item-when {
    flex: 0 0 auto;
    text-align: right;
    font-size: var(--text-xs);
    line-height: 1.3;
    color: var(--color-text-dimmed);
  }

  .k-timeline-item-when-absolute {
    opacity: 0.7;
  }

  .k-timeline-item-when-absolute::before {
    content: " · ";
  }

  /* Fixed width keeps the time column aligned when the diff button is missing */
  .k-timeline-item-actions {
    flex: 0 0 4.5rem;
    display: inline-flex;
    justify-content: flex-end;
    gap: 0.25rem;
    margin-left: auto;
  }

  .k-cw-slot-empty {
    visibility: hidden;
  }

  /* the version equals the current content: the diff only says "No changes" */
  .k-diff-button.k-cw-diff-same {
    color: var(--color-text-dimmed) !important;
  }

  .k-restore-button {
    height: auto !important;
    line-height: 1 !important;
    color: var(--color-positive) !important;
  }

  .k-diff-button {
    height: auto !important;
    line-height: 1 !important;
    color: var(--color-orange-600) !important;
  }

  .k-timeline-footer {
    padding: 0.5rem 1rem 0;
    font-size: 0.75rem;
    color: var(--color-text-dimmed);
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

  .k-content-watch-files {
    margin-top: 1rem;
  }

  /* timeline view */
  .k-content-watch-timeline-day {
    margin-top: var(--spacing-6);
  }

  .k-content-watch-timeline-day-label {
    font-size: var(--text-sm);
    font-weight: var(--font-semi);
  }

  .k-content-watch-timeline-day .k-content-watch-files {
    margin-top: var(--spacing-2);
  }

  /* fixed columns: time | title and path | language | action | author | actions */
  .k-content-watch-timeline-row {
    display: grid;
    grid-template-columns: 4.75rem minmax(0, 1fr) 2.25rem 5.5rem 9rem 4.5rem;
    align-items: center;
    column-gap: 0.875rem;
    font-size: var(--text-sm);
    cursor: default;
  }

  .k-content-watch-timeline-main-wide {
    grid-column: 2 / 6;
  }

  .k-content-watch-timeline-cell {
    display: flex;
  }

  .k-content-watch-timeline-row .k-timeline-item-actions {
    margin-left: 0;
  }

  .k-content-watch-file > .k-content-watch-timeline-row {
    min-height: 2.25rem;
  }

  /* wide enough for "03:46 PM" */
  .k-content-watch-timeline-time {
    white-space: nowrap;
    color: var(--color-text-dimmed);
    font-variant-numeric: tabular-nums;
  }

  .k-content-watch-timeline-main {
    min-width: 0;
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    overflow: hidden;
    white-space: nowrap;
  }

  /* the path gives way first, then the title */
  .k-content-watch-timeline-title {
    flex: 0 1 auto;
    min-width: 4rem;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: var(--font-semi);
    color: var(--color-text);
  }

  .k-content-watch-timeline-title:hover {
    text-decoration: underline;
  }

  .k-content-watch-timeline-path {
    flex: 0 1000 auto;
    min-width: 0;
    color: var(--color-text-dimmed);
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .k-content-watch-timeline-editor {
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    color: var(--color-text-dimmed);
  }

  .k-content-watch-timeline-group {
    cursor: pointer;
  }

  .k-content-watch-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: 0.75rem;
    margin-top: 0.75rem;
    align-items: end;
  }

  .k-content-watch-filters-reset {
    display: flex;
    justify-content: flex-end;
    padding-bottom: 0.125rem;
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

  @media (max-width: 40rem) {
    .k-content-watch-timeline-row {
      display: flex;
      flex-wrap: wrap;
      row-gap: 0.25rem;
    }

    .k-content-watch-timeline-main {
      flex-basis: calc(100% - 4rem);
      flex-direction: column;
      white-space: normal;
    }

    .k-content-watch-timeline-editor {
      text-align: left;
      flex-basis: auto;
    }

    .k-content-watch-toolbar {
      flex-wrap: wrap;
    }

    .k-content-watch-toolbar .k-content-watch-search {
      flex-basis: 100%;
    }

    .k-content-watch-buttons {
      flex-wrap: wrap;
      justify-content: flex-start;
    }

    /* File rows: stack title, path and editor instead of squeezing columns */
    .k-content-watch-file-header {
      align-items: flex-start;
      padding-inline: 0.75rem;
    }

    .k-content-watch-file-info {
      flex-direction: column;
      gap: 0.25rem;
      min-width: 0;
    }

    .k-content-watch-file-path {
      flex-direction: column;
      gap: 0.125rem;
      overflow-wrap: anywhere;
    }

    .k-content-watch-file-editor {
      text-align: left;
      margin-top: 0;
    }

    .k-content-watch-file-actions {
      gap: 0;
      flex-shrink: 0;
    }

    /* Timeline: time moves below the editor */
    .k-timeline-list .k-timeline-item {
      flex-wrap: wrap;
      row-gap: 0.25rem;
      column-gap: 0.5rem;
    }

    .k-timeline-list .k-timeline-item-tag {
      flex-basis: 4.75rem;
    }

    /* first row: version, language, tag, actions – second row: editor · time */
    .k-timeline-list .k-timeline-item-actions {
      order: 4;
    }

    .k-timeline-list .k-timeline-item::after {
      content: "";
      order: 5;
      flex-basis: 100%;
    }

    .k-timeline-list .k-timeline-item-editor {
      order: 6;
      flex: 0 1 auto;
      padding-left: 3rem;
    }

    .k-timeline-list .k-timeline-item-when {
      order: 7;
      text-align: left;
      font-size: var(--text-sm);
    }

    .k-timeline-list .k-timeline-item-when::before {
      content: "· ";
    }

    .k-timeline-list .k-timeline-item-when-absolute {
      display: none;
    }
  }

  @media (max-width: 62rem) {
    .k-content-watch-filters {
      grid-template-columns: 1fr;
    }

    .k-content-watch-pagination {
      flex-direction: column;
      gap: 1rem;
      align-items: stretch;
    }
  }
}

/* the dialogs render outside the view, so their styles are not nested */
.k-content-watch-diff-dialog .k-dialog-body {
  display: grid;
  gap: 0.5rem;
}

.k-content-watch-diff-header {
  padding: 0 0 var(--spacing-4);
  border-bottom: 1px solid var(--color-border);
}

.k-content-watch-diff-file-info {
  font-size: var(--text-md);
}

.k-content-watch-diff-path {
  font-size: var(--text-sm);
  color: var(--color-text-dimmed);
}

.k-content-watch-diff-compare-versions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--spacing-3);
  margin-top: var(--spacing-4);
}

.k-content-watch-diff-confirm {
  display: grid;
  gap: var(--spacing-3);
  padding: var(--spacing-4) var(--dialog-padding, var(--spacing-6));
  border-top: 1px solid var(--color-border);
  background: var(--panel-color-back);
  font-size: var(--text-sm);
}

.k-content-watch-diff-confirm ul {
  color: var(--color-text-dimmed);
}

.k-content-watch-diff-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-3);
}

.k-content-watch-diff-footer .k-button-group {
  margin-left: auto;
}

.k-content-watch-diff-hint {
  flex: 1 1 14rem;
  font-size: var(--text-xs);
  color: var(--color-text-dimmed);
}

.k-content-watch-diff-summary {
  margin-top: var(--spacing-3);
  font-size: var(--text-sm);
  color: var(--color-text-dimmed);
}

.k-content-watch-diff-code {
  font-size: 0.875rem;
  font-family: monospace;
  white-space: pre-wrap;
}

@media (max-width: 40rem) {
  .k-content-watch-diff-compare-versions {
    grid-template-columns: 1fr;
  }
}

</style>
