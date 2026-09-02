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

  const [isConfigured, setIsConfigured] = useState(false);
  const [isConnected, setIsConnected] = useState(false);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [disconnecting, setDisconnecting] = useState(false);
  const [connecting, setConnecting] = useState(false);

  // The connection is checked once, and a reply that arrives after leaving the
  // page is dropped rather than applied to a component that has gone.
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
      .catch((error) => { logger.error("Error checking Dropbox status:", error); })
      .finally(() => { if (!ignore) setLoading(false); });

    return () => { ignore = true; };
  }, [refreshFiles]);

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
      setSyncStatus(`Import completed: ${data.newFiles || 0} new comics added`);
      setLastSync(new Date().toISOString());
      toast({
        title: "Import Complete",
        description: `${data.newFiles || 0} new comics have been imported from Dropbox.`,
      });
      refreshFiles();
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
    syncing,
    syncStatus,
    lastSync,
    disconnecting,
    connecting,
    connect,
    disconnect,
    importAll,
  };
}
