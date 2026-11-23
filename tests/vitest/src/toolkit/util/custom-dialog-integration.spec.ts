/**
 * @copyright Copyright (c) 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { Dialog } from '~/src/toolkit/util/custom-dialog.ts';

async function waitForOpeningTransition() {
  await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(waitForOpeningTransition);

describe('custom dialog with Nextcloud Vue', () => {
  it('mounts content and classes in the real modal and closes through its button', async () => {
    const initialized = vi.fn();
    const ready = vi.fn((content: HTMLElement) => {
      content.querySelectorAll('.cloud-file-system-operations-wrapper .tooltip-auto').forEach(initialized);
    });
    const callback = vi.fn();
    const result = new Dialog({
      name: 'Title',
      text: '',
      html: '<div class="cloud-file-system-operations-wrapper"><label class="tooltip-auto"><input type="radio" checked>Choice</label></div>',
      severity: 'info',
      dialogClasses: ['legacy-dialog', 'fit-content-width'],
      onReady: ready,
      buttons: [{ label: 'Apply', callback }],
    }).show();
    await nextTick();

    const mask = document.querySelector<HTMLElement>('.modal-mask.legacy-dialog.fit-content-width');
    const container = mask?.querySelector('.modal-container');
    expect(container).toBeTruthy();
    expect(ready).toHaveBeenCalledOnce();
    expect(container?.contains(ready.mock.calls[0][0])).toBe(true);
    expect(ready.mock.calls[0][0].querySelector('input')?.checked).toBe(true);
    expect(initialized).toHaveBeenCalledOnce();
    const apply = Array.from(container!.querySelectorAll('button')).find((element) => element.textContent?.includes('Apply'));
    expect(apply).toBeDefined();
    apply!.click();
    await expect(result).resolves.toBeUndefined();
    expect(callback).toHaveBeenCalledOnce();
    expect(document.querySelector('.modal-mask.legacy-dialog')).toBeNull();
  });

  it('unmounts the real modal when initialization fails', async () => {
    const cause = new Error('Initialization failed');
    const result = new Dialog({
      name: 'Title',
      text: 'Text',
      buttons: [],
      onReady() { throw cause; },
    }).show();
    await expect(result).rejects.toMatchObject({ cause });
    expect(document.querySelector('.modal-mask')).toBeNull();
  });

  it('keeps the modal usable after its opening transition', async () => {
    const result = new Dialog({
      name: 'Title',
      text: 'Text',
      buttons: [{ label: 'Apply', callback() {} }],
    }).show();
    await waitForOpeningTransition();
    const mask = document.querySelector('.modal-mask');
    await vi.waitFor(() => expect(document.activeElement).toBe(mask));
    const apply = Array.from(document.querySelectorAll('button')).find((element) => element.textContent?.includes('Apply'));
    expect(apply).toBeDefined();
    apply!.click();
    await expect(result).resolves.toBeUndefined();
  });
});
