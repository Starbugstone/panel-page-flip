/**
 * A deployment replaces the hashed chunks a tab opened earlier still refers to.
 * Reloading picks up the current build, which is all the user needs. If the
 * chunk still fails, reloading again cannot help, so the failure is left to the
 * error boundary instead of looping.
 *
 * The guard lives in history.state rather than browser storage: it survives a
 * reload, belongs to this one tab and history entry, and adds nothing to the
 * storage the cookie policy has to list.
 */
const RELOAD_MARKER = "staleChunkReloadAt";
const RELOAD_GUARD_MS = 60000;

export function reloadOnceForStaleChunk(win = window) {
  // Offline, the chunk may be fine and a reload would swap the running app for
  // the browser's error page.
  if (win.navigator.onLine === false) {
    return false;
  }

  const now = Date.now();
  const lastReload = win.history.state?.[RELOAD_MARKER];
  if (typeof lastReload === "number" && now - lastReload < RELOAD_GUARD_MS) {
    return false;
  }

  try {
    win.history.replaceState({ ...win.history.state, [RELOAD_MARKER]: now }, "");
  } catch {
    // Without the marker nothing would stop a loop.
    return false;
  }
  win.location.reload();
  return true;
}

// Not calling preventDefault: Vite would then resolve the import with undefined,
// and React would report that as a broken component instead of the real failure.
export function installStaleChunkRecovery(win = window) {
  win.addEventListener("vite:preloadError", () => reloadOnceForStaleChunk(win));
}
