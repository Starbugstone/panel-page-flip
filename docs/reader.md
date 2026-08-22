# Reader settings and controls

Reader preferences belong to the signed-in account. They are loaded when the
reader opens and follow the user to another browser or device. If preferences
cannot be loaded, the reader stays usable with safe defaults.

Open **Reader settings** from the sliders button at the top-right of a page.
Changes apply immediately and save in the background. **Reset defaults** returns
every reader option to its default value.

## Page sizing

| Setting | Behaviour |
| --- | --- |
| Best fit | Shows the complete page inside the available reader area without changing its aspect ratio. This is the default. |
| Fit width | Uses the available width. Tall pages scroll vertically. |
| Fit height | Uses the available height without changing the page aspect ratio. |
| Original size | Uses the image's native dimensions. Oversized pages scroll horizontally or vertically. |

Changing page size does not reload the comic or reset reading progress. Changing
it while zoomed returns the page to its natural scale so the selected fit is
immediately clear.

## Reading modes and direction

| Setting | Behaviour |
| --- | --- |
| Single page | Shows one logical source page at a time. |
| Two pages | Shows facing pages on a suitable landscape desktop or tablet. The first page can stay alone as a cover, likely scanned spreads stay alone, and final odd pages remain readable. Narrow and portrait screens safely show one page without changing the saved preference. |
| Continuous scroll | Stacks pages in source order for native vertical scrolling. Nearby pages load around the viewport while distant pages keep their space without holding decoded artwork. |

**Reading direction** changes facing-page placement and physical tap/swipe
semantics. Right-to-left reading puts the later page on the left and makes a
left-edge tap or rightward swipe advance. Source page numbers, progress, the
Previous/Next labels and keyboard arrows remain logical and never reorder the
comic.

In two-page mode, **Show first page alone** controls whether page 1 is treated as
a cover. The page-number field always shows the canonical logical page; the
range beneath it identifies both pages in the visible spread.

## Reading with a finger

Touch reading is not the desktop reader with the mouse taken away. One gesture
model covers the whole page surface:

| Gesture | Action |
| --- | --- |
| Tap the left or right edge | Previous or next page in paged modes, adjusted for reading direction |
| Tap the middle | Show or hide the reader controls |
| Swipe left or right | Next or previous page in paged modes, adjusted for reading direction |
| Double tap | Zoom to readable width around what was tapped; again to come back |
| Pinch | Zoom around the middle of the two fingers |
| Drag while zoomed | Pan the page, never turn it |
| Drag a page taller than the screen | The browser's own scrolling, untouched |

The rules between them matter as much as the gestures themselves. A pinch never
becomes a page turn, however far the first finger travelled. A finger left over
from a pinch does nothing on its way off the glass. The first tap of a double
tap does not flash the controls before the zoom it was really asking for, and a
drag that wanders off axis is a scroll rather than a page turn.

Continuous mode leaves one-finger vertical scrolling native and does not attach
paged swipe navigation. Pinch, double tap and zoomed panning still work on each
loaded page.

A mouse keeps its click zones, its wheel and its hover. It has a cursor and does
not need any of this disambiguating, so it does not go through it.

## Page size on this screen

**Different page size here** in reader settings gives the device and orientation
you are reading on its own page size, leaving every other screen with the
account default. A phone held upright and a tablet turned sideways are separate
contexts, as are portrait and landscape on the same device.

The reader will suggest a fit that suits the screen — fit width on a phone in
portrait, best fit on a tablet — as a small prompt above the controls. It is a
suggestion: accepting it records a page size for that context, and dismissing it
means nothing changes. Rotating a device never rewrites a choice on its own.

Contexts are recognised from what the browser reports about the viewport and the
pointer, never from the user-agent string, so a narrow desktop window gets a
narrow layout without being mistaken for a phone.

## Controls that get out of the way

