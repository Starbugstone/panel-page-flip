import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { buildUserUpdatePayload } from "@/lib/admin-user-roles";
import { validatePassword } from "@/lib/password-policy";

/**
 * The administrator's side of an account.
 *
 * Anything that changes which rows belong on the page reloads instead of
 * splicing: a row leaving pulls one up from the next page, and the total has
 * changed. An in-place edit that keeps the row where it is updates it directly,
 * so the table does not flicker.
 */
export function useAdminUserActions({ reload, setUsers, showOnlyUnverified, currentUser }) {
  const { toast } = useToast();

  const failed = (title) => (error) => {
    toast({ title, description: error.message, variant: "destructive" });
  };

  const rejectWeakPassword = (password) => {
    const errors = validatePassword(password);
    if (errors.length === 0) return false;
    toast({ title: "Password does not meet policy", description: errors.join(", "), variant: "destructive" });
    return true;
  };

  const deleteUser = async (userId) => {
    try {
      await api.delete(`/api/users/${userId}`);
      reload();
      toast({ title: "User deleted" });
    } catch (error) {
      failed("Delete failed")(error);
    }
  };

  /** @returns {boolean} whether the dialog should close */
  const saveUser = async (form, editingUser) => {
    if (form.password?.trim() && rejectWeakPassword(form.password.trim())) return false;

    const payload = buildUserUpdatePayload(form, editingUser, currentUser);
    if (Object.keys(payload).length === 0) return true;

    try {
      const response = await api.put(`/api/users/${editingUser.id}`, payload);
      setUsers((users) => users.map((user) => (
        user.id === editingUser.id ? { ...user, ...response.user } : user
      )));
      toast({ title: "User updated" });
      return true;
    } catch (error) {
      failed("Update failed")(error);
      return false;
    }
  };

  /** @returns {boolean} whether the dialog should close */
  const createUser = async (newUser) => {
    if (!newUser.email || !newUser.password || !newUser.name) {
      toast({ title: "Missing fields", description: "Name, email and password are required.", variant: "destructive" });
      return false;
    }
    if (rejectWeakPassword(newUser.password)) return false;

    try {
      const data = await api.post("/api/users", newUser);
      reload();
      toast({ title: "User created", description: `${data.user.email} can log in immediately.` });
      return true;
    } catch (error) {
      failed("Create user failed")(error);
      return false;
    }
  };

  const verifyUser = async (userId) => {
    try {
      const data = await api.post(`/api/users/${userId}/verify`, {});
      if (showOnlyUnverified) {
        // The row no longer belongs on this list, so the page has to be refilled.
        reload();
      } else {
        setUsers((users) => users.map((user) => (user.id === userId ? { ...user, ...data.user } : user)));
      }
      toast({ title: "User verified" });
    } catch (error) {
      failed("Verification failed")(error);
    }
  };

  const resendVerification = async (user) => {
    try {
      await api.post("/api/email-verification/resend", { email: user.email });
      toast({ title: "Verification email sent" });
    } catch (error) {
      failed("Resend failed")(error);
    }
  };

  return { deleteUser, saveUser, createUser, verifyUser, resendVerification };
}
