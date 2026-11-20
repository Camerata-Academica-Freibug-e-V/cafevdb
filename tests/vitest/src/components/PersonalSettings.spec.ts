import { mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { describe, expect, it, vi } from 'vitest';
import PersonalSettings from '../../../../src/components/PersonalSettings.vue';
import UserSettings from '../../../../src/components/settings/UserSettings.vue';

vi.mock('../../../../src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('../../../../src/util/console.ts', () => ({ default: class { debug() {} } }));
vi.mock('../../../../src/components/ErrorPageModal.vue', () => ({ default: { template: '<div />' } }));
vi.mock('../../../../src/components/settings/AboutSettings.vue', () => ({
  default: { props: ['entry'], template: '<section :id="entry.id">{{ entry.name }}</section>' },
}));
vi.mock('../../../../src/components/settings/UserSettings.vue', () => ({
  default: { props: ['entry'], template: '<section :id="entry.id" />' },
}));

describe('personal settings page', () => {
  it('renders personal settings and app information without administrative navigation', () => {
    const wrapper = mount(PersonalSettings, { global: { plugins: [createPinia()] } });
    expect(wrapper.findAll('section').map((section) => section.attributes('id')))
      .toEqual(['personal-settings-user', 'personal-settings-about']);
    expect(wrapper.get('#personal-settings-about').text()).toBe('About');
    expect(wrapper.getComponent(UserSettings).props('entry').children).toHaveLength(2);
    expect(wrapper.find('nav').exists()).toBe(false);
    wrapper.unmount();
  });
});
