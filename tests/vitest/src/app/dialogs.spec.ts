/**
 * @copyright Copyright (c) 2026 Claus-Justus Heine
 * @license AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { compileString } from 'sass';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { attachDialogHandlers } from '~/src/app/dialogs.ts';
import $ from '~/src/app/jquery.ts';

vi.mock('~/src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }));
vi.mock('~/src/toolkit/util/custom-dialog.ts', () => ({ Dialog: class {} }));
vi.mock('variables.module.scss', () => ({ appNameTag: 'app-cafevdb' }));
vi.mock('dialogs.scss', () => ({}));

afterEach(() => {
  $('body').off('dblclick').removeData('cafevdbDialogHandlersAttached');
  document.body.replaceChildren();
});

describe('legacy dialog handlers', () => {
  it('toggles width and exception traces only in the selected dialog', () => {
    document.body.innerHTML = `
      <div class="modal-mask legacy-dialog app-cafevdb">
        <div class="modal-container"><h2 class="dialog__name">First</h2>
          <div class="exception error"><span class="trace"></span></div>
        </div>
      </div>
      <div class="modal-mask legacy-dialog app-cafevdb">
        <div class="modal-container"><h2 class="dialog__name">Second</h2>
          <div class="exception error"><span class="trace"></span></div>
        </div>
      </div>`;
    const masks = document.querySelectorAll<HTMLElement>('.modal-mask');
    attachDialogHandlers();
    $(masks[0]).find('.dialog__name').trigger('dblclick');
    expect(masks[0].classList.contains('maximize-width')).toBe(true);
    expect(masks[1].classList.contains('maximize-width')).toBe(false);
    $(masks[0]).find('.dialog__name').trigger('dblclick');
    expect(masks[0].classList.contains('maximize-width')).toBe(false);
    $(masks[0]).find('.exception.error').trigger('dblclick');
    expect(masks[0].querySelector('.trace')?.classList.contains('visible')).toBe(true);
    expect(masks[1].querySelector('.trace')?.classList.contains('visible')).toBe(false);
  });

  it.each(['maximize-width', 'fit-content-width'])('applies %s sizing to the modal container', (widthClass) => {
    const file = resolve('style/dialogs.scss');
    const source = readFileSync(file, 'utf8').replace(
      "@use './variables.scss' as *;",
      '$appNameTag: app-cafevdb; $appName: cafevdb; $hiddenCssClass: hidden;',
    );
    const style = document.createElement('style');
    style.textContent = compileString(source, { url: pathToFileURL(file) }).css;
    document.head.append(style);
    try {
      document.body.innerHTML = `<div class="modal-mask app-cafevdb legacy-dialog ${widthClass}"><div class="modal-container"></div></div>`;
      const container = document.querySelector('.modal-container')!;
      const rules = Array.from(style.sheet!.cssRules);
      expect(rules.some((rule) =>
        rule instanceof CSSStyleRule && rule.style.width === 'unset' && container.matches(rule.selectorText))).toBe(true);
    } finally {
      style.remove();
    }
  });
});
