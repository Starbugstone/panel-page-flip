import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, UNAUTHORIZED_EVENT } from "@/lib/api";
import { logger } from "@/lib/logger";
import sessionManager from "@/lib/session-manager";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(false);

  const clearClientSession = useCallback(() => {
    setUser(null);
  }, []);

  const checkAuth = useCallback(async () => {
    try {
      const data = await api.get("/api/me", { notifyUnauthorized: false });
      setUser(data.user);
      return true;
    } catch (error) {
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
    return () => { active = false; };
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
    try {
      const data = await api.post("/api/login", { email, password }, { notifyUnauthorized: false });
      setUser(data.user);
      setSessionExpired(false);
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

  const register = useCallback((email, password, name = "", agreeTerms = false) => (
    api.post(
      "/api/register",
      { email, password, plainPassword: password, name, agreeTerms },
      { notifyUnauthorized: false },
    )
  ), []);

  const logout = useCallback(async () => {
    try {
      await api.post("/api/logout_user", {}, { notifyUnauthorized: false });
    } catch (error) {
      logger.warn("Logout request failed; clearing the local session:", error.message);
    } finally {
      clearClientSession();
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
    const valid = await sessionManager.forceSessionCheck();
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
