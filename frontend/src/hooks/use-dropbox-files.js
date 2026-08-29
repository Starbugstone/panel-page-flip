import { useCallback, useState } from "react";
import { useToast } from "@/hooks/use-toast.js";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

/** The comics in the Dropbox app folder, and importing them one at a time. */
export function useDropboxFiles() {
  const { toast } = useToast();
  const [files, setFiles] = useState([]);
  const [refreshingFiles, setRefreshingFiles] = useState(false);
  const [importingPaths, setImportingPaths] = useState(new Set());

  const refreshFiles = useCallback(async (showToast = true) => {
    setRefreshingFiles(true);
    try {
      const data = await api.get("/api/dropbox/files");
      setFiles(data.files || []);
      if (showToast) {
        toast({
          title: "Files Refreshed",
          description: `Found ${data.files?.length || 0} comics in your Dropbox folder.`,
        });
      }
    } catch (error) {
      logger.error("Error fetching Dropbox files:", error);
      toast({
        title: "Refresh Failed",
        description: error.message || "Could not refresh Dropbox files. Please try again.",
        variant: "destructive",
      });
    } finally {
      setRefreshingFiles(false);
    }
  }, [toast]);

  // Keyed on the path rather than the name: the same file name can appear in
  // several Dropbox folders, and each is a separate row here.
  const importFile = async (file) => {
    const { name: fileName, path } = file;
    setImportingPaths((paths) => new Set([...paths, path]));

    try {
      const data = await api.post("/api/dropbox/import", { path, fileName });
      toast({
        title: "Import Successful",
        description: `${data.comic?.title || fileName} has been imported successfully.`,
      });
      refreshFiles(false);
    } catch (error) {
      toast({
        title: "Import Failed",
        description: error.message || "Could not import this comic.",
        variant: "destructive",
      });
    } finally {
      setImportingPaths((paths) => {
        const next = new Set(paths);
        next.delete(path);
        return next;
      });
    }
  };

  return { files, setFiles, refreshingFiles, refreshFiles, importingPaths, importFile };
}
