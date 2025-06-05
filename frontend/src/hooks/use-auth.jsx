import { useState, useEffect, createContext, useContext } from 'react';
import sessionManager from '@/lib/session-manager';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(false);

  // Check if user is already logged in on mount and validate session with backend
  useEffect(() => {
    const validateSession = async () => {
      const storedUser = localStorage.getItem('user');
      if (storedUser) {
        try {
          setUser(JSON.parse(storedUser));
          // Validate the session with the backend
          const isValid = await checkAuth();
          if (!isValid) {
            console.log('Session expired, please log in again');
            // Session is invalid, user will need to log in again
          }
        } catch (error) {
          console.error('Failed to parse stored user data:', error);
          localStorage.removeItem('user');
        }
      }
      setLoading(false);
    };
    
    validateSession();
  }, []);

  const login = async (email, password) => {
    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
        credentials: 'include', // Important for cookies
      });

      const data = await response.json();
      
      // Check if the response indicates email verification is required
      if (response.status === 403 && data.requiresVerification) {
        throw new Error(data.message || 'Email verification required', { cause: { requiresVerification: true, email } });
      }
      
      if (!response.ok) {
        throw new Error(data.message || 'Login failed');
      }
      
      // Store user data in localStorage
      localStorage.setItem('user', JSON.stringify(data.user));
      setUser(data.user);
      
      return data;
    } catch (error) {
      console.error('Login error:', error);
      throw error;
    }
  };

  const register = async (email, password) => {
    try {
      const response = await fetch('/api/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password, plainPassword: password }),
        credentials: 'include',
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Registration failed');
      }

      const data = await response.json();
      return data;
    } catch (error) {
      console.error('Registration error:', error);
      throw error;
    }
  };

  const logout = async () => {
    try {
      // Use the new programmatic logout endpoint
      const response = await fetch('/api/logout_user', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json'
        }
      });
      
      if (!response.ok) {
        console.warn('Logout API call failed, but proceeding with client-side logout');
      }
    } catch (error) {
      console.error('Logout error:', error);
      // Continue with client-side logout even if API call fails
    } finally {
      // Always clear local user data regardless of API response
      localStorage.removeItem('user');
      setUser(null);
    }
  };

  const checkAuth = async () => {
    try {
      // Fetch user data from /api/users/me to get roles and other details
      const response = await fetch('/api/users/me', {
        credentials: 'include',
      });

      if (!response.ok) {
        // If not authenticated, clear local storage
        localStorage.removeItem('user');
        setUser(null);
        return false;
      }

      const data = await response.json();
      setUser(data.user);
      localStorage.setItem('user', JSON.stringify(data.user));
      return true;
    } catch (error) {
      console.error('Auth check error:', error);
      localStorage.removeItem('user');
      setUser(null);
      return false;
    }
  };

  // Initialize session manager when user is authenticated
  useEffect(() => {
    if (user) {
      // Start session manager with session expiration handler
      sessionManager.start({
        onSessionExpired: () => {
          console.log('Session expired detected by session manager');
          setSessionExpired(true);
          logout();
        }
      });
    } else {
      // Stop session manager when user is not authenticated
      sessionManager.stop();
    }
    
    return () => {
      // Clean up on unmount
      sessionManager.stop();
    };
  }, [user]);

  // Handle session expired state
  useEffect(() => {
    if (sessionExpired && user) {
      logout();
      setSessionExpired(false);
    }
  }, [sessionExpired]);

  const value = {
    user,
    loading,
    login,
    register,
    logout,
    checkAuth,
    isAuthenticated: !!user,
    sessionExpired,
    refreshSession: async () => {
      const isValid = await sessionManager.forceSessionCheck();
      if (!isValid && user) {
        logout();
      }
      return isValid;
    }
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === null) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
