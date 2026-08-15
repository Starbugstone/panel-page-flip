from pathlib import Path
import json
import subprocess

# Restore the quota scalar on StorageQuotaService and remove the obsolete
# controller-level copy after the main transformation.
p = Path('backend/config/services.yaml')
s = p.read_text()
line = "            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'\n"
marker = "    App\\Service\\StorageQuotaService:\n        arguments:\n\n"
if marker in s:
    s = s.replace(marker, "    App\\Service\\StorageQuotaService:\n        arguments:\n" + line, 1)
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

# The first transformation experimented with renaming every controller's
# top-level `error` key to `message`. That is an unnecessary compatibility break:
# the frontend client already accepts both. Restore exactly the response lines
# that used `error` on main while keeping the new centralized JSON decoder.
for path in Path('backend/src/Controller').glob('*.php'):
    try:
        original = subprocess.check_output(
            ['git', 'show', f'HEAD:{path.as_posix()}'],
            text=True,
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError:
        continue
    current = path.read_text()
    for original_line in original.splitlines():
        if "['error' =>" not in original_line:
            continue
        changed_line = original_line.replace("['error' =>", "['message' =>")
        current = current.replace(changed_line, original_line)
    path.write_text(current)

# ComicInfo.xml never needs a DTD. Rejecting any DOCTYPE before libxml sees it
# makes entity expansion/XXE behavior independent of libxml build defaults and
# is stricter than relying on NOENT being absent.
p = Path('backend/src/Metadata/ComicInfoParser.php')
s = p.read_text()
needle = """        if ($xml === '' || strlen($xml) > self::MAX_DOCUMENT_BYTES) {
            return null;
        }

        $root = $this->rootElement($xml);
"""
replacement = """        if ($xml === '' || strlen($xml) > self::MAX_DOCUMENT_BYTES) {
            return null;
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            return null;
        }

        $root = $this->rootElement($xml);
"""
if needle not in s:
    raise SystemExit('ComicInfoParser parse guard marker not found')
p.write_text(s.replace(needle, replacement, 1))

# A scalar body is valid JSON but not a valid API command. The centralized
# decoder now rejects it consistently instead of silently treating it as a
# no-op; update the old regression to assert that stronger contract.
p = Path('backend/tests/Functional/Controller/ExplicitShareControllerTest.php')
s = p.read_text()
old = """        self::assertResponseIsSuccessful();
        self::assertFalse($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);
"""
new = """        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);
"""
if old not in s:
    raise SystemExit('ExplicitShare scalar JSON assertion marker not found')
p.write_text(s.replace(old, new, 1))

# Diagnostics from previous runner attempts are never part of the product diff.
Path('.github/agent/cache-clear-error.txt').unlink(missing_ok=True)
Path('.github/agent/phpunit-error.txt').unlink(missing_ok=True)
