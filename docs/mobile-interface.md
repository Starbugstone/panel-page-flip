# Mobile user interface

The signed-in user interface supports viewports down to 320 CSS pixels without
document-level horizontal scrolling. The administrator interface is outside
this contract: it is currently intended for the single operator rather than
general mobile use.

## Layout contract

- Page content keeps a small viewport gutter instead of touching the screen
  edge.
- Controls that cannot remain legible in one row stack or move onto a new row.
  Primary actions become full-width where that makes the tap target clearer.
- Long user-controlled values, including comic filenames and tag names, wrap or
  truncate inside their container rather than widening the page.
- Dialogs keep a viewport gutter and cap their height. Long dialog content
  scrolls inside the dialog so its controls remain reachable.
  Their grid column can shrink below its contents' intrinsic width, preventing
  a form or long label from clipping the right-hand side at 320 pixels.

Page widths, headings, colors and account forms follow the
[shared interface patterns](interface-patterns.md). The mobile navigation menu
uses the same destinations and current-page indication as desktop navigation;
choosing a destination leaves focus on the destination's main content.

## User-page behaviour

The library toolbar groups folders and sorting on the first compact row, keeps
the view selector together, and gives upload its own row on narrow screens. The
search field uses the available width, with Tags and Search sharing the next
row. The tag picker becomes a viewport-bound panel on phones, with a scrolling
tag list and a visible Apply action; from the small breakpoint upwards it
returns to an anchored popover. `Escape` closes the panel and returns focus to
the Tags trigger, while a pointer press outside closes it without stealing focus
from the target. Search, filter, clear, and remove controls all expose names to
assistive technology even when their visible control is only an icon.

The library table shows the selection, comic identity, reading progress and
actions at phone sizes. Lower-priority metadata moves into the comic cell or is
revealed again as the viewport grows, rather than forcing a desktop-width table
onto the screen.

Upload queues turn each file into a stacked row on phones, and long filenames
wrap. Both upload forms expose a native keyboard-accessible file chooser as well
as drag-and-drop. Invitation previews stack their cover above the description
on phones; sharing cards keep their complete action group reachable.
Sharing identity, redeem-code, pagination, and personal-tag controls stack or
expand to the available width. These layouts return to their compact horizontal
form at the existing tablet and desktop breakpoints.

## Scroll surfaces

Native scroll areas use one theme-aware treatment throughout the application.
Desktop scrollbars are slim, keep a transparent track, and use a rounded thumb
that shifts toward the primary colour on hover. Light and dark themes define
their own thumb contrast. Firefox receives the standard `scrollbar-width` and
`scrollbar-color` properties; Chromium and Safari receive the equivalent
WebKit pseudo-elements. Native touch scrolling remains unchanged.

## Regression coverage

Component tests assert the responsive class contract for the library toolbar,
search and tag picker, library table, uploads, sharing controls, personal tags,
pagination, badges and shared dialog primitives. Browser checks should include
at least a 320-pixel user viewport and a desktop viewport, with the tag picker
and a long dialog open, and should confirm that the document itself does not
scroll horizontally.
