/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import $ from 'jquery';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { globalState } = vi.hoisted(() => ({ globalState: { language: 'en' } }));
vi.mock('~/src/config.ts', () => ({ appName: 'cafevdb' }));
vi.mock('~/src/app/globals.ts', () => ({ globalState }));
vi.mock('~/src/app/jquery.ts', async () => {
  const { default: jquery } = await import('jquery');
  return { default: jquery, jq: jquery };
});
vi.mock('jquery-ui/ui/version', () => ({}));
vi.mock('jquery-ui/ui/widget', () => ({}));
vi.mock('jquery-ui/ui/widgets/datepicker', () => ({}));
vi.mock('jquery-datetimepicker/build/jquery.datetimepicker.full.js', () => ({}));
vi.mock('jquery-ui/ui/i18n/datepicker-de.js', async () => {
  const { default: jquery } = await import('jquery');
  jquery.datepicker.regional.de = { dateFormat: 'dd.mm.yy' };
  return {};
});
vi.mock('jquery-ui/ui/i18n/datepicker-fr.js', () => {
  throw new Error('locale import failed');
});

describe('datetimepicker language initialization', () => {
  const defaultDatepicker = vi.fn();
  const defaultDatetimepicker = vi.fn();
  const setDefaults = vi.fn();
  const setLocale = vi.fn();
  let logError: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
    $.fn.datepicker = defaultDatepicker;
    $.fn.datetimepicker = defaultDatetimepicker;
    $.datepicker = {
      regional: { '': { dateFormat: 'mm/dd/yy' } },
      setDefaults,
    } as unknown as typeof $.datepicker;
    $.datetimepicker = { setLocale };
    logError = vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  it.each([
    { language: 'en', format: 'm/d/Y', loaded: false },
    { language: 'fr', format: 'm/d/Y', loaded: false },
    { language: 'de', format: 'd.m.Y', loaded: true },
  ])('initializes with $language locale availability', async ({ language, format, loaded }) => {
    globalState.language = language;
    await import('~/src/app/jquery-datetimepicker.ts');
    await vi.waitFor(() => expect(setDefaults).toHaveBeenCalledOnce());

    expect(logError).toHaveBeenCalledTimes(loaded ? 0 : 1);
    expect(setLocale).toHaveBeenCalledTimes(loaded ? 1 : 0);
    if (loaded) {
      expect(setLocale).toHaveBeenCalledWith(language);
    }
    expect(setDefaults.mock.calls[0][0]).toMatchObject({
      dateFormat: loaded ? 'dd.mm.yy' : 'mm/dd/yy',
      beforeShow: expect.any(Function),
      onSelect: expect.any(Function),
    });

    const input = $('<input>');
    input.datetimepicker({ step: 10 });
    expect(defaultDatetimepicker).toHaveBeenCalledWith(expect.objectContaining({
      format: `${format}, H:i`,
      formatDate: format,
      formatTime: 'H:i',
      step: 10,
      onShow: expect.any(Function),
      onClose: expect.any(Function),
    }));
    logError.mockRestore();
  });
});
