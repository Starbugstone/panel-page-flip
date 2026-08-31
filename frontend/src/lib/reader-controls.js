// The reader's controls float over the page, inside the very element the
// gesture, pan and click listeners are all bound to. An event that landed on
// one of them belongs to that control and nothing else — the top-right cluster
// sits in the same place as the next-page tap zone, so without this every
// settings tap would also turn a page.
//
// One list rather than three. While each listener carried its own, they had
// already drifted: a click on a `[role="button"]` was a control to the gesture
// layer, and a page turn to the click handler on the same element.
const CONTROL_SELECTOR = 'button, a, input, select, textarea, label, [role="button"],'
  + ' [role="switch"], [role="slider"], [role="dialog"], [contenteditable="true"]';

/**
 * Whether an event landed on a reader control rather than on the page behind it.
 *
 * Takes the event target rather than the event so it can be asked about a
 * pointer, a mouse and a click alike; those are three listeners with one
 * question between them.
 */
export function isReaderControl(target) {
  return Boolean(target?.closest?.(CONTROL_SELECTOR));
}
