export function accountDeletionReauthenticationUrl(provider) {
  return `/api/auth/oauth/${provider}/start?purpose=delete-account&redirect=${encodeURIComponent("/settings")}`;
}
