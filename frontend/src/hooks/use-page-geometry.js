import { useEffect, useRef, useState } from "react";

import { api } from "@/lib/api";
import { createPageManifestUrl } from "@/lib/reader-pages";

const NOTHING_KNOWN = { geometry: {}, isComplete: false };

/**
 * The shape of a comic's pages, as far as the server currently knows it.
 *
 * The server measures a few pages per request and fills the rest in as they are
 * read, so this asks again from wherever the reader has moved to rather than
 * demanding a whole book be inspected up front. A comic whose geometry never
 * arrives is not a broken reader: everything here degrades to "unknown", which
 * every consumer already has to handle for the first page of every comic.
 *
 * What is known is stored against the comic it describes, so moving to another
 * comic reads as "nothing known yet" without a reset — one page of the previous
 * comic's geometry applied to this one would be a wrongly shaped layout.
 *
 * @returns {{geometry: Record<number, {width: number, height: number, aspectRatio: number}>, isComplete: boolean}}
 */
export function usePageGeometry(comicId, pageCount, currentPage) {
  const [known, setKnown] = useState({ comicId: null, ...NOTHING_KNOWN });
  const requestedRef = useRef(new Set());
  const current = known.comicId === comicId ? known : NOTHING_KNOWN;

  useEffect(() => {
    if (!comicId || !pageCount || current.isComplete) return undefined;

    // One request per comic and starting point, ever. A page the server cannot
    // measure would otherwise be asked about on every render for as long as the
    // reader sits on it.
    const from = Math.max(1, (currentPage ?? 0) + 1);
    if (current.geometry[from]) return undefined;

    const request = `${comicId}:${from}`;
    if (requestedRef.current.has(request)) return undefined;
    requestedRef.current.add(request);

    let active = true;
    api
      .get(createPageManifestUrl(comicId, from))
      .then((data) => {
        if (!active || !Array.isArray(data?.pages)) return;

        setKnown((previous) => {
          const geometry = previous.comicId === comicId ? { ...previous.geometry } : {};
          data.pages.forEach((page) => {
            if (!page || !Number.isInteger(page.page)) return;
            geometry[page.page] = { width: page.width, height: page.height, aspectRatio: page.aspectRatio };
          });

          return { comicId, geometry, isComplete: Boolean(data.complete) };
        });
      })
      .catch(() => {
        // Geometry is an optimisation. A reader without it lays pages out from
        // the images themselves, exactly as it did before this existed.
      });

    return () => {
      active = false;
    };
  }, [comicId, pageCount, currentPage, current]);

  return current;
}
