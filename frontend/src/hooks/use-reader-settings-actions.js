import { useCallback } from "react";

import { OVERRIDABLE_SETTINGS } from "@/lib/reader-preferences";

/**
 * Changing a reader setting, and what that means for the zoom already applied.
 *
 * A zoom is measured against the fit it was set up under, so any change that
 * moves the page — fit, mode, direction — takes it off rather than leaving a
 * magnification that no longer points at what it was pointing at. A patch that
 * is entirely overridable lands on this screen's override when there is one,
 * so adjusting a phone does not rewrite the account default.
 */
export function useReaderSettingsActions({
  settings, viewportContext, hasContextOverride,
  changeSettings, changeOverride, clearOverride, resetPreferences, resetTransform,
}) {
  const change = useCallback((patch) => {
    if ((patch.fit && patch.fit !== settings.fit) || patch.mode || patch.direction) resetTransform();
    if (hasContextOverride && Object.keys(patch).every((key) => OVERRIDABLE_SETTINGS.includes(key))) {
      changeOverride(viewportContext, patch);
      return;
    }
    changeSettings(patch);
  }, [changeOverride, changeSettings, hasContextOverride, resetTransform, settings.fit, viewportContext]);

  const changeContextOverride = useCallback((enabled) => {
    if (enabled) changeOverride(viewportContext, { fit: settings.fit });
    else {
      resetTransform();
      clearOverride(viewportContext);
    }
  }, [changeOverride, clearOverride, resetTransform, settings.fit, viewportContext]);

  const reset = useCallback(() => {
    resetTransform();
    resetPreferences();
  }, [resetPreferences, resetTransform]);

  return { change, changeContextOverride, reset };
}
