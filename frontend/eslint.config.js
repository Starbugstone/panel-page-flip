import js from "@eslint/js";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";

// Removed: import tseslint from "typescript-eslint";

export default [ // Changed from tseslint.config to a plain array
  { ignores: ["dist"] },
  {
    // Removed: extends: [js.configs.recommended, ...tseslint.configs.recommended],
    // Now only extends js.configs.recommended
    extends: [js.configs.recommended],
    files: ["**/*.{js,jsx}"], // Changed from ts,tsx to js,jsx
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "module", // Added for clarity with ES modules
      globals: globals.browser,
    },
    plugins: {
      "react-hooks": reactHooks,
      "react-refresh": reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      "react-refresh/only-export-components": [
        "warn",
        { allowConstantExport: true },
      ],
      // Removed: "@typescript-eslint/no-unused-vars": "off",
      // Standard 'no-unused-vars' from js.configs.recommended will apply.
    },
  }
];
