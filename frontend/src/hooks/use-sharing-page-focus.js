import { useState } from "react";

/**
 * Which half of the sharing page to show, and what to refresh once something
 * completes.
 *
 * Answering an invitation is what most visits are for, so "Shared with me" is
 * the tab the page opens on. A completed share moves it to the half that now
 * has something new on it.
 */
export function useSharingPageFocus({ reload, loadLibrary }) {
  const [activeTab, setActiveTab] = useState("with-me");
  // Bumped whenever a sharing action completes, so the codes card refetches
  // what has been handed out. A code created from the share dialog belongs in
  // that list straight away.
  const [codesReloadKey, setCodesReloadKey] = useState(0);

  /**
   * What both sharing flows do once invitations exist: the new relationships
   * belong on **Shared by me**, and a comic that was not shared before now is.
   * Deliberately not wrapped in a try — the dialog reports a refresh failure
   * itself, and must not mistake one for a share that did not happen.
   */
  const afterShare = async () => {
    // Before the reload rather than after it, so a sender who started from the
    // header is not left looking at "Shared with me" wondering whether anything
    // happened — even if the refresh itself fails.
    setActiveTab("by-me");
    setCodesReloadKey((key) => key + 1);
    await reload();
    await loadLibrary();
  };

  /**
   * The mirror of the above, for comics arriving rather than leaving. Redeeming
   * a code puts them under **Shared with me**, which is already the tab the page
   * opens on, so this only reloads.
   */
  const afterReceiving = async () => {
    setActiveTab("with-me");
    await reload();
    await loadLibrary();
  };

  return { activeTab, setActiveTab, codesReloadKey, afterShare, afterReceiving };
}
