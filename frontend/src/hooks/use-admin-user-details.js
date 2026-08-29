import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { buildUserUpdatePayload } from "@/lib/admin-user-roles";
import { validatePassword } from "@/lib/password-policy";

/**
 * One account as an administrator sees it, and everything they can do to it.
 *
 * The account is fetched once per `userId`. Asking here rather than through a
 * shared loader means the page does not render twice before the request
 * exists, and a reply for a user we have navigated away from is ignored.
 */
export function useAdminUserDetails(userId) {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { user: currentUser } = useAuth();

  const [user, setUser] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [form, setForm] = useState({ name: "", password: "", roles: [], metadataApiEnabled: true });
  const [isSaving, setIsSaving] = useState(false);
  const [isRotatingCode, setIsRotatingCode] = useState(false);

  const failed = (title) => (error) => {
    toast({ title, description: error.message, variant: "destructive" });
  };

  useEffect(() => {
    let ignore = false;
    api.get(`/api/users/${userId}`)
      .then((data) => {
        if (ignore) return;
        setUser(data.user);
        setForm({
          name: data.user.name || "",
          password: "",
          roles: [...(data.user.roles || [])],
          metadataApiEnabled: data.user.metadataApiEnabled !== false,
        });
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
    const typedPassword = form.password.trim();
    if (typedPassword) {
      const errors = validatePassword(typedPassword);
      if (errors.length > 0) {
        toast({ title: "Password does not meet policy", description: errors.join(", "), variant: "destructive" });
        return;
      }
    }

    const payload = buildUserUpdatePayload(form, user, currentUser);
    // Only sent when it changed, so an ordinary name edit does not restate a
    // permission somebody else may have just altered.
    if (form.metadataApiEnabled !== (user.metadataApiEnabled !== false)) {
      payload.metadataApiEnabled = form.metadataApiEnabled;
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
      failed("Update failed")(error);
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
      failed("Verification failed")(error);
    }
  };

  const rotateUserCode = async () => {
    setIsRotatingCode(true);
    try {
      await api.post(`/api/users/${userId}/user-code/rotate`, {});
      toast({
        title: "User code replaced",
        description: "The old code no longer works. The user can see the new one on their Sharing page.",
      });
    } catch (error) {
      failed("Could not replace the code")(error);
    } finally {
      setIsRotatingCode(false);
    }
  };

  const deleteUser = async () => {
    try {
      await api.delete(`/api/users/${userId}`);
      toast({ title: "User deleted" });
      navigate("/admin?tab=users", { replace: true });
    } catch (error) {
      failed("Delete failed")(error);
    }
  };

  return {
    user,
    isLoading,
    notFound,
    form,
    setForm,
    isSaving,
    isRotatingCode,
    isSelf: Boolean(currentUser && user && currentUser.id === user.id),
    updateUser: (changes) => setUser((current) => ({ ...current, ...changes })),
    saveAccount,
    verifyUser,
    rotateUserCode,
    deleteUser,
  };
}
