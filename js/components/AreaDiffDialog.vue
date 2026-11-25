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

      <!-- the two usual comparisons, and stepping through versions (also with the arrow keys);
           the dialog starts on the active comparison, not in a select that would keep the arrow keys -->
      <div class="k-cw-diff-toolbar">
        <k-button-group class="k-cw-diff-modes" role="group" :aria-label="t('diff.mode.label')">
          <k-button
            v-for="mode in modes"
            :key="mode"
            size="sm"
            :variant="diffMode === mode ? 'filled' : null"
            :current="diffMode === mode"
            :autofocus="diffMode === mode"
            :disabled="!canSetDiffMode(mode)"
            @click="setDiffMode(mode)"
          >{{ t('diff.mode.' + mode) }}</k-button>
        </k-button-group>
        <k-button-group>
          <k-button
            icon="angle-left"
            size="sm"
            variant="filled"
            :disabled="diffStepTarget(1) < 0"
            @click="diffStep(1)"
          >{{ t('diff.step.older') }}</k-button>
          <k-button
            icon="angle-right"
            icon-after
            size="sm"
            variant="filled"
            :disabled="diffStepTarget(-1) < 0"
            @click="diffStep(-1)"
          >{{ t('diff.step.newer') }}</k-button>
        </k-button-group>
      </div>

      <div class="k-content-watch-diff-compare-versions">
        <k-select-field
          :label="$t('tearoom1.content-watch.diff.version')"
          :options="diffFromOptions"
          :value="diffFromVersionId"
          class="k-cw-select-plain"
          required
          @input="changeFromVersion"
        />
        <k-select-field
          :label="$t('tearoom1.content-watch.diff.compare')"
          :options="diffToOptions"
          :value="diffToVersionId"
          class="k-cw-select-plain"
          required
          @input="changeToVersion"
        />
      </div>

      <p v-if="diffSummaryText" class="k-content-watch-diff-summary">{{ diffSummaryText }}</p>
    </div>

    <!-- while the next pair loads, the last result stays dimmed so the dialog doesn't jump -->
    <k-loader v-if="isDiffLoading && diffContent === null && diffSummary === null"/>

    <div v-else-if="diffContent" class="k-content-watch-diff-content" :class="{'k-cw-diff-stale': isDiffLoading}">
      <div v-html="diffContent"></div>
    </div>

    <k-empty v-else icon="document" text="No diff available" :class="{'k-cw-diff-stale': isDiffLoading}"/>

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
import {CURRENT, PREVIOUS, canCompare, focusOf, isTypingTarget, modeOf, pairFor, step} from '../diff-pairs.js';

/**
 * Compares a version of a page or file with the current content or another
 * version and restores it. Calls `afterRestore` after a successful restore.
 */
