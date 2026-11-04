<template>
  <div v-if="systemConfig" class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{ t('memories', 'Expose the user-accessible Memories albums as a read-only folder in each user’s Files.') }}
    </p>

    <NcCheckboxRadioSwitch
      type="switch"
      :model-value="systemConfig['memories.virtual_albums.enabled']"
      @update:model-value="update('memories.virtual_albums.enabled', $event)"
      >{{ t('memories', 'Enable virtual album storage') }}
    </NcCheckboxRadioSwitch>

    <NcTextField
      :label="t('memories', 'Virtual albums mountpoint (relative to Files)')"
      :label-visible="true"
      :model-value="systemConfig['memories.virtual_albums.mountpoint']"
      :disabled="!systemConfig['memories.virtual_albums.enabled']"
      @change="update('memories.virtual_albums.mountpoint', $event.target.value)"
    />
  </div>
</template>

<script setup lang="ts">
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import { t } from '@services/l10n';
import { useAdminContext } from '../admin-context';

defineOptions({
  name: 'VirtualAlbums',
  title: t('memories', 'Virtual Albums'),
});

const { systemConfig, update } = useAdminContext();
</script>
