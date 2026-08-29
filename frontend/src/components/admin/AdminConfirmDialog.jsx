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

/**
 * A pending destructive action, described by whoever asked for it.
 *
 * The action is cleared before it runs, so a second click on a dialog that has
 * not finished closing cannot fire it twice.
 */
export function AdminConfirmDialog({ action, onClear }) {
  return (
    <AlertDialog open={Boolean(action)} onOpenChange={(open) => { if (!open) onClear(); }}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{action?.title}</AlertDialogTitle>
          <AlertDialogDescription>{action?.description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction onClick={() => {
            const confirmed = action?.onConfirm;
            onClear();
            confirmed?.();
          }}>
            Confirm
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
