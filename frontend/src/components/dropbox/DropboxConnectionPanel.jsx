import { AlertCircle, CheckCircle, Cloud, Loader2, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { DropboxFileList } from "@/components/dropbox/DropboxFileList";
import { APP_FOLDER } from "@/components/dropbox/dropbox-guide-content";

function ConnectedPanel({ dropbox }) {
  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <div className="mb-2 flex items-center gap-2">
            <CheckCircle className="h-5 w-5 text-green-500" />
            <span className="font-semibold">Connected to Dropbox</span>
          </div>
          {dropbox.lastSync && (
            <p className="text-sm text-muted-foreground">
              Last import: {new Date(dropbox.lastSync).toLocaleString()}
            </p>
          )}
        </div>
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
          <Button onClick={dropbox.importAll} disabled={dropbox.syncing} className="flex w-full items-center gap-2 sm:w-auto">
            <RefreshCw className={`h-4 w-4 ${dropbox.syncing ? "animate-spin" : ""}`} />
            {dropbox.syncing ? "Importing..." : "Import new comics"}
          </Button>
          <Button
            variant="outline"
            onClick={dropbox.refreshFiles}
            disabled={dropbox.refreshingFiles}
            className="flex w-full items-center gap-2 sm:w-auto"
          >
            <RefreshCw className={`h-4 w-4 ${dropbox.refreshingFiles ? "animate-spin" : ""}`} />
            {dropbox.refreshingFiles ? "Refreshing..." : "Refresh Files"}
          </Button>
          <Button
            variant="outline"
            onClick={dropbox.disconnect}
            disabled={dropbox.disconnecting}
            className="w-full text-red-600 hover:text-red-700 disabled:text-red-400 sm:w-auto"
          >
            {dropbox.disconnecting
              ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Disconnecting...</>
              : "Disconnect"}
          </Button>
        </div>
      </div>

      {dropbox.syncStatus && (
        <div className="rounded-lg bg-muted p-3"><p className="text-sm">{dropbox.syncStatus}</p></div>
      )}

      <DropboxFileList
        files={dropbox.files}
        refreshingFiles={dropbox.refreshingFiles}
        importingPaths={dropbox.importingPaths}
        onImport={dropbox.importFile}
      />
    </div>
  );
}

function DisconnectedPanel({ connecting, onConnect }) {
  return (
    <div className="space-y-4 text-center">
      <div className="flex items-center justify-center gap-2 text-muted-foreground">
        <AlertCircle className="h-5 w-5" />
        <span>Not connected to Dropbox</span>
      </div>
      <p className="text-muted-foreground">Connect your Dropbox account to import comics from Dropbox.</p>
      <p className="text-sm text-muted-foreground">
        Your comics should be placed in your <code className="rounded bg-muted px-1 py-0.5">{APP_FOLDER}</code>.
      </p>
      <Button onClick={onConnect} disabled={connecting} className="flex items-center gap-2">
        {connecting
          ? <><Loader2 className="h-4 w-4 animate-spin" />Connecting...</>
          : <><Cloud className="h-4 w-4" />Connect to Dropbox</>}
      </Button>
    </div>
  );
}

function UnconfiguredPanel() {
  return (
    <div className="space-y-3 text-center">
      <div className="flex items-center justify-center gap-2 text-muted-foreground">
        <AlertCircle className="h-5 w-5" />
        <span className="font-medium">Dropbox imports are not enabled on this server.</span>
      </div>
      <p className="text-sm text-muted-foreground">
        An administrator must configure Dropbox before accounts can connect.
      </p>
    </div>
  );
}

function StatusErrorPanel({ onRetry }) {
  return (
    <div role="alert" className="space-y-3 text-center">
      <div className="flex items-center justify-center gap-2 text-red-600 dark:text-red-400">
        <AlertCircle className="h-5 w-5" />
        <span className="font-medium">Could not check Dropbox connection.</span>
      </div>
      <p className="text-sm text-muted-foreground">
        The server did not return a connection status. Try the check again before connecting or importing.
      </p>
      <Button variant="outline" onClick={onRetry} className="w-full sm:w-auto">
        <RefreshCw className="mr-2 h-4 w-4" />
        Retry status check
      </Button>
    </div>
  );
}

/** Either what the connection can do, or the invitation to make one. */
export function DropboxConnectionPanel({ dropbox }) {
  if (dropbox.statusError) return <StatusErrorPanel onRetry={dropbox.retryStatus} />;
  if (dropbox.isConfigured === false) return <UnconfiguredPanel />;

  return dropbox.isConnected
    ? <ConnectedPanel dropbox={dropbox} />
    : <DisconnectedPanel connecting={dropbox.connecting} onConnect={dropbox.connect} />;
}
