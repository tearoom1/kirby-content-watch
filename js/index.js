/* global panel */

import ContentWatch from './components/ContentWatch.vue'
import ContentWatchSection from './components/ContentWatchSection.vue'
import Tooltip from './tooltip.js'
import './diff-table.css'
import './tooltip.css'

panel.plugin('tearoom1/kirby-content-watch', {
  components: {
    'content-watch': ContentWatch
  },
  sections: {
    contentwatch: ContentWatchSection
  },
  use: {
    tooltip: Tooltip
  }
})
