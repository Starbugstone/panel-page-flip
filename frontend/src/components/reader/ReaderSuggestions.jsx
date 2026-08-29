import { ReaderFitSuggestion } from "@/components/reader/ReaderFitSuggestion";

/** Whichever of the reader's two prompts is currently worth raising, if either. */
export function ReaderSuggestions({ suggestions, contextLabel }) {
  if (suggestions.isSuggestingMode) {
    return (
      <ReaderFitSuggestion
        message={<><span className="font-medium">Two pages</span> can make better use of this wide screen.</>}
        acceptLabel="Use two pages"
        onAccept={suggestions.acceptMode}
        onDismiss={suggestions.dismissMode}
      />
    );
  }

  if (suggestions.isSuggestingFit) {
    return (
      <ReaderFitSuggestion
        fitLabel={suggestions.suggestedFitLabel}
        contextLabel={contextLabel}
        onAccept={suggestions.acceptFit}
        onDismiss={suggestions.dismissFit}
      />
    );
  }

  return null;
}
