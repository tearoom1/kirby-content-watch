/* global panel */

import ContentWatch from './components/ContentWatch.vue'
import './diff-table.css'

panel.plugin('tearoom1/kirby-content-watch', {
  components: {
    'content-watch': ContentWatch
  }
})
