import { useEffect, useRef, useState } from "react";

import { loadTurnstile } from "@/lib/turnstile-loader";

const NOOP = () => {};

export function TurnstileWidget({ siteKey, onToken, onError = NOOP, resetKey }) {
  const containerRef = useRef(null);
  const apiRef = useRef(null);
  const widgetIdRef = useRef(null);
  const onTokenRef = useRef(onToken);
  const onErrorRef = useRef(onError);
  const [unavailable, setUnavailable] = useState(false);

  useEffect(() => {
    onTokenRef.current = onToken;
    onErrorRef.current = onError;
  }, [onToken, onError]);

  useEffect(() => {
    let cancelled = false;

    loadTurnstile()
      .then((turnstile) => {
        if (cancelled || !containerRef.current) return;
        apiRef.current = turnstile;
        widgetIdRef.current = turnstile.render(containerRef.current, {
          sitekey: siteKey,
          action: "content_report",
          callback: (token) => onTokenRef.current(token),
          "expired-callback": () => onTokenRef.current(null),
          "error-callback": () => {
            onTokenRef.current(null);
            onErrorRef.current();
          },
        });
      })
      .catch(() => {
        if (cancelled) return;
        setUnavailable(true);
        onTokenRef.current(null);
        onErrorRef.current();
      });

    return () => {
      cancelled = true;
      if (apiRef.current && widgetIdRef.current !== null) {
        apiRef.current.remove(widgetIdRef.current);
      }
      apiRef.current = null;
      widgetIdRef.current = null;
    };
  }, [siteKey]);

  useEffect(() => {
    if (resetKey === 0 || !apiRef.current || widgetIdRef.current === null) return;
    apiRef.current.reset(widgetIdRef.current);
  }, [resetKey]);

  return (
    <div className="space-y-2" aria-label="Anti-bot verification">
      <div ref={containerRef} />
      {unavailable && (
        <p role="alert" className="text-sm text-destructive">
          The anti-bot check could not be loaded. Try again or use the legal email below.
        </p>
      )}
    </div>
  );
}
