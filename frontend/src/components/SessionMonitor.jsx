import { useAuth } from "@/hooks/use-auth";
import { AlertDialog, AlertDialogAction, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";

export default function SessionMonitor() {
  const { sessionExpired } = useAuth();

  // Whether the dialog is open is not its own piece of state: it is exactly
  // "has the session expired". Copying that into local state from an effect
  // also made the dialog dismissable with Escape, which left the reader in an
  // application that could no longer talk to the server and said nothing about
  // it. The only way out is the action below.
  return (
    <AlertDialog open={sessionExpired}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Session Expired</AlertDialogTitle>
          <AlertDialogDescription>Your session has expired. Please log in again to continue.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogAction onClick={() => { window.location.href = "/login"; }}>Log in</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
