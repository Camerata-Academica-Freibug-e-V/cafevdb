import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectorCredentials from '../../../../src/components/settings/ConnectorCredentials.vue';
import { setAdminSetting } from '../../../../src/services/admin-settings.ts';
import { showSettingError } from '../../../../src/services/setting-feedback.ts';
import { showError } from '../../../../src/toolkit/util/toasts.ts';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('@nextcloud/vue', () => {
  const field = {
    props: ['modelValue', 'label'],
    emits: ['update:modelValue'],
    template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
  };
  return {
    NcTextField: field,
    NcPasswordField: field,
    NcButton: { template: '<button><slot /></button>' },
  };
});
vi.mock('../../../../src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('../../../../src/services/admin-settings.ts', () => ({ setAdminSetting: vi.fn() }));
vi.mock('../../../../src/services/setting-feedback.ts', () => ({
  showSettingError: vi.fn(),
  showSettingFeedback: vi.fn(),
}));
vi.mock('../../../../src/toolkit/util/toasts.ts', () => ({ showError: vi.fn() }));

describe('Connector credentials', () => {
  beforeEach(() => vi.resetAllMocks());

  it('submits the credentials and clears the password after saving', async () => {
    vi.mocked(setAdminSetting).mockResolvedValue({ data: { messages: ['Saved'] } } as never);
    const wrapper = mount(ConnectorCredentials, { props: { setting: 'wopiConnectorCredentials' } });
    await wrapper.get('input[aria-label="Connector database username"]').setValue(' restricted ');
    await wrapper.get('input[aria-label="Connector database password"]').setValue('test-only');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(setAdminSetting).toHaveBeenCalledWith('wopiConnectorCredentials', { user: 'restricted', password: 'test-only' });
    expect((wrapper.get('input[aria-label="Connector database password"]').element as HTMLInputElement).value).toBe('');
  });

  it('does not forward a failed request containing the password', async () => {
    const error = new Error('test-only');
    vi.mocked(setAdminSetting).mockRejectedValue(error);
    vi.mocked(showSettingError).mockReturnValue(false);
    const wrapper = mount(ConnectorCredentials, { props: { setting: 'cloudConnectorCredentials' } });
    await wrapper.get('input[aria-label="Connector database username"]').setValue('restricted');
    await wrapper.get('input[aria-label="Connector database password"]').setValue('test-only');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(showError).toHaveBeenCalledWith('Unable to save connector database credentials.');
    expect((wrapper.get('input[aria-label="Connector database password"]').element as HTMLInputElement).value).toBe('');
  });
});
