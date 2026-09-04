import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";

import { isGoogleFreeRoute } from "@/lib/google-free-routes";

const replaceBrowserDocument = (url) => window.location.replace(url);

export function GooglePolicyBoundary({ children, replaceDocument = replaceBrowserDocument }) {
  const { pathname, search, hash } = useLocation();
  const [initialGoogleFree] = useState(() => isGoogleFreeRoute(pathname));
  const needsDocument = initialGoogleFree !== isGoogleFreeRoute(pathname);

  // CSP belongs to the document. A client-side route change cannot tighten or
  // relax it, and removing a script element cannot unload code already running.
  useEffect(() => {
    if (needsDocument) replaceDocument(pathname + search + hash);
  }, [hash, needsDocument, pathname, replaceDocument, search]);

  return needsDocument ? null : children;
}
