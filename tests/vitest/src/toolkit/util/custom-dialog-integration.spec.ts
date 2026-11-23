/**
 * @copyright Copyright (c) 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { Dialog } from '~/src/toolkit/util/custom-dialog.ts';

describe('custom dialog with Nextcloud Vue', () => {
  it('mounts content and classes in the real modal and closes through its button', async () => {
    const ready = vi.fn();
    const callback = vi.fn();
    const result = new Dialog({
      name: 'Title',
      text: '',
      html: '<label><input type="radio" checked>Choice</label>',
      severity: 'info',
      dialogClasses: ['legacy-dialog', 'fit-content-width'],
      onReady: ready,
      buttons: [{ label: 'Apply', callback }],
    }).show();
    await nextTick();

    const container = document.querySelector<HTMLElement>('.modal-container.legacy-dialog.fit-content-width');
    expect(container).not.toBeNull();
    expect(ready).toHaveBeenCalledExactlyOnceWith(container);
    expect(container?.querySelector<HTMLInputElement>('input')?.checked).toBe(true);
    const apply = Array.from(container!.querySelectorAll('button')).find((element) => element.textContent?.includes('Apply'));
    expect(apply).toBeDefined();
    apply!.click();
    await expect(result).resolves.toBeUndefined();
    expect(callback).toHaveBeenCalledOnce();
    expect(document.querySelector('.modal-container.legacy-dialog')).toBeNull();
  });
});
