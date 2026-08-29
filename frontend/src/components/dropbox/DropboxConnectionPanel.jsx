import { AlertCircle, CheckCircle, Cloud, Loader2, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button.jsx";
import { DropboxFileList } from "@/components/dropbox/DropboxFileList";
import { APP_FOLDER } from "@/components/dropbox/dropbox-guide-content";

function ConnectedPanel({ dropbox }) {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="mb-2 flex items-center gap-2">
            <CheckCircle className="h-5 w-5 text-green-500" />
            <span className="font-semibold">Connected to Dropbox</span>
          </div>
          {dropbox.dropboxUser && <p className="text-sm text-muted-foreground">Account: {dropbox.dropboxUser}</p>}
          {dropbox.lastSync && (
            <p className="text-sm text-muted-foreground">
              Last import: {new Date(dropbox.lastSync).toLocaleString()}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <Button onClick={dropbox.importAll} disabled={dropbox.syncing} className="flex items-center gap-2">
            <RefreshCw className={`h-4 w-4 ${dropbox.syncing ? "animate-spin" : ""}`} />
            {dropbox.syncing ? "Importing..." : "Import new comics"}
          </Button>
          <Button
            variant="outline"
            onClick={dropbox.refreshFiles}
            disabled={dropbox.refreshingFiles}
            className="flex items-center gap-2"
          >
            <RefreshCw className={`h-4 w-4 ${dropbox.refreshingFiles ? "animate-spin" : ""}`} />
            {dropbox.refreshingFiles ? "Refreshing..." : "Refresh Files"}
          </Button>
          <Button
            variant="outline"
            onClick={dropbox.disconnect}
            disabled={dropbox.disconnecting}
            className="text-red-600 hover:text-red-700 disabled:text-red-400"
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
        Your comics should be placed in the <code className="rounded bg-muted px-1 py-0.5">{APP_FOLDER}</code> folder in your Dropbox.
      </p>
      <Button onClick={onConnect} disabled={connecting} className="flex items-center gap-2">
        {connecting
          ? <><Loader2 className="h-4 w-4 animate-spin" />Connecting...</>
          : <><Cloud className="h-4 w-4" />Connect to Dropbox</>}
      </Button>
    </div>
  );
}

/** Either what the connection can do, or the invitation to make one. */
export function DropboxConnectionPanel({ dropbox }) {
  return dropbox.isConnected
    ? <ConnectedPanel dropbox={dropbox} />
    : <DisconnectedPanel connecting={dropbox.connecting} onConnect={dropbox.connect} />;
}
