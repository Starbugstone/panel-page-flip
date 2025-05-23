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
        console.log('Using theme from cookie:', cookieTheme);
        return cookieTheme;
      }
      
      // Otherwise, check localStorage for backward compatibility
      const localTheme = localStorage.getItem(storageKey);
      if (localTheme) {
        console.log('Using theme from localStorage:', localTheme);
        // Migrate from localStorage to cookie
        setCookie(storageKey, localTheme);
        return localTheme;
      }
      
      // Default theme if nothing is found
      console.log('Using default theme:', defaultTheme);
      return defaultTheme;
    } catch (error) {
      console.error('Error initializing theme:', error);
      return defaultTheme;
    }
  });

  useEffect(() => {
    try {
      const root = window.document.documentElement;
      root.classList.remove("light", "dark");
      root.classList.add(theme);
    } catch (error) {
      console.error('Error applying theme to document:', error);
    }
  }, [theme]);

  const value = {
    theme,
    setTheme: (newTheme) => {
      try {
        console.log(`Setting theme to: ${newTheme}`);
        
        // Set the cookie to persist theme preference (365 days expiration)
        setCookie(storageKey, newTheme, 365);
        
        // Also update localStorage for backward compatibility
        localStorage.setItem(storageKey, newTheme);
        
        // Update state
        setTheme(newTheme);
      } catch (error) {
        console.error('Error setting theme:', error);
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
