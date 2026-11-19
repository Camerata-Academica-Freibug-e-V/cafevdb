/*
 * Orchestra member, musicion and project management application.
 *
 * CAFEVDB -- Camerata Academica Freiburg e.V. DataBase.
 *
 * @author Claus-Justus Heine
 * @copyright 2011-2016, 2020-2026 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.

 */

import { translate as t } from '@nextcloud/l10n';
import { appName as appId } from '../../config.ts';

export interface NavigationEntry {
  id: string;
  name: string;
}

export interface TabEntry extends NavigationEntry {
  key: string;
  children: NavigationEntry[];
}

/**
 * The personal page has no administrative sections or sidebar.
 */
export const getPersonalSettingsEntry = (): TabEntry => ({
  key: 'user',
  id: 'personal-settings-user',
  name: t(appId, 'Personal'),
  children: [
    { id: 'personal-settings-user-personal-settings', name: t(appId, 'Personal settings') },
    { id: 'personal-settings-user-encryption-key', name: t(appId, 'Encryption key') },
  ],
});

export const getAboutSettingsEntry = (): TabEntry => ({
  key: 'about',
  id: 'admin-settings-about',
  name: t(appId, 'About'),
  children: [],
});

/**
 * Single source of truth for admin section ordering and sidebar visibility.
 *
 * @param permissions Current orchestra permissions and encryption-key availability.
 */
export const getAdminSettingsTree = (permissions: {
  isSubAdmin: boolean;
  orchestraSettingsEnabled: boolean;
}): TabEntry[] => {
  const entry = (key: string, name: string): TabEntry => ({
    key,
    id: 'admin-settings-' + key,
    name,
    children: [],
  });
  const top = [
    entry('backend', t(appId, 'Camerata DB')),
    ...(permissions.isSubAdmin
      ? [
        entry('configure-user-backend', t(appId, 'Configure User Backend')),
        entry('recryption', t(appId, 'Recryption Requests')),
        entry('access-control', t(appId, 'Access Control')),
      ]
      : []),
  ];
  const orchestra: TabEntry[] = [
    {
      key: 'general',
      id: 'admin-settings-general',
      name: t(appId, 'General'),
      children: [
        { id: 'general-settings', name: t(appId, 'General settings') },
        { id: 'encryption-settings', name: t(appId, 'Encryption settings') },
        { id: 'database-settings', name: t(appId, 'Database settings') },
      ],
    },
    {
      key: 'orchestra',
      id: 'admin-settings-orchestra',
      name: t(appId, 'Orchestra'),
      children: [
        { id: 'street-address', name: t(appId, 'Street Address') },
        { id: 'bank-account', name: t(appId, 'Bank Account') },
        { id: 'document-templates', name: t(appId, 'Document templates') },
        { id: 'executive-board-and-club-members', name: t(appId, 'Executive board and club members') },
      ],
    },
    {
      key: 'sharing',
      id: 'admin-settings-sharing',
      name: t(appId, 'Sharing'),
      children: [
        { id: 'share-owner', name: t(appId, 'Share owner') },
        { id: 'calendars', name: t(appId, 'Calendars') },
        { id: 'contacts', name: t(appId, 'Contacts') },
        { id: 'shared-folder', name: t(appId, 'Shared folder') },
        { id: 'members-as-cloud-users', name: t(appId, 'Members as Cloud-Users') },
        { id: 'wopi-credentials-view', name: t(appId, 'WOPI access to DB-Storage') },
      ],
    },
    {
      key: 'email',
      id: 'admin-settings-email',
      name: t(appId, 'Email'),
      children: [
        { id: 'server-settings', name: t(appId, 'Email Server and Account') },
        { id: 'bulk-email-composition', name: t(appId, 'Bulk Email Composition') },
        { id: 'bulk-email-privacy-notice', name: t(appId, 'Bulk Email Privacy Notice') },
        { id: 'mailing-list-service', name: t(appId, 'Mailing List Service') },
        { id: 'test-settings', name: t(appId, 'Test Settings') },
      ],
    },
    {
      key: 'cms',
      id: 'admin-settings-cms',
      name: t(appId, 'CMS'),
      children: [
        { id: 'redaxo-categories', name: t(appId, 'Redaxo Categories') },
        { id: 'redaxo-templates', name: t(appId, 'Redaxo Templates') },
        { id: 'redaxo-modules', name: t(appId, 'Redaxo Modules') },
      ],
    },
    {
      key: 'development',
      id: 'admin-settings-development',
      name: t(appId, 'Development'),
      children: [
        { id: 'links', name: t(appId, 'Links') },
      ],
    },
  ];
  return [
    ...top,
    ...(permissions.orchestraSettingsEnabled ? orchestra : []),
    ...(permissions.isSubAdmin
      ? [
        entry('finance', t(appId, 'Finance')),
        entry('office-fonts', t(appId, 'Configure Office Fonts for Office Exports')),
        entry('problem-reports', t(appId, 'Configure Problem Report Handling')),
      ]
      : []),
    getAboutSettingsEntry(),
  ]
    .map((tab) => ({
      ...tab,
      children: tab.children.map((child) => ({ ...child, id: tab.id + '-' + child.id })),
    }));
};
