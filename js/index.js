/* global panel */

import ContentWatch from './components/ContentWatch.vue'
import ContentWatchSection from './components/ContentWatchSection.vue'
import './diff-table.css'

panel.plugin('tearoom1/kirby-content-watch', {
  components: {
    'content-watch': ContentWatch
  },
  sections: {
    contentwatch: ContentWatchSection
  }
})
