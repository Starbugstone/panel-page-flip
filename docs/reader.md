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

## Other settings

- **Show progress bar** displays the slim indicator above the page controls.
- **Fade fullscreen controls** hides the bottom controls until pointer hover or
  keyboard focus. Turning it off keeps the controls visible.
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
numbers. A future spread or continuous renderer may present those pages
differently, but it must not persist a synthetic spread or viewport number.

Page URLs are created in one source-neutral helper and retain the protected
`/api/comics/{id}/pages/{page}` endpoint. Backend source-provider factories can
therefore change how a page is read without changing reader navigation.

The persisted preference envelope is versioned and already reserves an
`overrides` section for validated device/orientation contexts. Unsupported modes,
directions, and override data are rejected until their renderer or context logic
is present; later reader work can extend the allowed values centrally without
replacing existing user settings.
