/* global panel */

import ContentWatch from './components/ContentWatch.vue'
import './diff-table.css'

panel.plugin('tearoom1/content-watch', {
  components: {
    'content-watch': ContentWatch
  }
})
