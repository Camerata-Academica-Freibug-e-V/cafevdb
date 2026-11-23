import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SettingsNavigation from '../../../../src/components/settings/SettingsNavigation.vue';
import { getAdminSettingsTree, getPersonalSettingsEntry } from '../../../../src/components/settings/settings-tree.ts';

vi.mock('../../../../src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('@nextcloud/vue', () => ({
  NcButton: { template: '<button><slot name="icon" /></button>' },
}));

afterEach(() => {
  vi.unstubAllGlobals();
  window.localStorage.clear();
  document.body.innerHTML = '';
});

describe('settings page structure', () => {
  it('keeps only preferences and the user encryption key in personal settings', () => {
    const entry = getPersonalSettingsEntry();
    expect(entry.key).toBe('user');
    expect(entry.children.map(({ name }) => name)).toEqual(['Personal settings', 'Encryption key']);
  });

  it('keeps administration through Access Control ahead of all moved sections', () => {
    const tree = getAdminSettingsTree({ isAdmin: false, isSubAdmin: true, orchestraSettingsEnabled: true });
    expect(tree.map(({ key }) => key)).toEqual([
      'backend',
      'recryption',
      'access-control',
      'general',
      'orchestra',
      'sharing',
      'email',
      'cms',
      'development',
      'finance',
      'office-fonts',
      'problem-reports',
    ]);
    const ids = tree.flatMap((entry) => [entry.id, ...entry.children.map(({ id }) => id)]);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('omits encrypted configuration but retains existing delegated sections without a valid key', () => {
    const tree = getAdminSettingsTree({ isAdmin: false, isSubAdmin: true, orchestraSettingsEnabled: false });
    expect(tree.map(({ key }) => key)).toEqual([
      'backend',
      'configure-user-backend',
      'recryption',
      'access-control',
      'finance',
      'office-fonts',
      'problem-reports',
    ]);
  });

  it('exposes only backend recovery to instance admins without orchestra access', () => {
    expect(getAdminSettingsTree({ isAdmin: true, isSubAdmin: false, orchestraSettingsEnabled: false })
      .map(({ key }) => key)).toEqual(['backend', 'configure-user-backend']);
    expect(getAdminSettingsTree({ isAdmin: false, isSubAdmin: false, orchestraSettingsEnabled: false })
      .map(({ key }) => key)).toEqual(['backend']);
  });
});

describe('settings sidebar', () => {
  const render = (narrow = false) => {
    const observe = vi.fn();
    const disconnect = vi.fn();
    vi.stubGlobal('IntersectionObserver', class {
      observe = observe;
      disconnect = disconnect;
    });
    vi.stubGlobal('matchMedia', () => ({
      matches: narrow,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }));
    const tree = getAdminSettingsTree({ isAdmin: false, isSubAdmin: true, orchestraSettingsEnabled: true });
    for (const id of tree.flatMap((entry) => [entry.id, ...entry.children.map(({ id }) => id)])) {
      const section = document.createElement('section');
      section.id = id;
      document.body.append(section);
    }
    return { wrapper: mount(SettingsNavigation, { props: { tree } }), tree, observe, disconnect };
  };

  it('links to and observes every visible section, and scrolls to the selected subsection', async () => {
    const { wrapper, tree, observe, disconnect } = render();
    const ids = tree.flatMap((entry) => [entry.id, ...entry.children.map(({ id }) => id)]);
    expect(wrapper.findAll('nav a').map((link) => link.attributes('href'))).toEqual(ids.map((id) => '#' + id));
    expect(observe).toHaveBeenCalledTimes(ids.length);
    const target = document.getElementById('admin-settings-orchestra-bank-account')!;
    target.scrollIntoView = vi.fn();
    await wrapper.get('a[href="#admin-settings-orchestra-bank-account"]').trigger('click');
    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' });
    wrapper.unmount();
    expect(disconnect).toHaveBeenCalledOnce();
  });

  it('persists desktop collapse independently of the old personal sidebar', async () => {
    const { wrapper } = render();
    await wrapper.get('button').trigger('click');
    expect(wrapper.get('nav').isVisible()).toBe(false);
    expect(window.localStorage.getItem('cafevdb:admin-settings-navigation-hidden')).toBe('1');
    wrapper.unmount();
  });

  it('starts collapsed on narrow screens and closes after choosing a section', async () => {
    const { wrapper } = render(true);
    expect(wrapper.get('nav').isVisible()).toBe(false);
    await wrapper.get('button').trigger('click');
    expect(wrapper.get('button').attributes('aria-expanded')).toBe('true');
    expect(wrapper.get('nav').attributes('style') ?? '').not.toContain('display: none');
    document.getElementById('admin-settings-access-control')!.scrollIntoView = vi.fn();
    await wrapper.get('a[href="#admin-settings-access-control"]').trigger('click');
    expect(wrapper.get('nav').isVisible()).toBe(false);
    wrapper.unmount();
  });
});
