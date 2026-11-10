/* global panel */

import AreaDiffDialog from './components/AreaDiffDialog.vue'
import ContentWatch from './components/ContentWatch.vue'
import ContentWatchField from './components/ContentWatchField.vue'
import FieldDiffDialog from './components/FieldDiffDialog.vue'
import RestoreDialog from './components/RestoreDialog.vue'
import Tooltip from './tooltip.js'
import './diff-table.css'
import './tooltip.css'

panel.plugin('tearoom1/kirby-content-watch', {
  components: {
    'content-watch': ContentWatch,
    // dialogs are opened with panel.dialog.open({component: ...})
    'k-content-watch-area-diff-dialog': AreaDiffDialog,
    'k-content-watch-field-diff-dialog': FieldDiffDialog,
    'k-content-watch-restore-dialog': RestoreDialog
  },
  fields: {
    // until Kirby 5 this was a section of the same name
    contentwatch: ContentWatchField
  },
  use: {
    tooltip: Tooltip
  }
})
