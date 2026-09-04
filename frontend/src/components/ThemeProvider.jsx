import { createContext, useContext, useEffect, useState } from "react";
import { getCookie, setCookie } from "@/lib/cookies";

const isTheme = (value) => value === "light" || value === "dark";

const ThemeProviderContext = createContext(undefined);

function readTheme(storageKey, fallback) {
  try {
    const saved = getCookie(storageKey);
    if (isTheme(saved)) return saved;
  } catch {
    // Site-data access may be disabled independently for cookies and storage.
  }

  try {
    const legacy = localStorage.getItem(storageKey);
    if (isTheme(legacy)) {
      try { setCookie(storageKey, legacy); } catch { /* Migration is optional. */ }
      return legacy;
    }
  } catch {
    // The selected theme still works for this session without persistence.
  }

  return isTheme(fallback) ? fallback : "light";
}

export function ThemeProvider({
  children,
  defaultTheme = "light",
  storageKey = "comic-reader-theme",
  ...props
}) {
  const [theme, setTheme] = useState(() => readTheme(storageKey, defaultTheme));

  useEffect(() => {
    const root = window.document.documentElement;
    root.classList.remove("light", "dark");
    root.classList.add(theme);
    root.style.colorScheme = theme;
  }, [theme]);

  const value = {
    theme,
    setTheme: (newTheme) => {
      if (!isTheme(newTheme)) return;
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
