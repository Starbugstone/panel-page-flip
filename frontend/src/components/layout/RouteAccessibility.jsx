import { useEffect, useRef } from "react";
import { useLocation, useNavigationType } from "react-router-dom";
import { pageTitle } from "@/lib/navigation";

export function RouteAccessibility() {
  const { pathname, search, hash } = useLocation();
  const navigationType = useNavigationType();
  const previousPath = useRef(pathname);

  useEffect(() => {
    document.title = `${pageTitle(pathname)} | Panel Page Flip`;
    if (previousPath.current === pathname) return;
    previousPath.current = pathname;

    document.getElementById("main-content")?.focus({ preventScroll: true });
    // The library owns its return-to-comic scroll, and browser Back keeps its position.
    if (navigationType !== "POP" && !hash && !new URLSearchParams(search).has("jump") && !pathname.startsWith("/read/")) {
      window.scrollTo({ top: 0, left: 0, behavior: "instant" });
    }
  }, [pathname, search, hash, navigationType]);

  return null;
}
