import { ShieldAlert, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * The two irreversible things, each behind its own button and confirmation.
 *
 * The replaced user code is deliberately not shown: an administrator has no
 * reason to hold somebody's contact handle, and the user reads the new one off
 * their own Sharing page. Deleting is refused while the account still owns
 * comics, so no library ever disappears as a side effect.
 */
export function AdminUserDangerZone({ user, isSelf, isRotatingCode, onRotate, onDelete }) {
  const ownsComics = (user.comicCount || 0) > 0;

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle>User code</CardTitle>
          <CardDescription>
            Replace this user&apos;s <code>U-</code> code if it has been posted somewhere they
            did not intend. The old code stops working immediately. Comics already shared with
            them, and people they have shared with, are not affected.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="outline" onClick={onRotate} disabled={isRotatingCode}>
            {isRotatingCode ? "Replacing…" : "Replace user code"}
          </Button>
        </CardContent>
      </Card>

      <Card className="border-destructive/40">
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><ShieldAlert className="h-5 w-5" /> Delete account</CardTitle>
          <CardDescription>
            {ownsComics
              ? "This account still owns comics. Review and remove them from the Comics tab first — deleting an account never deletes a library by surprise."
              : "Removes the account, its personal tags, shares and reading history."}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="destructive" disabled={ownsComics || isSelf} onClick={onDelete}>
            <Trash2 className="mr-2 h-4 w-4" /> Delete this account
          </Button>
          {isSelf && <p className="mt-2 text-sm text-muted-foreground">You cannot delete your own account here.</p>}
        </CardContent>
      </Card>
    </>
  );
}
