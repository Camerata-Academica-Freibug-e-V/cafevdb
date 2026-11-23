<template>
  <form class="connector-account" @submit.prevent="save(false)">
    <fieldset class="connector-account__identity">
      <legend>{{ t(appId, 'Views database account') }}</legend>
      <div class="connector-account__fields">
        <NcTextField v-model="user" :label="t(appId, 'Account username')" :placeholder="t(appId, 'e.g. connector')" />
        <span class="connector-account__separator" aria-hidden="true">@</span>
        <NcTextField v-model="accountHost" :label="t(appId, 'Account host')" :placeholder="t(appId, 'e.g. localhost or %')" />
      </div>
      <p v-if="user.trim() && accountHost.trim()" class="connector-account__preview">
        {{ t(appId, 'MariaDB account:') }} <code>{{ accountPreview }}</code>
      </p>
    </fieldset>
    <NcPasswordField v-model="password"
                     :label="t(appId, 'Views database password')"
                     :hint="t(appId, 'Leave blank to keep the current password.')"
                     autocomplete="new-password"
    />
    <NcButton type="submit" variant="primary" :disabled="running || !user.trim() || !accountHost.trim()">
      {{ t(appId, 'Save views database account') }}
    </NcButton>
    <NcButton type="button" :disabled="running" @click="save(true)">
      {{ t(appId, 'Use Nextcloud database account') }}
    </NcButton>
    <NcNoteCard v-for="warning in warnings" :key="warning" type="warning">
      {{ warning }}
    </NcNoteCard>
  </form>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import { appName as appId } from '../../config.ts'
import { setAdminSetting } from '../../services/admin-settings.ts'
import { showSettingError, showSettingFeedback } from '../../services/setting-feedback.ts'

const props = withDefaults(defineProps<{ account?: { user?: string, accountHost?: string }, setting?: string }>(), {
  account: () => ({ user: '', accountHost: 'localhost' }),
  setting: 'cloudConnectorAccount',
})
const emit = defineEmits<{ error: [error: Error] }>()
const user = ref(props.account?.user ?? '')
const accountHost = ref(props.account?.accountHost ?? 'localhost')
const password = ref('')
const warnings = ref<string[]>([])
const running = ref(false)
const accountPreview = computed(() => {
  const quote = (value: string) => `'${value.trim().replaceAll("'", "''")}'`
  return `${quote(user.value)}@${quote(accountHost.value)}`
})

const save = async (reset: boolean) => {
  running.value = true
  warnings.value = []
  try {
    const response = await setAdminSetting(
      props.setting,
      reset ? null : { user: user.value.trim(), accountHost: accountHost.value.trim(), password: password.value },
    )
    showSettingFeedback(response.data, t(appId, 'Views database account'))
    warnings.value = response.data.hints ?? []
    if (reset) {
      user.value = ''
      accountHost.value = 'localhost'
    }
  } catch (error) {
    if (!showSettingError(error)) {
      emit('error', new Error(t(appId, 'Unable to save the connector database account.')))
    }
  } finally {
    password.value = ''
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

.connector-account__identity {
  flex: 1 1 24rem;
  min-width: 0;
  border: 0;
  padding: 0;
  margin: 0;
}

.connector-account__fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: var(--default-grid-baseline);
}

.connector-account__preview {
  overflow-wrap: anywhere;
}

@media (max-width: 480px) {
  .connector-account__fields {
    grid-template-columns: minmax(0, 1fr);
  }

  .connector-account__separator {
    text-align: center;
  }
}
</style>
