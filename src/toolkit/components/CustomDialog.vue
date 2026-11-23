<!--
  - SPDX-FileCopyrightText: 2026 Claus-Justus Heine
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <NcDialog :dialogClasses="['nc-generic-dialog', ...dialogClasses]"
            :buttons="dialogButtons"
            :name="name"
            :message="text"
            @update:open="emit('close', false)"
  >
    <NcNoteCard v-if="severity" :type="severity">
      <div ref="content">
        <!-- HTML is deliberately opt-in and must be trusted by the caller. -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div v-if="html !== undefined" v-html="html" />
        <p v-else v-text="text" />
      </div>
    </NcNoteCard>
    <div v-else ref="content">
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div v-if="html !== undefined" v-html="html" />
      <p v-else v-text="text" />
    </div>
  </NcDialog>
</template>

<script setup lang="ts">
import type { IDialogButton, IDialogSeverity } from '@nextcloud/dialogs'

import { computed, onMounted, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

const props = withDefaults(defineProps<{
  name: string
  text: string
  html?: string
  buttons: IDialogButton[]
  severity?: IDialogSeverity
  dialogClasses?: string[]
  onReady?: (container: HTMLElement) => void
}>(), {
  dialogClasses: () => [],
})

const emit = defineEmits<{ close: [buttonClick: boolean] }>()
const content = ref<HTMLElement>()
const dialogButtons = computed(() => props.buttons.map((button) => ({
  ...button,
  callback() {
    button.callback()
    emit('close', true)
  },
})))

onMounted(() => {
  const container = content.value?.closest<HTMLElement>('.modal-container')
  if (!container) {
    throw new Error('Custom dialog modal container not found')
  }
  container.classList.add(...props.dialogClasses)
  props.onReady?.(container)
})
</script>

<style>
.nc-generic-dialog .dialog__actions {
  justify-content: space-between;
  min-width: calc(100% - 12px);
}
</style>
