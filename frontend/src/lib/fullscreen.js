// The reader's overlays - the left/right click-to-turn zones, the header and
// the bottom controls - are siblings of the page image, not descendants of it.
// A fullscreen element only renders its own subtree, so putting just the image
// container fullscreen leaves every one of those overlays behind: invisible and
// unclickable. The document element is the common ancestor of all of them, so
// that is what the reader takes fullscreen.
export function getFullscreenTarget(doc) {
  return doc?.documentElement ?? null;
}

export function isFullscreenActive(doc) {
  return Boolean(doc?.fullscreenElement);
}

// Returns the fullscreen state the caller asked for, or the unchanged current
// state when the browser has no fullscreen API. The real state still arrives
// via the `fullscreenchange` event; this return value is only a hint.
export function toggleFullscreen(doc) {
  const active = isFullscreenActive(doc);

  if (active) {
    if (typeof doc?.exitFullscreen !== "function") return true;
    // A rejected request leaves us in the state we were already in, and the
    // fullscreenchange event is the source of truth regardless.
    Promise.resolve(doc.exitFullscreen()).catch(() => {});
    return false;
  }

  const target = getFullscreenTarget(doc);
  if (typeof target?.requestFullscreen !== "function") return false;

  Promise.resolve(target.requestFullscreen()).catch(() => {});
  return true;
}
