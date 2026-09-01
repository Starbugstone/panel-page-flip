export function resolveLocalRedirect(destination, fallback = "/dashboard") {
  if (typeof destination !== "string"
    || !destination.startsWith("/")
    || destination.startsWith("//")
    || destination.includes("\\")
    || Array.from(destination).some((character) => {
      const code = character.charCodeAt(0);
      return code < 32 || code === 127;
    })) {
    return fallback;
  }

  return destination;
}
