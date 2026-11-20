<template>
  <form class="connector-credentials" autocomplete="off" @submit.prevent="save(false)">
    <NcTextField v-model="user" :label="t(appId, 'Connector database username')" autocomplete="off" />
    <NcPasswordField v-model="password" :label="t(appId, 'Connector database password')" autocomplete="new-password" />
    <NcButton type="submit" variant="primary" :disabled="running || !user.trim()">
      {{ t(appId, 'Save connector credentials') }}
    </NcButton>
    <NcButton type="button" :disabled="running" @click="save(true)">
      {{ t(appId, 'Use Nextcloud database credentials') }}
    </NcButton>
  </form>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcPasswordField, NcTextField } from '@nextcloud/vue'
import { ref } from 'vue'
import { appName as appId } from '../../config.ts'
import { setAdminSetting } from '../../services/admin-settings.ts'
import { showSettingError, showSettingFeedback } from '../../services/setting-feedback.ts'
import { showError } from '../../toolkit/util/toasts.ts'

const props = defineProps<{ setting: string }>()
const user = ref('')
const password = ref('')
const running = ref(false)

const save = async (reset: boolean) => {
  running.value = true
  try {
    const response = await setAdminSetting(props.setting, reset ? null : { user: user.value.trim(), password: password.value })
    showSettingFeedback(response.data, t(appId, 'Connector database credentials'))
    user.value = ''
  } catch (error) {
    // Do not forward the Axios error: it can contain the submitted password.
    if (!showSettingError(error)) {
      showError(t(appId, 'Unable to save connector database credentials.'))
    }
  } finally {
    password.value = ''
    running.value = false
  }
}
</script>

<style scoped>
.connector-credentials {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: calc(var(--default-grid-baseline) * 2);
}
</style>
