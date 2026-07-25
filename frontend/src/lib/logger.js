const isDevelopment = import.meta.env.DEV;

export const logger = {
  log: (...args) => isDevelopment && console.log(...args),
  warn: (...args) => isDevelopment && console.warn(...args),
  error: (...args) => console.error(...args),
};
