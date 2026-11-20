import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectorAccount from '../../../../src/components/settings/ConnectorAccount.vue';
import { setAdminSetting } from '../../../../src/services/admin-settings.ts';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('@nextcloud/vue', () => ({
  NcTextField: {
    props: ['modelValue', 'label'],
    emits: ['update:modelValue'],
    template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
  },
  NcButton: { template: '<button><slot /></button>' },
}));
vi.mock('../../../../src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('../../../../src/services/admin-settings.ts', () => ({ setAdminSetting: vi.fn() }));
vi.mock('../../../../src/services/setting-feedback.ts', () => ({
  handleSettingError: vi.fn(),
  showSettingFeedback: vi.fn(),
}));

describe('Connector account', () => {
  beforeEach(() => vi.resetAllMocks());

  it('saves only the username and MariaDB account host', async () => {
    vi.mocked(setAdminSetting).mockResolvedValue({ data: { messages: ['Saved'] } } as never);
    const wrapper = mount(ConnectorAccount, { props: { account: { user: 'previous', accountHost: 'localhost' } } });
    await wrapper.get('input[aria-label="Views database username"]').setValue(' restricted ');
    await wrapper.get('input[aria-label="MariaDB account host"]').setValue(' % ');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(setAdminSetting).toHaveBeenCalledWith('cloudConnectorAccount', { user: 'restricted', accountHost: '%' });
    expect(wrapper.find('input[type="password"]').exists()).toBe(false);
  });

  it('can restore the Nextcloud account', async () => {
    vi.mocked(setAdminSetting).mockResolvedValue({ data: { messages: ['Saved'] } } as never);
    const wrapper = mount(ConnectorAccount, { props: { account: { user: 'restricted', accountHost: '%' } } });
    await wrapper.get('button[type="button"]').trigger('click');
    await flushPromises();

    expect(setAdminSetting).toHaveBeenCalledWith('cloudConnectorAccount', null);
    expect((wrapper.get('input[aria-label="Views database username"]').element as HTMLInputElement).value).toBe('');
  });
});
