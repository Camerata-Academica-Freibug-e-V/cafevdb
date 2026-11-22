/**
 * CAFEVDB -- Orchestra management application.
 *
 * @copyright 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCloudUsersGroupsStore } from '~/src/stores/cloud-users-groups.ts';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@nextcloud/axios', () => ({ default: { get } }));
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: vi.fn() }));
vi.mock('~/src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('@nextcloud/router', () => ({
  generateUrl: (path: string) => path,
  generateOcsUrl: (path: string) => `/ocs/${path}`,
}));

describe('cloud users and groups store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('searches app group details and preserves cached group references', async () => {
    const group = {
      id: 'orchestra',
      displayname: 'Orchestra',
      usercount: 2,
      disabled: 0,
      canAdd: true,
      canRemove: true,
      backends: ['Database'],
    };
    get.mockResolvedValueOnce({ data: { groups: [group] } });
    const store = useCloudUsersGroupsStore();
    await store.findGroups('a & b', null);
    expect(get).toHaveBeenCalledWith('/apps/cafevdb/settings/cloud-groups', {
      params: { search: 'a & b', limit: 10, offset: 0 },
    });
    const cached = store.groups.orchestra;
    expect(cached.backends).toEqual(['Database']);

    get.mockResolvedValueOnce({ data: { groups: [{ ...group, usercount: 3 }] } });
    await store.findGroups('orchestra', null);
    expect(store.groups.orchestra).toBe(cached);
    expect(cached.usercount).toBe(3);
    expect(typeof cached.getUsers).toBe('function');
  });

  it('reports endpoint failures', async () => {
    const error = new Error('Forbidden');
    get.mockRejectedValueOnce(error);
    const handler = vi.fn();
    const store = useCloudUsersGroupsStore();
    await store.findGroups('', handler);
    expect(handler).toHaveBeenCalledWith(error);
  });

  it('encodes group identifiers when requesting their users', async () => {
    get.mockResolvedValueOnce({ data: { ocs: { data: { users: ['alice'] } } } });
    const store = useCloudUsersGroupsStore();
    expect(await store.getGroupUsers('team/a & b', null)).toEqual(['alice']);
    expect(get).toHaveBeenCalledWith('/ocs/cloud/groups/team%2Fa%20%26%20b/users');
  });
});
