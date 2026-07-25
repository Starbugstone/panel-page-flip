import js from "@eslint/js";
import globals from "globals";
import react from "eslint-plugin-react";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";

// Removed: import tseslint from "typescript-eslint";

export default [ // Changed from tseslint.config to a plain array
  { ignores: ["dist"] },
  js.configs.recommended,
  {
    files: ["**/*.{js,jsx}"], // Changed from ts,tsx to js,jsx
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "module", // Added for clarity with ES modules
      globals: {
        ...globals.browser,
        ...globals.node,
      },
      parserOptions: {
        ecmaFeatures: {
          jsx: true,
        },
      },
    },
    plugins: {
      react,
      "react-hooks": reactHooks,
      "react-refresh": reactRefresh,
    },
    rules: {
      "react/jsx-uses-vars": "error",
      "react/jsx-uses-react": "off",
      "no-unused-vars": "warn",
      "no-useless-catch": "warn",
      "no-useless-escape": "warn",
      ...reactHooks.configs.recommended.rules,
      "react-refresh/only-export-components": [
        "warn",
        { allowConstantExport: true },
      ],
      // Removed: "@typescript-eslint/no-unused-vars": "off",
      // Standard 'no-unused-vars' from js.configs.recommended will apply.
    },
  },
  {
    files: ["src/components/ui/**/*.{js,jsx}", "src/components/ThemeProvider.jsx", "src/hooks/**/*.{js,jsx}"],
    rules: {
      "react-refresh/only-export-components": "off",
    },
  }
];
