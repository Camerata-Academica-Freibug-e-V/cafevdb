import type { EventArgs } from '@rotdrop/async-nextcloud-event-bus';
import type { GET_VUE_COMPONENT } from '../../../src/event-bus-events.ts';
import type { ComponentProps } from '../../../src/mountable-component-names.ts';

type Request = EventArgs[typeof GET_VUE_COMPONENT];
type Assert<T extends true> = T;
type IsAssignable<T, U> = [T] extends [U] ? true : false;

type DualListboxRequest = {
  name: 'DualListbox';
  propsData: ComponentProps['DualListbox'];
};
type ExportMenuRequest = {
  name: 'PmeExportMenu';
  propsData: ComponentProps['PmeExportMenu'];
};
type MismatchedRequest = {
  name: 'DualListbox';
  propsData: ComponentProps['PmeExportMenu'];
};

export type ValidDualListbox = Assert<IsAssignable<DualListboxRequest, Request>>;
export type ValidExportMenu = Assert<IsAssignable<ExportMenuRequest, Request>>;
// @ts-expect-error A component name cannot be paired with another component's props.
export type InvalidPair = Assert<IsAssignable<MismatchedRequest, Request>>;
export type NarrowedProps = Assert<IsAssignable<
  Extract<Request, { name: 'DualListbox' }>['propsData'],
  ComponentProps['DualListbox']
>>;
