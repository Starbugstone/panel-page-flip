import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

/** The confirmations guarding the two irreversible actions on an account. */
export function AdminUserDialogs({ user, deleting, rotating, isRotatingCode }) {
  return (
    <>
      <AlertDialog open={deleting.open} onOpenChange={deleting.onOpenChange}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {user.name || user.email}?</AlertDialogTitle>
            <AlertDialogDescription>
              This permanently removes the account and everything attached to it. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={(event) => { event.preventDefault(); deleting.onConfirm(); }}
            >
              Delete account
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={rotating.open} onOpenChange={rotating.onOpenChange}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Replace this user&apos;s user code?</AlertDialogTitle>
            <AlertDialogDescription>
              The old code stops working immediately, and anyone who still has it will need the new
              one. Existing shares are not affected. The user can see the new code on their own
              Sharing page.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            {/* Guarded in flight: the dialog stays open until the request
                settles, and every accepted request mints another code. An
                administrator pressing twice would leave the user reading a
                code that was replaced while they looked at it. */}
            <AlertDialogAction
              disabled={isRotatingCode}
              onClick={(event) => { event.preventDefault(); rotating.onConfirm(); }}
            >
              {isRotatingCode ? "Replacing…" : "Replace their code"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
