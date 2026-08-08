import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ArrowLeft, BookOpen, Cloud, MailCheck, ShieldAlert, Tags, Trash2 } from "lucide-react";

import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDateTime } from "@/lib/format";
import { validatePassword } from "@/lib/password-policy";

/**
 * Everything an administrator needs about one account, in one place.
 *
 * Reached from the Manage user button that replaced the promote-to-admin cog:
 * promotion is a role change like any other and belongs in the account form
 * below, not on a single click in a table row.
 */
/**
 * Remounted per account.
 *
 * Navigating from one user to another has to start from nothing: leaving the
 * previous account's name and roles in the form while the next one loads means
 * Save would post them to the new user's id. Clearing each piece of state in an
 * effect would do it a render too late; a new instance has nothing to clear.
 */
export default function AdminUserDetails() {
  const { userId } = useParams();
  return <AdminUserDetailsPage key={userId} userId={userId} />;
}

function AdminUserDetailsPage({ userId }) {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { user: currentUser } = useAuth();

  const [user, setUser] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [form, setForm] = useState({ name: "", password: "", roles: [] });
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleteOpen, setIsDeleteOpen] = useState(false);

  const passwordErrors = form.password ? validatePassword(form.password) : [];
  const isSelf = currentUser && user && currentUser.id === user.id;

  // The account is fetched once per userId. Asking here rather than through a
  // shared loader means the page does not render twice before the request
  // exists, and a reply for a user we have navigated away from is ignored.
  useEffect(() => {
    let ignore = false;
    api.get(`/api/users/${userId}`)
      .then((data) => {
        if (ignore) return;
        setUser(data.user);
        setForm({ name: data.user.name || "", password: "", roles: [...(data.user.roles || [])] });
        setNotFound(false);
      })
      .catch((error) => {
        if (ignore) return;
        logger.error(`Failed to load user ${userId}:`, error);
        setNotFound(true);
        toast({ title: "Could not load user", description: error.message, variant: "destructive" });
      })
      .finally(() => { if (!ignore) setIsLoading(false); });

    return () => { ignore = true; };
  }, [toast, userId]);

  const saveAccount = async () => {
    const payload = {};
    if (form.name.trim() && form.name.trim() !== user.name) payload.name = form.name.trim();
    if (form.password.trim()) {
      if (passwordErrors.length > 0) {
        toast({
          title: "Password does not meet policy",
          description: passwordErrors.join(", "),
          variant: "destructive",
        });
        return;
      }
      payload.password = form.password.trim();
    }

    const rolesChanged = JSON.stringify([...form.roles].sort()) !== JSON.stringify([...(user.roles || [])].sort());
    if (rolesChanged && !isSelf) {
      payload.roles = Array.from(new Set([...form.roles, "ROLE_USER"]));
    }

    if (Object.keys(payload).length === 0) {
      toast({ title: "Nothing to save" });
      return;
    }

    setIsSaving(true);
    try {
      const data = await api.put(`/api/users/${userId}`, payload);
      setUser((current) => ({ ...current, ...data.user }));
      setForm((current) => ({ ...current, password: "" }));
      toast({ title: "User updated" });
    } catch (error) {
      toast({ title: "Update failed", description: error.message, variant: "destructive" });
    } finally {
      setIsSaving(false);
    }
  };

  const verifyUser = async () => {
    try {
      const data = await api.post(`/api/users/${userId}/verify`, {});
      setUser((current) => ({ ...current, ...data.user }));
      toast({ title: "User verified" });
    } catch (error) {
      toast({ title: "Verification failed", description: error.message, variant: "destructive" });
    }
  };

  const deleteUser = async () => {
    try {
      await api.delete(`/api/users/${userId}`);
      toast({ title: "User deleted" });
      navigate("/admin?tab=users", { replace: true });
    } catch (error) {
      toast({ title: "Delete failed", description: error.message, variant: "destructive" });
    } finally {
      setIsDeleteOpen(false);
    }
  };

  if (isLoading) {
    return (
      <div className="container mx-auto flex justify-center px-4 py-16">
        <div className="h-12 w-12 animate-spin rounded-full border-b-4 border-t-4 border-primary" />
      </div>
    );
  }

  if (notFound || !user) {
    return (
      <div className="container mx-auto px-4 py-16 text-center">
        <h1 className="text-2xl font-bold">User not found</h1>
        <p className="mt-2 text-muted-foreground">This account may already have been deleted.</p>
        <Button asChild variant="outline" className="mt-6">
          <Link to="/admin?tab=users"><ArrowLeft className="mr-2 h-4 w-4" /> Back to users</Link>
        </Button>
      </div>
    );
  }

  const ownsComics = (user.comicCount || 0) > 0;

  return (
    <div className="container mx-auto max-w-6xl px-4 py-8">
      <Button asChild variant="ghost" size="sm" className="mb-4 -ml-2">
        {/* The users tab keeps its own page and search in the query string, so
            the browser's history entry restores exactly what was on screen. */}
        <Link to="/admin?tab=users"><ArrowLeft className="mr-2 h-4 w-4" /> Back to users</Link>
      </Button>

      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-comic">{user.name || user.email}</h1>
          <p className="mt-1 text-muted-foreground">{user.email}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {user.roles?.includes("ROLE_ADMIN") && <Badge>Admin</Badge>}
          {user.isEmailVerified
            ? <Badge variant="outline">Verified</Badge>
            : <Badge variant="destructive">Pending verification</Badge>}
        </div>
      </div>

      <Tabs defaultValue="overview" className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="comics">Comics ({user.comicCount ?? 0})</TabsTrigger>
          <TabsTrigger value="tags">Tags ({user.tagCount ?? 0})</TabsTrigger>
          <TabsTrigger value="account">Account</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SummaryCard icon={BookOpen} label="Comics owned" value={user.comicCount ?? 0} />
            <SummaryCard icon={Tags} label="Personal tags" value={user.tagCount ?? 0} />
            <SummaryCard icon={MailCheck} label="Created" value={formatDateTime(user.createdAt)} />
            <SummaryCard icon={MailCheck} label="Last login" value={formatDateTime(user.lastLoginAt, "Never")} />
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2"><Cloud className="h-5 w-5" /> Dropbox</CardTitle>
              <CardDescription>
                {user.dropboxConnected
                  ? `Connected. Last synced ${formatDateTime(user.dropboxLastSyncedAt, "never")}.`
                  : "Not connected."}
              </CardDescription>
            </CardHeader>
          </Card>

          {!user.isEmailVerified && (
            <Card>
              <CardHeader>
                <CardTitle>Email verification pending</CardTitle>
                <CardDescription>This account has not confirmed its email address.</CardDescription>
              </CardHeader>
              <CardContent>
                <Button onClick={verifyUser}>Mark as verified</Button>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="comics">
          <AdminComicsList ownerId={user.id} embedded />
        </TabsContent>

        <TabsContent value="tags">
          <AdminTagsList creatorId={user.id} embedded />
        </TabsContent>

        <TabsContent value="account" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Account settings</CardTitle>
              <CardDescription>Change the display name, reset the password, or adjust roles.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-2 sm:max-w-md">
                <Label htmlFor="admin-user-name">Name</Label>
                <Input
                  id="admin-user-name"
                  value={form.name}
                  onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                />
              </div>
              <div className="grid gap-2 sm:max-w-md">
                <Label htmlFor="admin-user-email">Email</Label>
                <Input id="admin-user-email" value={user.email} disabled />
              </div>
              <div className="grid gap-2 sm:max-w-md">
                <Label htmlFor="admin-user-password">New password</Label>
                <Input
                  id="admin-user-password"
                  type="password"
                  placeholder="Leave blank to keep the current password"
                  value={form.password}
                  onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
                />
                {form.password && passwordErrors.length > 0 && (
                  <p className="text-sm text-muted-foreground">
                    Password must include: {passwordErrors.join(", ")}.
                  </p>
                )}
              </div>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="admin-user-role-admin"
                  checked={form.roles.includes("ROLE_ADMIN")}
                  disabled={isSelf}
                  onCheckedChange={(checked) => setForm((current) => ({
                    ...current,
                    roles: checked
                      ? Array.from(new Set([...current.roles, "ROLE_ADMIN"]))
                      : current.roles.filter((role) => role !== "ROLE_ADMIN"),
                  }))}
                />
                <Label htmlFor="admin-user-role-admin" className="font-normal">
                  Administrator
                  {isSelf && <span className="ml-1 text-xs text-muted-foreground">(cannot change your own role)</span>}
                </Label>
              </div>
              <Button onClick={saveAccount} disabled={isSaving}>
                {isSaving ? "Saving…" : "Save changes"}
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
              <Button
                variant="destructive"
                disabled={ownsComics || isSelf}
                onClick={() => setIsDeleteOpen(true)}
              >
                <Trash2 className="mr-2 h-4 w-4" /> Delete this account
              </Button>
              {isSelf && <p className="mt-2 text-sm text-muted-foreground">You cannot delete your own account here.</p>}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <AlertDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
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
              onClick={(event) => { event.preventDefault(); deleteUser(); }}
            >
              Delete account
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function SummaryCard({ icon: Icon, label, value }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
        <div className="min-w-0">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
          <p className="truncate font-medium">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
