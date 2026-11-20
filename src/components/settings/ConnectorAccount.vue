<template>
  <form class="connector-account" @submit.prevent="save(false)">
    <NcTextField v-model="user" :label="t(appId, 'Views database username')" />
    <NcTextField v-model="accountHost" :label="t(appId, 'MariaDB account host')" />
    <NcButton type="submit" variant="primary" :disabled="running || !user.trim() || !accountHost.trim()">
      {{ t(appId, 'Save views database account') }}
    </NcButton>
    <NcButton type="button" :disabled="running" @click="save(true)">
      {{ t(appId, 'Use Nextcloud database account') }}
    </NcButton>
  </form>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { ref } from 'vue'
import { appName as appId } from '../../config.ts'
import { setAdminSetting } from '../../services/admin-settings.ts'
import { handleSettingError, showSettingFeedback } from '../../services/setting-feedback.ts'

const props = defineProps<{ account?: { user?: string, accountHost?: string } }>()
const emit = defineEmits<{ error: [error: Error] }>()
const user = ref(props.account?.user ?? '')
const accountHost = ref(props.account?.accountHost ?? 'localhost')
const running = ref(false)

const save = async (reset: boolean) => {
  running.value = true
  try {
    const response = await setAdminSetting(
      'cloudConnectorAccount',
      reset ? null : { user: user.value.trim(), accountHost: accountHost.value.trim() },
    )
    showSettingFeedback(response.data, t(appId, 'Views database account'))
    if (reset) {
      user.value = ''
      accountHost.value = 'localhost'
    }
  } catch (error) {
    handleSettingError(error, (forwarded) => emit('error', forwarded))
  } finally {
    running.value = false
  }
}
</script>

<style scoped>
.connector-account {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: calc(var(--default-grid-baseline) * 2);
}
</style>
