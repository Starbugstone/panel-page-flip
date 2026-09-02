import { useCallback, useEffect, useState } from "react";

import { logger } from "@/lib/logger";
import { useTags } from "./use-tags";

export const MAX_TAG_FETCH_RETRIES = 3;

export function isRetryableTagError(error) {
  const status = Number(error?.status || 0);
  if (status === 0 || status >= 500) return true;

  return /network|server error|unable to reach/i.test(String(error?.message || ""));
}

const errorMessage = (error) => error instanceof Error ? error.message : "Unable to load tags";

export function useTagOptions() {
  const { tags: availableTags, requestTags } = useTags();
  const [isLoadingTags, setIsLoadingTags] = useState(true);
  const [tagFetchError, setTagFetchError] = useState(null);
  const [retryCount, setRetryCount] = useState(0);
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    let cancelled = false;
    let retryTimer = null;

    const fetchTags = async (attempt) => {
      if (cancelled) return;

      if (attempt === 0) setRetryCount(0);
      setIsLoadingTags(true);
      setTagFetchError(null);

      try {
        await requestTags(attempt > 0);
        if (cancelled) return;

        setRetryCount(0);
      } catch (error) {
        if (cancelled) return;

        logger.error("Error fetching tags:", error);
        setTagFetchError(errorMessage(error));

        if (attempt < MAX_TAG_FETCH_RETRIES && isRetryableTagError(error)) {
          const retryDelay = 2 ** attempt * 1000;
          retryTimer = window.setTimeout(() => {
            if (cancelled) return;

            const nextAttempt = attempt + 1;
            logger.log(`Retrying tag fetch (${nextAttempt}/${MAX_TAG_FETCH_RETRIES})...`);
            setRetryCount(nextAttempt);
            void fetchTags(nextAttempt);
          }, retryDelay);
        }
      } finally {
        if (!cancelled) setIsLoadingTags(false);
      }
    };

    void fetchTags(0);

    return () => {
      cancelled = true;
      if (retryTimer !== null) window.clearTimeout(retryTimer);
    };
  }, [requestTags, requestVersion]);

  const retryTagFetch = useCallback(() => {
    setRequestVersion((version) => version + 1);
  }, []);

  return {
    availableTags,
    isLoadingTags,
    tagFetchError,
    retryCount,
    retryTagFetch,
  };
}
