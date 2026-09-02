import { useEffect, useState } from "react";
import { useDropboxFiles } from "@/hooks/use-dropbox-files";
import { useToast } from "@/hooks/use-toast.js";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

// Long enough for the button to show it was pressed before the page goes.
const CONNECT_REDIRECT_MS = 100;

/** The Dropbox connection, and the bulk import that runs across all of it. */
export function useDropboxSync() {
  const { toast } = useToast();
  const dropboxFiles = useDropboxFiles();
  const { refreshFiles, setFiles } = dropboxFiles;

  const [isConfigured, setIsConfigured] = useState(null);
  const [isConnected, setIsConnected] = useState(false);
  const [loading, setLoading] = useState(true);
  const [statusError, setStatusError] = useState(false);
  const [statusCheckAttempt, setStatusCheckAttempt] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [disconnecting, setDisconnecting] = useState(false);
  const [connecting, setConnecting] = useState(false);

  // A reply that arrives after leaving the page or starting another check is
  // dropped rather than applied to a component that has gone.
  useEffect(() => {
    let ignore = false;
    api.get("/api/dropbox/status")
      .then((data) => {
        if (ignore) return;
        const configured = data.configured === true;
        const connected = configured && data.connected === true;
        setIsConfigured(configured);
        setIsConnected(connected);
        setLastSync(data.lastSync);
        if (connected) void refreshFiles(false);
      })
      .catch((error) => {
        if (ignore) return;
        logger.error("Error checking Dropbox status:", error);
        setIsConfigured(null);
        setIsConnected(false);
        setLastSync(null);
        setStatusError(true);
      })
      .finally(() => { if (!ignore) setLoading(false); });

    return () => { ignore = true; };
  }, [refreshFiles, statusCheckAttempt]);

  // The OAuth round trip comes back with ?status=connected. Announced once,
  // then taken out of the URL so a reload does not repeat it.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("status") === "connected") {
      toast({ title: "Dropbox Connected!", description: "Your Dropbox account has been successfully connected." });
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }, [toast]);

  const connect = () => {
    setConnecting(true);
    setTimeout(() => { window.location.href = "/api/dropbox/connect"; }, CONNECT_REDIRECT_MS);
  };

  const retryStatus = () => {
    setLoading(true);
    setStatusError(false);
    setStatusCheckAttempt((attempt) => attempt + 1);
  };

  const disconnect = async () => {
    setDisconnecting(true);
    try {
      await api.post("/api/dropbox/disconnect", {});
      setIsConnected(false);
      setFiles([]);
      toast({ title: "Dropbox Disconnected", description: "Your Dropbox account has been disconnected." });
    } catch (error) {
      toast({
        title: "Disconnect Failed",
        description: error.message || "Could not disconnect Dropbox.",
        variant: "destructive",
      });
    } finally {
      setDisconnecting(false);
    }
  };

  const importAll = async () => {
    setSyncing(true);
    setSyncStatus("Importing...");

    try {
      const data = await api.post("/api/dropbox/sync", {});
      const newFiles = Number(data.newFiles) || 0;
      const failedFiles = Number(data.failedFiles) || 0;

      if (failedFiles > 0) {
        setSyncStatus(`Import partially completed: ${newFiles} imported, ${failedFiles} failed`);
        toast({
          title: "Import Incomplete",
          description: data.message || `${newFiles} imported, ${failedFiles} failed.`,
          variant: "destructive",
        });
      } else {
        const comicNoun = newFiles === 1 ? "comic" : "comics";
        const haveOrHas = newFiles === 1 ? "has" : "have";
        setSyncStatus(`Import completed: ${newFiles} new ${comicNoun} added`);
        setLastSync(new Date().toISOString());
        toast({
          title: "Import Complete",
          description: `${newFiles} new ${comicNoun} ${haveOrHas} been imported from Dropbox.`,
        });
      }
      void refreshFiles(false);
    } catch (error) {
      setSyncStatus(`Import failed: ${error.message || "Could not import comics from Dropbox."}`);
      toast({
        title: "Import Failed",
        description: error.message || "Could not import comics from Dropbox.",
        variant: "destructive",
      });
    } finally {
      setSyncing(false);
    }
  };

  return {
    ...dropboxFiles,
    isConfigured,
    isConnected,
    loading,
    statusError,
    syncing,
    syncStatus,
    lastSync,
    disconnecting,
    connecting,
    retryStatus,
    connect,
    disconnect,
    importAll,
  };
}
