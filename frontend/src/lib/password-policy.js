export function validatePassword(password) {
  const errors = [];

  if (password.length < 12) {
    errors.push("At least 12 characters");
  }
  if (!/[a-z]/.test(password) || !/[A-Z]/.test(password)) {
    errors.push("Uppercase and lowercase letters");
  }
  if (!/\d/.test(password)) {
    errors.push("At least one digit");
  }
  if (!/[^A-Za-z0-9]/.test(password)) {
    errors.push("At least one symbol");
  }

  return errors;
}
