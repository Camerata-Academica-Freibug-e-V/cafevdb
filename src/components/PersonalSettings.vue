<!--
 - Orchestra member, musicion and project management application.
 -
 - CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 -
 - @author Claus-Justus Heine
 - @copyright 2011-2016, 2020-2026 Claus-Justus Heine <himself@claus-justus-heine.de>
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -->
<template>
  <div class="templateroot personal-settings">
    <UserSettings :entry="getPersonalSettingsEntry()" @error="handleSettingsError" />
    <AboutSettings :entry="getAboutSettingsEntry()" />
    <div v-if="!!appError" class="flex-container flex-justify-center">
      <ErrorPageModal :show="!!appError"
                      :error="appError"
                      @update:show="appError = null"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { onUnmounted, ref } from 'vue'
import ErrorPageModal from './ErrorPageModal.vue'
import AboutSettings from './settings/AboutSettings.vue'
import UserSettings from './settings/UserSettings.vue'
import { appName as appId } from '../config.ts'
import useErrorHandlerStore from '../stores/error-handler.ts'
import { AppError } from '../toolkit/types/errors.ts'
import Console from '../util/console.ts'
import { getAboutSettingsEntry, getPersonalSettingsEntry } from './settings/settings-tree.ts'

const COMPONENT_NAME = 'PersonalSettings'
const logger = new Console(COMPONENT_NAME)

const errorHandlerProvider = useErrorHandlerStore()

const appError = ref<null | AppError>(null)
const errorHandler = <E extends AppError>(error: E) => {
  logger.debug('TOP LEVEL ERROR', error)
  if (!error.cause) {
    appError.value = new AppError({ component: COMPONENT_NAME }, t(appId, 'Top-Level Error'), { cause: error })
  } else {
    appError.value = error
  }
}
errorHandlerProvider.pushHandler(errorHandler)

const handleSettingsError = (error: Error) => {
  errorHandler(new AppError(
    { component: COMPONENT_NAME },
    t(appId, 'Unable to save personal setting.'),
    { cause: error },
  ))
}

onUnmounted(() => {
  errorHandlerProvider.popHandler()
})
</script>

<style lang="scss" scoped>
.personal-settings {
  min-width: 0;
}
</style>
