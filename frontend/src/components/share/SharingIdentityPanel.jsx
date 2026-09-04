import { Check, Copy, KeyRound, Loader2, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { SHARING_CODE_COPY } from "@/lib/sharing";

/** How other people reach this account: a username and a `U-` code. */
export function SharingIdentityPanel({ identity, loadFailed, copied, isRotating, onCopy, onRotate }) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <KeyRound className="h-4 w-4 text-primary" />
        <h2 className="font-semibold">Your identity</h2>
      </div>
      <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.mine}</p>
      <p className="text-sm">
        Username{" "}
        <span className="font-mono font-medium" aria-label="Your username">
          {identity ? `@${identity.username}` : "…"}
        </span>
      </p>
      <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
        <code
          className="col-span-2 min-w-0 break-all rounded border bg-muted px-3 py-2 font-mono text-sm tracking-widest sm:flex-1"
          aria-label="Your user code"
        >
          {identity?.userCode || "U-····-····-····"}
        </code>
        <Button className="w-full sm:w-auto" variant="outline" size="sm" disabled={!identity} onClick={onCopy} aria-label="Copy your user code">
          {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
          <span className="ml-2 sm:sr-only">{copied ? "Copied" : "Copy"}</span>
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full sm:w-auto"
          disabled={!identity || isRotating}
          onClick={onRotate}
          aria-label="Replace your user code"
        >
          {isRotating ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
          <span className="ml-2 sm:sr-only">Replace</span>
        </Button>
      </div>
      {loadFailed && (
        <p className="text-sm text-destructive" role="status">
          Your username and user code could not be loaded. Reload the page to try again.
        </p>
      )}
    </div>
  );
}
