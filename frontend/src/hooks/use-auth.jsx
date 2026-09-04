import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { api, UNAUTHORIZED_EVENT } from "@/lib/api";
import { logger } from "@/lib/logger";
import sessionManager from "@/lib/session-manager";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(false);
  const sessionRevision = useRef(0);

  const clearClientSession = useCallback(() => {
    sessionRevision.current += 1;
    setUser(null);
  }, []);

  const checkAuth = useCallback(async () => {
    const revision = ++sessionRevision.current;
    try {
      const data = await api.get("/api/me", { notifyUnauthorized: false });
      if (revision !== sessionRevision.current) return false;
      const authenticatedUser = data?.user ?? null;
      setUser(authenticatedUser);
      return Boolean(authenticatedUser);
    } catch (error) {
      if (revision !== sessionRevision.current) return false;
      logger.warn("Authentication check failed:", error.message);
      clearClientSession();
      return false;
    }
  }, [clearClientSession]);

  useEffect(() => {
    let active = true;
    const validateSession = async () => {
      await checkAuth();
      if (active) setLoading(false);
    };
    validateSession();
    return () => { active = false; sessionRevision.current += 1; };
  }, [checkAuth]);

  useEffect(() => {
    const handleUnauthorized = () => {
      setSessionExpired(true);
      clearClientSession();
    };
    window.addEventListener(UNAUTHORIZED_EVENT, handleUnauthorized);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, handleUnauthorized);
  }, [clearClientSession]);

  const login = useCallback(async (email, password) => {
    const revision = ++sessionRevision.current;
    try {
      const data = await api.post("/api/login", { email, password }, { notifyUnauthorized: false });
      if (revision === sessionRevision.current) {
        setUser(data.user);
        setSessionExpired(false);
      }
      return data;
    } catch (error) {
      if (error.status === 403 && error.data?.requiresVerification) {
        throw new Error(error.message || "Email verification required", {
          cause: { requiresVerification: true, email },
        });
      }
      throw error;
    }
  }, []);

  const register = useCallback((email, password, name = "", agreeTerms = false, username = "") => (
    api.post(
      "/api/register",
      { email, password, plainPassword: password, name, agreeTerms, username },
      { notifyUnauthorized: false },
    )
  ), []);

  const logout = useCallback(async () => {
    sessionManager.stop();
    setSessionExpired(false);
    clearClientSession();

    try {
      await api.post("/api/logout", {}, { notifyUnauthorized: false });
    } catch (error) {
      logger.warn("Logout request failed after clearing the local session:", error.message);
    }
  }, [clearClientSession]);

  useEffect(() => {
    if (user) {
      sessionManager.start({
        onSessionExpired: () => {
          setSessionExpired(true);
          clearClientSession();
        },
      });
    } else {
      sessionManager.stop();
    }
    return () => sessionManager.stop();
  }, [clearClientSession, user]);

  const refreshSession = useCallback(async () => {
    const revision = sessionRevision.current;
    const valid = await sessionManager.forceSessionCheck();
    if (revision !== sessionRevision.current) return false;
    if (!valid) clearClientSession();
    return valid;
  }, [clearClientSession]);

  const value = useMemo(() => ({
    user,
    loading,
    login,
    register,
    logout,
    checkAuth,
    isAuthenticated: Boolean(user),
    // UI convenience only; the API remains the authorization boundary.
    isAdmin: Boolean(user?.roles?.includes("ROLE_ADMIN")),
    sessionExpired,
    refreshSession,
  }), [checkAuth, loading, login, logout, refreshSession, register, sessionExpired, user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === null) throw new Error("useAuth must be used within an AuthProvider");
  return context;
}
