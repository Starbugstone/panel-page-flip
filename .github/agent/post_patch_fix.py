from pathlib import Path
import json

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

# HttpClient is used by production Dropbox code. It happened to be available
# through the old dependency graph, but classifying it as require-dev means a
# production --no-dev install can remove the actual service behind the contract.
p = Path('backend/composer.json')
data = json.loads(p.read_text())
version = data.get('require-dev', {}).pop('symfony/http-client', '6.4.*')
data.setdefault('require', {})['symfony/http-client'] = version
p.write_text(json.dumps(data, indent=4, ensure_ascii=False) + '\n')

# A diagnostic from a previous runner attempt is never part of the product diff.
Path('.github/agent/cache-clear-error.txt').unlink(missing_ok=True)
