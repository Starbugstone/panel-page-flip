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
      // Applied first, and outside the try: persistence is a convenience, but
      // the user asking for a theme is the whole request. A browser that
      // refuses site data — a private window, blocked cookies — throws on the
      // write, and doing it the other way round swallowed the click along with
      // it, so the toggle did nothing at all rather than working for the
      // session.
      setTheme(newTheme);

      try {
        setCookie(storageKey, newTheme, 365);
      } catch {
        // Unpersisted, not unapplied.
      }

      try {
        // Still written for a session that started before the cookie existed.
        localStorage.setItem(storageKey, newTheme);
      } catch {
        // Same again: the cookie above is the one that matters.
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
