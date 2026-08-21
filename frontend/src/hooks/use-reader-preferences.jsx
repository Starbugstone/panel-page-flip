import { useCallback, useEffect, useRef, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import {
  DEFAULT_READER_PREFERENCES,
  clearReaderOverride,
  normalizeReaderPreferences,
  setReaderOverride,
  updateReaderSettings,
} from "@/lib/reader-preferences";

/**
 * Loads once and applies changes optimistically. Writes are serialized and
 * collapsed to the newest pending value, preventing a slow older request from
 * overwriting a rapid later choice on the server.
 */
export function useReaderPreferences(toast) {
  const [preferences, setPreferences] = useState(DEFAULT_READER_PREFERENCES);
  const [isLoaded, setIsLoaded] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const preferencesRef = useRef(DEFAULT_READER_PREFERENCES);
  const pendingOperationRef = useRef(null);
  const pumpingRef = useRef(false);
  const mountedRef = useRef(true);
  const changedLocallyRef = useRef(false);

  const applyPreferences = useCallback((next) => {
    preferencesRef.current = next;
    if (mountedRef.current) setPreferences(next);
  }, []);

  const pump = useCallback(async () => {
    if (pumpingRef.current) return;
    pumpingRef.current = true;
    if (mountedRef.current) setIsSaving(true);

    while (pendingOperationRef.current) {
      const operation = pendingOperationRef.current;
      pendingOperationRef.current = null;
      try {
        const data = operation.kind === "reset"
          ? await api.delete("/api/reader/preferences", { keepalive: true })
          : await api.put(
              "/api/reader/preferences",
              { preferences: operation.preferences },
              { keepalive: true }
            );

        if (!pendingOperationRef.current) {
          applyPreferences(normalizeReaderPreferences(data?.preferences));
        }
      } catch (error) {
        logger.error("Failed to save reader preferences:", error);
        if (mountedRef.current) {
          toast({
            title: "Reader setting not saved",
            description: "Your choice works for this session, but may not follow you to another device.",
            variant: "destructive",
          });
        }
      }
    }

    pumpingRef.current = false;
    if (mountedRef.current) setIsSaving(false);
  }, [applyPreferences, toast]);

  useEffect(() => {
    mountedRef.current = true;
    let active = true;

    api.get("/api/reader/preferences")
      .then((data) => {
        if (!active || changedLocallyRef.current) return;
        applyPreferences(normalizeReaderPreferences(data?.preferences));
      })
      .catch((error) => {
        if (!active) return;
        logger.warn("Failed to load reader preferences; using defaults:", error);
        toast({
          title: "Using default reader settings",
          description: "Your saved settings could not be loaded.",
          variant: "destructive",
        });
      })
      .finally(() => {
        if (active) setIsLoaded(true);
      });

    return () => {
      active = false;
      mountedRef.current = false;
    };
  }, [applyPreferences, toast]);

  const save = useCallback((next) => {
    changedLocallyRef.current = true;
    applyPreferences(next);
    pendingOperationRef.current = { kind: "save", preferences: next };
    void pump();
  }, [applyPreferences, pump]);

  const changeSettings = useCallback((patch) => {
    save(updateReaderSettings(preferencesRef.current, patch));
  }, [save]);

  /** Record a fit for one device and orientation, leaving every other screen alone. */
  const changeOverride = useCallback((context, patch) => {
    save(setReaderOverride(preferencesRef.current, context, patch));
  }, [save]);

  const clearOverride = useCallback((context) => {
    save(clearReaderOverride(preferencesRef.current, context));
  }, [save]);

  const resetPreferences = useCallback(() => {
    changedLocallyRef.current = true;
    applyPreferences(DEFAULT_READER_PREFERENCES);
    pendingOperationRef.current = { kind: "reset" };
    void pump();
  }, [applyPreferences, pump]);

  return {
    preferences,
    settings: preferences.settings,
    isLoaded,
    isSaving,
    changeSettings,
    changeOverride,
    clearOverride,
    resetPreferences,
  };
}
