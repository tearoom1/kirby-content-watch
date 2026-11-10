<template>
  <!-- Diff dialog for comparing versions, opened with panel.dialog.open() by the Content Watch view -->
  <k-dialog
    :visible="visible"
    class="k-content-watch-diff-dialog"
    size="huge"
    @cancel="$emit('cancel')"
    @submit="$emit('close')"
  >
    <div class="k-content-watch-diff-header">
      <div class="k-content-watch-diff-file-info">
        <strong>{{ file?.title }}</strong>
        <span class="k-content-watch-diff-path"> ~ {{ file?.path_short }}</span>
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
          <k-button variant="filled" @click="$emit('close')">{{ $t('close') }}</k-button>
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
</template>

<script>
import {formatDistance} from 'date-fns';
import {editorLabel} from '../editor.js';

/**
 * Compares a version of a page or file with the current content or another
 * version and restores it. Emits `restored` after a successful restore.
 */
export default {
  // the Panel passes its dialog listeners, the events below are emitted explicitly
  inheritAttrs: false,

  props: {
    // open with the restore confirmation (restore buttons in the lists)
    confirmRestore: Boolean,
    enableRestore: Boolean,
    entryIndex: Number,
    file: Object,
    visible: Boolean
  },

  emits: ['cancel', 'close', 'restored'],

  data() {
    // the newest version usually is the current content: show what it changed instead
    const history = this.file?.history || [];
    const fromIndex = this.entryIndex === 0 && history.length > 1 && !this.confirmRestore ? 1 : this.entryIndex;

    return {
      diffConfirmOnLoad: this.confirmRestore,
      diffFromVersionId: String(fromIndex),
      diffToVersionId: 'current',
      diffContent: null,
      diffSummary: null,
      diffCanRestore: false,
      diffRestoreConfirm: false,
      isRestoringFromDiff: false,
      isDiffLoading: false
    };
  },

  created() {
    this.loadDiff();
  },

  computed: {
    diffVersionOptions() {
      return (this.file?.history || []).map((historyEntry, historyIndex) => ({
        text: this.versionLabel(historyEntry),
        value: String(historyIndex),
        disabled: !historyEntry.has_snapshot
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
      if (!this.file || this.diffFromVersionId === null) {
        return null;
      }

      return this.file.history[parseInt(this.diffFromVersionId, 10)] || null;
    },

    canRestoreFromDiff() {
      return this.enableRestore &&
        this.diffCanRestore &&
        !this.isDiffLoading &&
        this.diffFromEntry?.has_snapshot === true &&
        (this.diffSummary?.changed ?? 0) > 0;
    },

    diffSummaryText() {
      if (!this.diffSummary || !this.file) {
        return null;
      }

      const {changed, unchanged} = this.diffSummary;
      const from = this.file.history[parseInt(this.diffFromVersionId, 10)];
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
    }
  },

  methods: {
    // translated plugin string; Kirby's $t fills the {placeholders} from data
    t(key, data = {}) {
      return this.$t('tearoom1.content-watch.' + key, data);
    },

    languageName(code) {
      const languages = window.panel?.languages || [];
      return languages.find(language => language.code === code)?.name || code.toUpperCase();
    },

    versionLabel(entry) {
      return 'v' + entry.version + (entry.language ? ' · ' + entry.language.toUpperCase() : '') + ' · ' +
        formatDistance(new Date(entry.time * 1000), new Date(), {addSuffix: true}) + ' · ' +
        editorLabel(entry.editor, this.$t.bind(this));
    },

    async restoreFromDiff() {
      const file = this.file;
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
        this.$emit('restored');
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

      const file = this.file;
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

    normalizeDiffVersionId(versionId) {
      if (versionId && typeof versionId === 'object') {
        return versionId.value !== undefined ? String(versionId.value) : null;
      }

      return versionId === null || versionId === undefined ? null : String(versionId);
    }
  }
};
</script>
