import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectorAccount from '../../../../src/components/settings/ConnectorAccount.vue';
import { setAdminSetting } from '../../../../src/services/admin-settings.ts';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('@nextcloud/vue', () => ({
  NcTextField: {
    props: ['modelValue', 'label', 'placeholder'],
    emits: ['update:modelValue'],
    template: '<input :aria-label="label" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
  },
  NcPasswordField: {
    props: ['modelValue', 'label'],
    emits: ['update:modelValue'],
    template: '<input type="password" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
  },
  NcNoteCard: { template: '<aside><slot /></aside>' },
  NcButton: { template: '<button><slot /></button>' },
}));
vi.mock('../../../../src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('../../../../src/services/admin-settings.ts', () => ({ setAdminSetting: vi.fn() }));
vi.mock('../../../../src/services/setting-feedback.ts', () => ({
  showSettingError: vi.fn(() => false),
  showSettingFeedback: vi.fn(),
}));

describe('Connector account', () => {
  beforeEach(() => vi.resetAllMocks());

  it('saves the password write-only and displays configuration warnings', async () => {
    vi.mocked(setAdminSetting).mockResolvedValue({ data: { messages: ['Saved'], hints: ['Check user_sql'] } } as never);
    const wrapper = mount(ConnectorAccount, { props: { account: { user: 'previous', accountHost: 'localhost' } } });
    await wrapper.get('input[aria-label="Account username"]').setValue(' restricted ');
    await wrapper.get('input[aria-label="Account host"]').setValue(' % ');
    await wrapper.get('input[aria-label="Views database password"]').setValue('private password');
    expect(wrapper.get('.connector-account__preview code').text()).toBe("'restricted'@'%'");
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(setAdminSetting).toHaveBeenCalledWith('cloudConnectorAccount', { user: 'restricted', accountHost: '%', password: 'private password' });
    expect((wrapper.get('input[type="password"]').element as HTMLInputElement).value).toBe('');
    expect(wrapper.text()).toContain('Check user_sql');
  });

  it('does not forward request errors containing credentials', async () => {
    vi.mocked(setAdminSetting).mockRejectedValue(new Error('private password'));
    const wrapper = mount(ConnectorAccount);
    await wrapper.get('input[aria-label="Account username"]').setValue('restricted');
    await wrapper.get('input[type="password"]').setValue('private password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect((wrapper.emitted('error')?.[0]?.[0] as Error).message).not.toContain('private password');
    expect((wrapper.get('input[type="password"]').element as HTMLInputElement).value).toBe('');
  });

  it('can restore the Nextcloud account', async () => {
    vi.mocked(setAdminSetting).mockResolvedValue({ data: { messages: ['Saved'] } } as never);
    const wrapper = mount(ConnectorAccount, { props: { account: { user: 'restricted', accountHost: '%' } } });
    await wrapper.get('button[type="button"]').trigger('click');
    await flushPromises();

    expect(setAdminSetting).toHaveBeenCalledWith('cloudConnectorAccount', null);
    expect((wrapper.get('input[aria-label="Account username"]').element as HTMLInputElement).value).toBe('');
    expect(wrapper.find('.connector-account__preview').exists()).toBe(false);
  });

  it('shows examples and updates the account preview as the fields change', async () => {
    const wrapper = mount(ConnectorAccount);
    const user = wrapper.get('input[aria-label="Account username"]');
    const host = wrapper.get('input[aria-label="Account host"]');
    expect(user.attributes('placeholder')).toBe('e.g. connector');
    expect(host.attributes('placeholder')).toBe('e.g. localhost or %');
    expect(wrapper.find('.connector-account__preview').exists()).toBe(false);

    await user.setValue("o'brien");
    expect(wrapper.get('.connector-account__preview code').text()).toBe("'o''brien'@'localhost'");
    await host.setValue('client.example');
    expect(wrapper.get('.connector-account__preview code').text()).toBe("'o''brien'@'client.example'");
  });
});
