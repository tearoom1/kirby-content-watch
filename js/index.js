/* global panel */

import ContentHistory from './components/ContentHistory.vue'

panel.plugin('tearoom1/content-history', {
  components: {
    'content-history': ContentHistory
  }
})
