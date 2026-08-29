import { useCallback } from "react";

import {
  DEFAULT_READER_PREFERENCES,
  READER_FITS,
} from "@/lib/reader-preferences";
import { suggestedFitFor, viewportContextKey } from "@/lib/reader-viewport";

const MODE_SUGGESTION_ID = "mode:tablet:landscape";

/**
 * The two prompts the reader is allowed to raise about how it is set up.
 *
 * Both are suppressed while the reader is busy being used — zoomed, or with the
 * thumbnail strip open — and neither ever changes a setting on its own.
 * Dismissing one is remembered per viewport context, so rotating a device does
 * not re-ask a question already answered for that shape of screen.
 */
export function useReaderSuggestions({
  profile,
  viewportContext,
  preferences,
  arePreferencesLoaded,
  settings,
  hasContextOverride,
  isZoomed,
  showThumbnails,
  changeSettings,
  changeOverride,
  dismissSuggestion,
  resetTransform,
}) {
  const suggestedFit = suggestedFitFor(profile);
  const fitSuggestionId = `fit:${viewportContextKey(viewportContext)}`;
  const wasDismissed = (id) => preferences.dismissedSuggestions.includes(id);

  const isSuggestingMode = arePreferencesLoaded
    && !isZoomed
    && !showThumbnails
    && profile.device === "tablet"
    && profile.orientation === "landscape"
    && settings.mode === "single"
    && !wasDismissed(MODE_SUGGESTION_ID);

  const isSuggestingFit = !isSuggestingMode
    && !isZoomed
    && !showThumbnails
    && arePreferencesLoaded
    && !hasContextOverride
    && preferences.settings.fit === DEFAULT_READER_PREFERENCES.settings.fit
    && suggestedFit !== settings.fit
    && !wasDismissed(fitSuggestionId);

  const acceptFit = useCallback(() => {
    resetTransform();
    changeOverride(viewportContext, { fit: suggestedFit });
    dismissSuggestion(fitSuggestionId);
  }, [changeOverride, dismissSuggestion, fitSuggestionId, resetTransform, suggestedFit, viewportContext]);

  const acceptMode = useCallback(() => {
    changeSettings({ mode: "double" });
    dismissSuggestion(MODE_SUGGESTION_ID);
  }, [changeSettings, dismissSuggestion]);

  return {
    isSuggestingFit,
    isSuggestingMode,
    suggestedFitLabel: READER_FITS.find(({ value }) => value === suggestedFit)?.label,
    acceptFit,
    acceptMode,
    dismissFit: () => dismissSuggestion(fitSuggestionId),
    dismissMode: () => dismissSuggestion(MODE_SUGGESTION_ID),
  };
}
