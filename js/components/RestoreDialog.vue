<template>
  <!-- opened with panel.dialog.open() by the contentwatch field -->
  <k-dialog
    :visible="visible"
    :submit-button="$t('tearoom1.content-watch.restore.confirm.submit', {version})"
    theme="positive"
    icon="undo"
    @cancel="$emit('cancel')"
    @submit="$emit('submit')"
  >
    <k-text>
      <p>
        <strong>{{ $t('tearoom1.content-watch.restore.confirm.title', {version}) }}</strong>
        {{ language
          ? $t('tearoom1.content-watch.restore.confirm.text.language', {version, language})
          : $t('tearoom1.content-watch.restore.confirm.text', {version}) }}
      </p>
      <ul>
        <li>{{ $t('tearoom1.content-watch.restore.confirm.history') }}</li>
        <li v-if="language">{{ $t('tearoom1.content-watch.restore.confirm.languages') }}</li>
      </ul>
    </k-text>
  </k-dialog>
</template>

<script>
export default {
  // the Panel passes its dialog listeners, the events below are emitted explicitly
  inheritAttrs: false,

  props: {
    // name of the language of the version, null in single-language sites
    language: String,
    version: [Number, String],
    visible: Boolean
  },

  emits: ['cancel', 'submit']
};
</script>
