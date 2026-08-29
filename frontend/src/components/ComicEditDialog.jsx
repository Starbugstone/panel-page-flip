import { ComicEditDialogBody } from "@/components/comic-edit/ComicEditDialogBody";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useComicEditForm } from "@/hooks/use-comic-edit-form";

/** Remounts the draft for every comic and every fresh opening. */
export function ComicEditDialog({ comic, ...props }) {
  return <ComicEditDialogForm key={`${comic?.id ?? "none"}:${props.isOpen ? "open" : "closed"}`} comic={comic} {...props} />;
}

function ComicEditDialogForm({ comic, isOpen, onClose, onSave }) {
  const form = useComicEditForm(comic, onSave, onClose);

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Edit Comic Details</DialogTitle>
          <DialogDescription>Change the details stored for this comic, including whether it is classified 18+.</DialogDescription>
        </DialogHeader>
        <ComicEditDialogBody comic={comic} form={form} />
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={form.submit} disabled={form.isSubmitting}>{form.isSubmitting ? "Saving..." : "Save Changes"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