Where there is no mouse to hover, and in fullscreen, the controls fade out after
a few seconds of reading and come back on a tap in the middle of the page. They
never fade while one of them has focus or a settings popover is open, any
keyboard interaction brings them straight back, and faded controls stop
accepting taps rather than sitting invisibly over the artwork. **Auto-hide reader
controls** turns the whole behaviour off.

Reader controls stay clear of browser chrome, notches and the home indicator,
and the reading area follows the browser's chrome as it grows and shrinks rather
than assuming one fixed height.

## Page navigator

The grid button at the top-right opens a strip of page thumbnails along the
bottom of the reader. Selecting one turns to that page; the current page is
marked, and the strip scrolls to keep it in view as you read. Thumbnails are
tab-reachable and activate with Enter or Space.

Thumbnails load around wherever you are rather than all at once, so opening the
navigator on a long book does not fetch hundreds of images.

## How far ahead it reads

In paged modes, the pages held ready around the current one are chosen from what the device can
afford, not from a fixed number: roughly five pages ahead on a desktop, three on
a tablet, two on a phone, halved again where the browser reports little memory,
and cut to the next page alone when the connection is slow or the user has asked
to save data. There is no setting; the same window decides both what is fetched
early and what is released.

Continuous mode instead follows the scroll viewport: visible and nearby pages
hold images, while pages outside the proximity margin return to stable
placeholders.

## Page quality

The reader asks the server for a page sized to the space it has and to how sharp
the screen is, instead of downloading whatever the uploader exported. Zooming in
asks for the next size up. Nothing here is a setting — there is no quality
choice to get wrong, and no way to end up downloading a 6000-pixel scan onto a
phone. See [page-derivatives.md](page-derivatives.md) for the sizes and the rules
behind them.

## Other settings

- **Show progress bar** displays the slim indicator above the page controls.
- **Auto-hide reader controls** lets the controls take themselves off screen while
  you read — in fullscreen, and on a touchscreen, where there is no hover to
  bring them back. Turning it off keeps them visible.
- **Different page size here** keeps a separate page size for the device and
  orientation you are reading on. See [Page size on this screen](#page-size-on-this-screen).
- **Keep screen awake** requests the browser's screen wake lock while the reader
  is open. Unsupported or denied wake locks do not interrupt reading.

## Keyboard controls

| Key | Action |
| --- | --- |
| Left arrow | Previous logical page |
| Right arrow | Next logical page |
| Home | First logical page |
| End | Last logical page |
| Tab / Shift+Tab | Move through reader controls |
| Enter / Space | Activate the focused control |
| Escape | Close the settings popover; the browser's standard fullscreen key also exits fullscreen |

Arrow shortcuts do not turn pages while focus is in the page-number input or
another editable control.

## Extension contract

Navigation and progress always use the source comic's one-based logical page
numbers. Spread and continuous renderers present those pages differently, but
never persist a synthetic spread, viewport number or scroll percentage.

Page URLs are created in one source-neutral helper and retain the protected
`/api/comics/{id}/pages/{page}` endpoint, with the requested size as a query
parameter. Backend source-provider factories can therefore change how a page is
read, and the derivative pipeline can change what sizes exist, without changing
reader navigation.

Page width, height and aspect ratio are available from
`/api/comics/{id}/pages` before the images themselves are fetched, so a spread or
continuous renderer can lay pages out without downloading them first.

The persisted preference envelope is versioned. Its `overrides` section holds
one entry per device/orientation context, each carrying only settings a context
is allowed to have — today that is the page size, because choosing a renderer
stays global. Unsupported modes, directions and contexts are rejected on write
and dropped on read, so one entry from a newer client can never cost a user the
settings they made everywhere else.

Gesture recognition, spread grouping, the zoom transform, viewport
classification and preload policy are isolated modules with their own tests.
The paged and continuous renderers consume the same logical navigation and
protected derivative sources without inventing a second progress model.