export default {
  // the Panel passes its dialog listeners, the events below are emitted explicitly
  inheritAttrs: false,

  props: {
    // called after a successful restore, also when the dialog was closed meanwhile:
    // Vue drops events of an unmounted component, a plain function call still arrives
    afterRestore: Function,
    // open with the restore confirmation (restore buttons in the lists)
    confirmRestore: Boolean,
    enableRestore: Boolean,
    entryIndex: Number,
    file: Object,
    visible: Boolean
  },

  emits: ['cancel', 'close'],

  data() {
    return {
      diffConfirmOnLoad: this.confirmRestore,
      diffFromVersionId: null,
      diffToVersionId: null,
      diffContent: null,
      diffSummary: null,
      diffCanRestore: false,
      diffRestoreConfirm: false,
      isRestoringFromDiff: false,
      isDiffLoading: false,
      diffRequest: 0,
      modes: [PREVIOUS, CURRENT]
    };
  },

  // the diff button shows what a version changed; restoring compares with the current content
  created() {
    this.showDiffPair(this.entryIndex, this.confirmRestore ? CURRENT : PREVIOUS);
    window.addEventListener('keydown', this.onDiffKeydown);
  },

  unmounted() {
    // answers still on their way belong to the closed dialog
    this.diffRequest++;
    window.removeEventListener('keydown', this.onDiffKeydown);
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

    // "Current version = v8" while the newest version in that language equals the current content
    diffToOptions() {
      const language = this.diffFromEntry?.language || '';
      const newest = (this.file?.history || []).find(entry => (entry.language || '') === language);
      const current = newest?.same_as_current
        ? this.t('diff.current.same', {version: newest.version})
        : this.$t('tearoom1.content-watch.diff.current');

      return [
        {text: current, value: CURRENT},
        ...this.diffVersionOptions
      ];
    },

    // 'previous', 'current' or null when the selects show any other pair
    diffMode() {
      if (!this.file || this.diffFromVersionId === null || this.diffToVersionId === null) {
        return null;
      }

      return modeOf(this.file.history, this.diffIndex(this.diffFromVersionId), this.diffIndex(this.diffToVersionId));
    },

    // the version the dialog is about, the starting point for mode switches and steps
    diffFocusIndex() {
      if (!this.file || this.diffFromVersionId === null || this.diffToVersionId === null) {
        return null;
      }

      const from = this.diffIndex(this.diffFromVersionId);
      const to = this.diffIndex(this.diffToVersionId);

      // a free choice: the newer side when it is a version, otherwise the older one
      return this.diffMode ? focusOf(from, to) : (to === CURRENT ? from : to);
    },

    diffFromEntry() {
      if (!this.file || this.diffFromVersionId === null) {
        return null;
      }

      return this.file.history[parseInt(this.diffFromVersionId, 10)] || null;
    },

    // restoring is offered while comparing with the current content: that is what it changes
    canRestoreFromDiff() {
      return this.enableRestore &&
        this.diffToVersionId === CURRENT &&
        this.diffCanRestore &&
        !this.isDiffLoading &&
        this.diffFromEntry?.has_snapshot === true &&
        (this.diffSummary?.changed ?? 0) > 0;
    },

    diffSummaryText() {
      if (!this.diffSummary || !this.file) {
        return null;
      }

      const {changed, unchanged, version} = this.diffSummary;
      const t = (key, data) => this.t(key, data);

      if (!changed) {
        return t('diff.summary.none', {});
      }

      const parts = [t(changed === 1 ? 'diff.summary.changed.one' : 'diff.summary.changed', {
        count: changed,
        version
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
        this.afterRestore?.();
      } catch (error) {
        window.panel.notification.error(error.message || 'Failed to restore content');
        this.diffRestoreConfirm = false;
      } finally {
        this.isRestoringFromDiff = false;
      }
    },

    showDiffPair(index, mode) {
      const {from, to} = pairFor(this.file.history, index, mode);
      this.diffFromVersionId = String(from);
      this.diffToVersionId = String(to);
      this.loadDiff();
    },

    // the mode button is off while the version can't be shown that way
    canSetDiffMode(mode) {
      return this.diffFocusIndex !== null && canCompare(this.file.history, this.diffFocusIndex, mode);
    },

    setDiffMode(mode) {
      if (mode !== this.diffMode && this.canSetDiffMode(mode)) {
        this.showDiffPair(this.diffFocusIndex, mode);
      }
    },

    // direction 1 = older, -1 = newer; only in one of the two modes
    diffStepTarget(direction) {
      if (!this.file || !this.diffMode || this.diffFocusIndex === null) {
        return -1;
      }

      return step(this.file.history, this.diffFocusIndex, this.diffMode, direction);
    },

    diffStep(direction) {
      const index = this.diffStepTarget(direction);

      if (index >= 0) {
        this.showDiffPair(index, this.diffMode);
      }
    },

    // arrow keys step through versions while the diff dialog is open
    onDiffKeydown(event) {
      if (this.diffRestoreConfirm || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
        return;
      }

      if (isTypingTarget(event.target)) {
        return;
      }

      const direction = {ArrowLeft: 1, ArrowRight: -1}[event.key];

      if (direction && this.diffStepTarget(direction) >= 0) {
        event.preventDefault();
        this.diffStep(direction);
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

      // the last result stays until the new one is there; later requests win
      const request = ++this.diffRequest;
      this.isDiffLoading = true;
      this.diffCanRestore = false;
      this.diffRestoreConfirm = false;

      const file = this.file;
      const fromEntry = file.history[parseInt(this.diffFromVersionId, 10)];
      const toCurrent = this.diffToVersionId === CURRENT;
      const toEntry = toCurrent ? null : file.history[parseInt(this.diffToVersionId, 10)];

      this.$api.post('/content-watch/diff', {
        dirPath: file.dir_path,
        fileKey: file.uid,
        fromEntryId: fromEntry?.entry_id ?? null,
        toEntryId: toCurrent ? CURRENT : (toEntry?.entry_id ?? null),
        fromTimestamp: fromEntry?.time ?? null,
        toTimestamp: toEntry?.time ?? null
      })
        .then(response => {
          if (request !== this.diffRequest) {
            return;
          }

          this.diffContent = response.diff;
          // the version travels with the counts, the select may already show the next pair
          this.diffSummary = response.summary ? {...response.summary, version: fromEntry?.version ?? ''} : null;
          this.isDiffLoading = false;
          this.diffCanRestore = response.canRestore === true;
          this.diffRestoreConfirm = this.diffConfirmOnLoad && this.canRestoreFromDiff;
          this.diffConfirmOnLoad = false;
        })
        .catch(error => {
          if (request !== this.diffRequest) {
            return;
          }

          this.diffContent = null;
          this.diffSummary = null;
          window.panel.notification.error('Error loading diff: ' + (error.message || 'Unknown error'));
        })
        .finally(() => {
          if (request === this.diffRequest) {
            this.isDiffLoading = false;
          }
        });
    },

    // select value to history index, 'current' stays as it is
    diffIndex(versionId) {
      return versionId === CURRENT ? CURRENT : parseInt(versionId, 10);
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
