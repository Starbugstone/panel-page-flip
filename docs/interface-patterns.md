# Shared interface patterns

The site keeps its book mark and purple identity across public, account,
library and administration pages. Semantic color tokens in `frontend/src/index.css`
provide separate light and dark palettes. Use `primary`, `foreground`, `muted`,
`destructive`, `card` and their paired foreground tokens for controls and text;
the decorative comic palette is reserved for artwork. Token contrast tests
guard normal text at 4.5:1. This is not a claim that every possible rendering
meets every accessibility criterion.

## Page and component responsibilities

- `PageLayout` supplies the common gutters and a named content width: `wide`
  for lists, `settings` for account work, `reading` for policy prose and `form`
  for focused forms. Feature components continue to own their actions and state.
- `PageHeader` groups one primary heading, explanatory text and wrapping actions.
  `PageLoading` exposes a visible label with `role="status"`.
- `AuthLayout` supplies the shared account card, book mark, primary heading and
  optional footer. It contains no authentication or redirect logic.
- `CardTitle` defaults to a section heading (`h2`). A page composed of one card
  can use `as="h1"`; nested sections can choose `as="h3"`.
- `ComicFilePicker` owns the native file input, keyboard-accessible chooser,
  drag-and-drop and input reset so the same file can be selected again. Single
  and bulk upload retain their own validation, destination and upload logic.
- Dialogs use a `minmax(0, 1fr)` column so intrinsic form widths cannot widen the
  content past the mobile dialog. Long content scrolls within viewport gutters.
- Legal pages use explicit `legal-content` typography for headings, lists and
  links; this does not depend on an optional Tailwind typography plugin.

Global focus indicators and reduced-motion rules complement the controls'
existing Radix behavior. Theme preferences accept only `light` or `dark`;
native form controls follow that color scheme. Storage failures leave the
selected theme usable for the current session.

## Navigation and recovery

`lib/navigation.js` is the shared destination list for desktop and mobile.
Current sections expose `aria-current="page"`, including admin detail pages.
Bulk upload still enters through `BulkUploadEntryLink`, preserving the existing
advertising boundary and keeping an active queue on its current URL.

A skip link reaches the main content. Navigation between pages moves focus to
that content and starts at the top; query-only changes keep focus and scroll,
and browser Back, reader navigation, hash anchors and library return-to-comic
jumps retain their existing scroll behavior. Closing a mobile navigation menu
after navigation must not return focus to its old trigger.

Route rendering errors leave the header and footer available with a generic
explanation and a **Reload page** link. Recovery loads a fresh document because
React retains failed lazy imports after a deployment removes an old asset;
resetting the boundary alone cannot fetch the new bundle. Navigating to a
different pathname resets the boundary.
Raw exception details and private invitation/reset tokens do not appear in the
fallback or document titles. This boundary does not replace feature-specific
API failure handling or handle failures above the routed page.

## Account flows

Forgot-password responses remain identical for unknown accounts and request
failures. Reset-link validation distinguishes invalid links from temporary
service failures and offers a retry for the latter. Successful resets remain
visible until the reader chooses the login link; there is no delayed redirect.
Verification resend uses a native form, including Enter to submit and email
autofill. Terms/privacy acknowledgements and social-signup requirements retain
their existing behavior.

The login/signup tab is controlled by the existing `signup` query parameter.
Successful registration actually returns to the login tab while preserving a
local redirect destination; changing tabs retains the rest of the URL state.

Authentication and background session checks are scoped to the session revision
that started them. Logging out, expiring a session or beginning a new login
invalidates older results. Stopping a session monitor clears its failure count
and invalidates its pending work. A malformed HTTP 401 still triggers expiry;
failed downloads parse structured API errors rather than treating an error as a
successful blob. Raw HTML failures receive a generic message.

## Verification

Run the component tests for the affected journey, shared layout, navigation,
dialogs and palette, as well as session/API regressions when those boundaries
change. Browser-check desktop and 320-pixel layouts in both themes, including a
populated sharing dialog. Keep the reader's geometry, touch behavior, access
checks and return-to-library position intact.
