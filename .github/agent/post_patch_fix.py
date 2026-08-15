from pathlib import Path

# Restore the quota scalar on StorageQuotaService and remove the obsolete
# controller-level copy after the main transformation.
p = Path('backend/config/services.yaml')
s = p.read_text()
line = "            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'\n"
marker = "    App\\Service\\StorageQuotaService:\n        arguments:\n\n"
if marker in s:
    s = s.replace(marker, "    App\\Service\\StorageQuotaService:\n        arguments:\n" + line, 1)
# If the scalar occurs more than once, keep the first one (StorageQuotaService)
# and remove subsequent legacy wiring.
first = s.find(line)
if first != -1:
    tail = s[first + len(line):].replace(line, '')
    s = s[:first + len(line)] + tail
p.write_text(s)

# A newly registered User has been scheduled for persistence but has no ID until
# the first flush. There are no older verification tokens to invalidate yet, so
# don't query by an unpersisted association.
p = Path('backend/src/Service/EmailVerificationService.php')
s = p.read_text()
old = """    public function issue(User $user): string
    {
        $this->removeTokensFor($user);
        $token = new EmailVerificationToken($user);
"""
new = """    public function issue(User $user): string
    {
        if ($user->getId() !== null) {
            $this->removeTokensFor($user);
        }
        $token = new EmailVerificationToken($user);
"""
if old not in s:
    raise SystemExit('EmailVerificationService issue() marker not found')
p.write_text(s.replace(old, new, 1))
