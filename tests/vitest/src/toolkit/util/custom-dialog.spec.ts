/**
 * @copyright Copyright (c) 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { Dialog } from '~/src/toolkit/util/custom-dialog.ts';
import dialogAlert from '~/src/toolkit/util/dialog-alert.ts';
import dialogConfirm from '~/src/toolkit/util/dialog-confirm.ts';

vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }));
vi.mock('~/src/config.ts', () => ({ appName: 'cafevdb' }));

vi.mock('@nextcloud/vue/components/NcDialog', async () => {
  const { defineComponent, h } = await import('vue');
  return {
    default: defineComponent({
      props: ['name', 'message', 'buttons', 'dialogClasses'],
      emits: ['update:open'],
      setup(props, { slots, emit }) {
        return () => h('div', { class: 'modal-mask' }, [
          h('div', { class: ['dialog', props.dialogClasses] }, [
            h('h2', props.name),
            slots.default?.(),
            ...props.buttons.map((button: { label: string; callback: () => void }) =>
              h('button', { onClick: button.callback }, button.label)),
            h('button', { class: 'dismiss', onClick: () => emit('update:open', false) }, 'Dismiss'),
          ]),
        ]);
      },
    }),
  };
});

vi.mock('@nextcloud/vue/components/NcNoteCard', async () => {
  const { defineComponent, h } = await import('vue');
  return {
    default: defineComponent({
      props: ['type'],
      setup(props, { slots }) {
        return () => h('section', { 'data-severity': props.type }, props.type === 'error' ? undefined : slots.default?.());
      },
    }),
  };
});

const button = (container: Element, label: string): HTMLButtonElement => {
  const result = Array.from(container.querySelectorAll('button')).find((element) => element.textContent === label);
  if (!result) {
    throw new Error(`Button not found: ${label}`);
  }
  return result;
};

afterEach(() => {
  expect(document.querySelectorAll('.modal-mask')).toHaveLength(0);
});

describe('custom dialog', () => {
  it.each([undefined, 'warning' as const])('renders text safely with severity %s', async (severity) => {
    const clicked = vi.fn();
    const result = new Dialog({
      name: 'Title',
      text: '<strong>plain text</strong>',
      severity,
      buttons: [{ label: 'OK', callback: clicked }],
    }).show();
    const container = document.querySelector('.modal-mask')!;
    expect(container.textContent).toContain('<strong>plain text</strong>');
    expect(container.querySelector('strong')).toBeNull();
    if (severity) {
      expect(container.querySelector('[data-severity="warning"]')).not.toBeNull();
    }
    button(container, 'OK').click();
    await expect(result).resolves.toBeUndefined();
    expect(clicked).toHaveBeenCalledOnce();
  });

  it('mounts interactive HTML and per-dialog classes before ready, without affecting other dialogs', async () => {
    const ready = vi.fn();
    const first = new Dialog({
      name: 'First',
      text: '',
      html: '<label><input type="radio" name="choice" checked>Choice</label>',
      severity: 'info',
      dialogClasses: ['legacy-dialog', 'fit-content-width'],
      onReady: ready,
      buttons: [{ label: 'Apply', callback() {} }],
    }).show();
    const second = new Dialog({
      name: 'Second',
      text: 'Other',
      dialogClasses: ['other-dialog'],
      buttons: [{ label: 'OK', callback() {} }],
    }).show();
    const [firstContainer, secondContainer] = document.querySelectorAll<HTMLElement>('.modal-mask');
    await nextTick();
    expect(ready).toHaveBeenCalledOnce();
    expect(firstContainer.contains(ready.mock.calls[0][0])).toBe(true);
    expect(ready.mock.calls[0][0].querySelector('input')).not.toBeNull();
    expect(document.querySelector('.modal-container')).toBeNull();
    expect(firstContainer.classList.contains('fit-content-width')).toBe(true);
    expect(secondContainer.classList.contains('fit-content-width')).toBe(false);
    expect(firstContainer.querySelector<HTMLInputElement>('input')?.checked).toBe(true);
    expect(firstContainer.querySelector('[data-severity="info"] label')).not.toBeNull();
    button(firstContainer, 'Apply').click();
    button(secondContainer, 'OK').click();
    await Promise.all([first, second]);
  });

  it('renders HTML without severity and rejects dismissal', async () => {
    const result = new Dialog({
      name: 'Title',
      text: '',
      html: '<a href="/example">link</a>',
      buttons: [],
    }).show();
    const container = document.querySelector('.modal-mask')!;
    expect(container.querySelector('a')?.getAttribute('href')).toBe('/example');
    button(container, 'Dismiss').click();
    await expect(result).rejects.toThrow('Dialog closed');
  });

  it('does not fall back to text when explicitly given empty HTML', async () => {
    const result = new Dialog({
      name: 'Title',
      text: 'fallback',
      html: '',
      severity: 'info',
      buttons: [{ label: 'OK', callback() {} }],
    }).show();
    const container = document.querySelector('.modal-mask')!;
    expect(container.querySelector('[data-severity="info"]')?.textContent).toBe('');
    button(container, 'OK').click();
    await result;
  });

  it('renders HTML in toolkit alerts and respects confirmation choices', async () => {
    const alert = dialogAlert({ title: 'Alert', text: '<a href="/projects">Projects</a>', allowHtml: true });
    const alertContainer = document.querySelector('.modal-mask')!;
    expect(alertContainer.querySelector('a')?.textContent).toBe('Projects');
    button(alertContainer, 'close').click();
    await alert;

    const confirmation = dialogConfirm({ title: 'Confirm', text: '<b>Question</b>', allowHtml: true, defaultNo: true });
    const confirmContainer = document.querySelector('.modal-mask')!;
    expect(confirmContainer.querySelector('b')?.textContent).toBe('Question');
    button(confirmContainer, 'No').click();
    await expect(confirmation).resolves.toBe(false);

    const yes = dialogConfirm({ title: 'Confirm', text: 'Question' });
    button(document.querySelector('.modal-mask')!, 'Yes').click();
    await expect(yes).resolves.toBe(true);

    const dismissed = dialogConfirm({ title: 'Confirm', text: 'Question' });
    button(document.querySelector('.modal-mask')!, 'Dismiss').click();
    await expect(dismissed).rejects.toThrow('Dialog closed');
    await nextTick();
  });

  it('rejects with the initialization error and removes the dialog', async () => {
    const failure = new Error('Tooltip initialization failed');
    const result = new Dialog({
      name: 'Title',
      text: 'Text',
      buttons: [],
      onReady() { throw failure; },
    }).show();
    await expect(result).rejects.toMatchObject({
      message: 'Custom dialog initialization failed',
      cause: failure,
    });
    expect(document.querySelector('.modal-mask')).toBeNull();
  });

  it('rejects and cleans up if the content element is missing', async () => {
    const ready = vi.fn();
    const result = new Dialog({
      name: 'Title',
      text: 'Text',
      severity: 'error',
      buttons: [],
      onReady: ready,
    }).show();
    await expect(result).rejects.toMatchObject({
      message: 'Custom dialog initialization failed',
      cause: expect.objectContaining({ message: 'Custom dialog content not found' }),
    });
    expect(ready).not.toHaveBeenCalled();
    expect(document.querySelector('.modal-mask')).toBeNull();
  });

  it('does not initialize content after the dialog is closed', async () => {
    const ready = vi.fn();
    const result = new Dialog({
      name: 'Title',
      text: 'Text',
      buttons: [{ label: 'OK', callback() {} }],
      onReady: ready,
    }).show();
    button(document.querySelector('.modal-mask')!, 'OK').click();
    await result;
    await nextTick();
    expect(ready).not.toHaveBeenCalled();
  });
});
