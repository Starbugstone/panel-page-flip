import { createContext, useContext, useEffect, useState } from "react";
import { getCookie, setCookie } from "@/lib/cookies";

const initialState = {
  theme: "light",
  setTheme: () => null,
};

const ThemeProviderContext = createContext(initialState);

export function ThemeProvider({
  children,
  defaultTheme = "light",
  storageKey = "comic-reader-theme",
  ...props
}) {
  const [theme, setTheme] = useState(() => {
    try {
      // Try to get theme from cookie first
      const cookieTheme = getCookie(storageKey);
      
      // If cookie exists, use it
      if (cookieTheme) {
        return cookieTheme;
      }
      
      // Otherwise, check localStorage for backward compatibility
      const localTheme = localStorage.getItem(storageKey);
      if (localTheme) {
        // Migrate from localStorage to cookie
        setCookie(storageKey, localTheme);
        return localTheme;
      }
      
      // Default theme if nothing is found
      return defaultTheme;
    } catch {
      return defaultTheme;
    }
  });

  useEffect(() => {
    try {
      const root = window.document.documentElement;
      root.classList.remove("light", "dark");
      root.classList.add(theme);
    } catch {
      // The next render will retry when the document becomes available.
    }
  }, [theme]);

  const value = {
    theme,
    setTheme: (newTheme) => {
      try {
        // Set the cookie to persist theme preference (365 days expiration)
        setCookie(storageKey, newTheme, 365);
        
        // Also update localStorage for backward compatibility
        localStorage.setItem(storageKey, newTheme);
        
        // Update state
        setTheme(newTheme);
      } catch {
        // Keep the current theme when browser storage is unavailable.
      }
    },
  };

  return (
    <ThemeProviderContext.Provider {...props} value={value}>
      {children}
    </ThemeProviderContext.Provider>
  );
}

export const useTheme = () => {
  const context = useContext(ThemeProviderContext);

  if (context === undefined)
    throw new Error("useTheme must be used within a ThemeProvider");

  return context;
};
