from pathlib import Path
import json
import re
import shutil


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, content: str) -> None:
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(content)


def replace(path: str, old: str, new: str, count: int | None = None) -> None:
    text = read(path)
    actual = text.count(old)
    if actual == 0:
        raise SystemExit(f"Expected text not found in {path}: {old[:100]!r}")
    if count is not None and actual != count:
        raise SystemExit(f"Expected {count} occurrence(s) in {path}, found {actual}: {old[:100]!r}")
    write(path, text.replace(old, new))


def sub(path: str, pattern: str, repl: str, count: int = 1, flags: int = 0) -> None:
    text = read(path)
    updated, actual = re.subn(pattern, repl, text, count=count, flags=flags)
    if actual != count:
        raise SystemExit(f"Expected regex {count} occurrence(s) in {path}, found {actual}: {pattern[:100]!r}")
    write(path, updated)


def ensure_strict(path: str) -> None:
    text = read(path)
    if 'declare(strict_types=1);' not in text:
        write(path, text.replace('<?php\n', '<?php\n\ndeclare(strict_types=1);\n', 1))


# ---------------------------------------------------------------------------
# Encryption configuration and persistence safety.
# ---------------------------------------------------------------------------
write('backend/src/Service/AppDataEncryptionService.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

final class AppDataEncryptionService
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(string $appDataKey)
    {
        $this->key = $this->normaliseKey($appDataKey);
    }

    public function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->isEncrypted($value)) {
            return $value;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !$this->isEncrypted($value)) {
            // Legacy plaintext remains readable so the explicit migration
            // command can encrypt it. New writes are always encrypted.
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted application data is malformed.');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt application data. Verify APP_DATA_KEY.');
        }

        return $plaintext;
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    private function normaliseKey(string $key): string
    {
        if (ctype_xdigit($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
            $decoded = hex2bin($key);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        throw new \InvalidArgumentException(
            'APP_DATA_KEY must be exactly 32 random bytes encoded as 64 hexadecimal characters or Base64.'
        );
    }
}
''')

replace(
    'backend/.env',
    'APP_DATA_KEY=ChangeMeInEnvLocal',
    'APP_DATA_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    1,
)
replace(
    'backend/.env.test',
    "APP_DATA_KEY='test_app_data_key_change_in_real_envs'",
    "APP_DATA_KEY='abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789'",
    1,
)

write('backend/src/EventSubscriber/UserSecretsSubscriber.php', r'''<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class UserSecretsSubscriber
{
    /** @var array<int, array{access: ?string, refresh: ?string}> */
    private array $logicalSnapshots = [];

    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User) {
            return;
        }

        $user->setDropboxAccessToken($this->encryption->encrypt($user->getDropboxAccessToken()));
        $user->setDropboxRefreshToken($this->encryption->encrypt($user->getDropboxRefreshToken()));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User) {
            return;
        }

        $snapshot = $this->logicalSnapshots[spl_object_id($user)] ?? [
            'access' => $user->getDropboxAccessToken(),
            'refresh' => $user->getDropboxRefreshToken(),
        ];
        $accessChanged = $user->getDropboxAccessToken() !== $snapshot['access'];
        $refreshChanged = $user->getDropboxRefreshToken() !== $snapshot['refresh'];

        if (!$accessChanged && !$refreshChanged) {
            return;
        }

        if ($accessChanged) {
            $user->setDropboxAccessToken($this->encryption->encrypt($user->getDropboxAccessToken()));
        }
        if ($refreshChanged) {
            $user->setDropboxRefreshToken($this->encryption->encrypt($user->getDropboxRefreshToken()));
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(User::class),
            $user
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    private function decryptAndSynchronize(User $user, EntityManagerInterface $entityManager): void
    {
        $oid = spl_object_id($user);
        $access = $this->encryption->decrypt($user->getDropboxAccessToken());
        $refresh = $this->encryption->decrypt($user->getDropboxRefreshToken());

        $user->setDropboxAccessToken($access);
        $user->setDropboxRefreshToken($refresh);
        $this->logicalSnapshots[$oid] = ['access' => $access, 'refresh' => $refresh];

        // The representation changed, not the logical value. Synchronizing the
        // UnitOfWork snapshot is what prevents a later unrelated flush from
        // treating plaintext-vs-ciphertext as a credential edit.
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty($oid, 'dropboxAccessToken', $access);
        $unitOfWork->setOriginalEntityProperty($oid, 'dropboxRefreshToken', $refresh);
    }
}
''')

write('backend/src/EventSubscriber/MetadataProviderSecretsSubscriber.php', r'''<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\MetadataProviderConfiguration;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class MetadataProviderSecretsSubscriber
{
    /** @var array<int, array{metron: ?string, comicVine: ?string}> */
    private array $logicalSnapshots = [];

    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $configuration = $args->getObject();
        if (!$configuration instanceof MetadataProviderConfiguration) {
            return;
        }

        $configuration->setMetronPassword($this->encryption->encrypt($configuration->getMetronPassword()));
        $configuration->setComicVineApiKey($this->encryption->encrypt($configuration->getComicVineApiKey()));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $configuration = $args->getObject();
        if (!$configuration instanceof MetadataProviderConfiguration) {
            return;
        }

        $snapshot = $this->logicalSnapshots[spl_object_id($configuration)] ?? [
            'metron' => $configuration->getMetronPassword(),
            'comicVine' => $configuration->getComicVineApiKey(),
        ];
        $metronChanged = $configuration->getMetronPassword() !== $snapshot['metron'];
        $comicVineChanged = $configuration->getComicVineApiKey() !== $snapshot['comicVine'];

        if (!$metronChanged && !$comicVineChanged) {
            return;
        }

        if ($metronChanged) {
            $configuration->setMetronPassword($this->encryption->encrypt($configuration->getMetronPassword()));
        }
        if ($comicVineChanged) {
            $configuration->setComicVineApiKey($this->encryption->encrypt($configuration->getComicVineApiKey()));
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(MetadataProviderConfiguration::class),
            $configuration
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    private function decryptAndSynchronize(
        MetadataProviderConfiguration $configuration,
        EntityManagerInterface $entityManager
    ): void {
        $oid = spl_object_id($configuration);
        $metron = $this->encryption->decrypt($configuration->getMetronPassword());
        $comicVine = $this->encryption->decrypt($configuration->getComicVineApiKey());

        $configuration->setMetronPassword($metron);
        $configuration->setComicVineApiKey($comicVine);
        $this->logicalSnapshots[$oid] = ['metron' => $metron, 'comicVine' => $comicVine];

        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty($oid, 'metronPassword', $metron);
        $unitOfWork->setOriginalEntityProperty($oid, 'comicVineApiKey', $comicVine);
    }
}
''')

# ---------------------------------------------------------------------------
# Email verification: dedicated token table is the only token model.
# ---------------------------------------------------------------------------
write('backend/src/Service/EmailVerificationResult.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class EmailVerificationResult
{
    public const INVALID = 'invalid';
    public const ALREADY_VERIFIED = 'already_verified';
    public const VERIFIED = 'verified';

    public function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
    ) {
    }
}
''')

write('backend/src/Service/EmailVerificationService.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerificationTokenRepository $tokens,
    ) {
    }

    public function issue(User $user): string
    {
        $this->removeTokensFor($user);
        $token = new EmailVerificationToken($user);
        $plainToken = $token->getPlainToken();
        if ($plainToken === null) {
            throw new \RuntimeException('Email verification token was not generated.');
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plainToken;
    }

    public function verify(string $plainToken): EmailVerificationResult
    {
        $token = $this->tokens->findValidToken($plainToken);
        if ($token === null || ($user = $token->getUser()) === null) {
            return new EmailVerificationResult(EmailVerificationResult::INVALID);
        }

        if ($user->isEmailVerified()) {
            $this->removeTokensFor($user);
            $this->entityManager->flush();

            return new EmailVerificationResult(EmailVerificationResult::ALREADY_VERIFIED, $user);
        }

        $user->setIsEmailVerified(true);
        $this->removeTokensFor($user);
        $this->entityManager->flush();

        return new EmailVerificationResult(EmailVerificationResult::VERIFIED, $user);
    }

    private function removeTokensFor(User $user): void
    {
        foreach ($this->tokens->findBy(['user' => $user]) as $token) {
            $this->entityManager->remove($token);
        }
    }
}
''')

write('backend/src/Service/EmailVerificationMailer.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class EmailVerificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {
    }

    public function send(User $user, string $plainToken): void
    {
        $verificationUrl = $this->urlGenerator->generate(
            'app_email_verification_verify',
            ['token' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to((string) $user->getEmail())
            ->subject('Verify your email address')
            ->html($this->twig->render('emails/email_verification.html.twig', [
                'user' => $user,
                'verificationUrl' => $verificationUrl,
            ]));

        $this->mailer->send($email);
    }
}
''')

write('backend/src/Controller/RegistrationController.php', r'''<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\JsonRequestDecoder;
use App\Service\ApiRateLimiter;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationService;
use App\Service\PasswordValidator;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        PasswordValidator $passwordValidator,
        ApiRateLimiter $rateLimiter,
        EmailVerificationService $verification,
        EmailVerificationMailer $verificationMailer,
        SecurityAuditLogger $securityLogger
    ): Response {
        if ($this->getUser()) {
            return new JsonResponse(['message' => 'User already authenticated.'], Response::HTTP_FORBIDDEN);
        }

        if ($rateLimitResponse = $rateLimiter->limit($request, 'register')) {
            return $rateLimitResponse;
        }

        $data = JsonRequestDecoder::decode($request);
        $password = $data['password'] ?? $data['plainPassword'] ?? null;

        $constraints = new Assert\Collection([
            'email' => [new Assert\NotBlank(['message' => 'Email is required']), new Assert\Email(['message' => 'Invalid email format'])],
            'password' => [new Assert\NotBlank(['message' => 'Password is required']), new Assert\Type('string')],
            'plainPassword' => new Assert\Optional(new Assert\Type('string')),
            'name' => new Assert\Optional(new Assert\Type('string')),
            'agreeTerms' => new Assert\Required([
                new Assert\NotNull(['message' => 'Terms acceptance is required']),
                new Assert\IsTrue(['message' => 'You must agree to the Terms of Service']),
            ]),
        ]);

        $violations = $validator->validate($data, $constraints);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $passwordErrors = $passwordValidator->validate((string) $password);
        if ($passwordErrors !== []) {
            return new JsonResponse([
                'message' => 'Password does not meet policy requirements.',
                'errors' => ['password' => $passwordErrors],
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']])) {
            return new JsonResponse(['message' => 'User with this email already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail((string) $data['email']);
        if (isset($data['name']) && is_string($data['name']) && trim($data['name']) !== '') {
            $user->setName(trim($data['name']));
        }
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($userPasswordHasher->hashPassword($user, (string) $password));

        $entityManager->persist($user);
        $plainToken = $verification->issue($user);
        $verificationMailer->send($user, $plainToken);

        $securityLogger->audit(SecurityAuditLogger::USER_REGISTERED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'created_by_admin' => false,
        ]);

        return new JsonResponse([
            'message' => 'User registered successfully. Please check your email to verify your account.',
            'requiresVerification' => true,
        ], Response::HTTP_CREATED);
    }
}
''')

write('backend/src/Controller/EmailVerificationController.php', r'''<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequestDecoder;
use App\Repository\UserRepository;
use App\Service\ApiRateLimiter;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationResult;
use App\Service\EmailVerificationService;
use App\Service\PublicUrl;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/email-verification')]
final class EmailVerificationController extends AbstractController
{
    private const RESEND_MESSAGE = 'If your email exists and still needs verification, a verification email has been sent.';

    public function __construct(private readonly PublicUrl $publicUrl)
    {
    }

    #[Route('/verify/{token}', name: 'app_email_verification_verify', methods: ['GET'], requirements: ['token' => '[A-Fa-f0-9]{64}'])]
    public function verify(string $token, EmailVerificationService $verification, SecurityAuditLogger $securityLogger): Response
    {
        $result = $verification->verify($token);
        if ($result->status === EmailVerificationResult::INVALID || $result->user === null) {
            $securityLogger->suspicious(
                SecurityAuditLogger::AUTHENTICATION_FAILED,
                'verify:' . $securityLogger->clientIp(),
                ['reason' => 'invalid_email_verification_token'],
                $securityLogger->failedLoginThreshold()
            );

            return $this->redirectToFrontend('verification-failed', 'Invalid or expired verification token');
        }

        if ($result->status === EmailVerificationResult::ALREADY_VERIFIED) {
            return $this->redirectToFrontend('verification-success', 'Your email has already been verified');
        }

        $securityLogger->audit(SecurityAuditLogger::USER_EMAIL_VERIFIED, [
            'actor_user_id' => $result->user->getId(),
            'target_user_id' => $result->user->getId(),
            'target_type' => 'user',
            'verified_by_admin' => false,
        ]);

        return $this->redirectToFrontend('verification-success', 'Your email has been verified successfully');
    }

    #[Route('/resend', name: 'app_email_verification_resend', methods: ['POST'])]
    public function resendVerificationEmail(
        Request $request,
        UserRepository $users,
        ApiRateLimiter $rateLimiter,
        EmailVerificationService $verification,
        EmailVerificationMailer $verificationMailer,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        if ($rateLimitResponse = $rateLimiter->limit($request, 'verification_resend')) {
            return $rateLimitResponse;
        }

        $data = JsonRequestDecoder::decode($request);
        $email = $data['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return $this->json(['message' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $users->findOneBy(['email' => trim($email)]);
        if ($user !== null && !$user->isEmailVerified()) {
            $plainToken = $verification->issue($user);
            $verificationMailer->send($user, $plainToken);
            $securityLogger->audit(SecurityAuditLogger::USER_VERIFICATION_RESENT, [
                'actor_user_id' => $user->getId(),
                'target_user_id' => $user->getId(),
                'target_type' => 'user',
            ]);
        }

        return $this->json(['message' => self::RESEND_MESSAGE], Response::HTTP_OK);
    }

    private function redirectToFrontend(string $status, string $message): Response
    {
        return $this->redirect(sprintf(
            '%s?status=%s&message=%s',
            $this->publicUrl->to('/email-verification'),
            urlencode($status),
            urlencode($message)
        ));
    }
}
''')

ensure_strict('backend/src/Entity/User.php')
sub(
    'backend/src/Entity/User.php',
    r'''\n    /\*\*\n     \* Email verification token\n     \*/\n    #\[ORM\\Column\(length: 255, nullable: true\)\]\n    private \?string \$emailVerificationToken = null;\n    \n    /\*\*\n     \* When the email verification token expires\n     \*/\n    #\[ORM\\Column\(nullable: true\)\]\n    private \?\\DateTimeImmutable \$emailVerificationTokenExpiresAt = null;\n''',
    '\n',
    flags=re.S,
)
sub(
    'backend/src/Entity/User.php',
    r'''\n    public function getEmailVerificationToken\(\): \?string.*?\n}\s*$''',
    '\n}\n',
    flags=re.S,
)
ensure_strict('backend/src/Entity/EmailVerificationToken.php')
ensure_strict('backend/src/Repository/EmailVerificationTokenRepository.php')

write('backend/migrations/Version20260815111500.php', r'''<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidate email verification onto the dedicated token table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO email_verification_token (user_id, token, expires_at, created_at)
            SELECT id, email_verification_token, email_verification_token_expires_at, created_at
            FROM `user`
            WHERE email_verification_token IS NOT NULL
              AND email_verification_token_expires_at IS NOT NULL
        SQL);
        $this->addSql('ALTER TABLE `user` DROP email_verification_token, DROP email_verification_token_expires_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD email_verification_token VARCHAR(255) DEFAULT NULL, ADD email_verification_token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            UPDATE `user` u
            LEFT JOIN email_verification_token t ON t.id = (
                SELECT t2.id FROM email_verification_token t2
                WHERE t2.user_id = u.id
                ORDER BY t2.created_at DESC, t2.id DESC
                LIMIT 1
            )
            SET u.email_verification_token = t.token,
                u.email_verification_token_expires_at = t.expires_at
        SQL);
    }
}
''')

# ---------------------------------------------------------------------------
# Consistent API JSON parsing and error semantics.
# ---------------------------------------------------------------------------
write('backend/src/Http/JsonRequestDecoder.php', r'''<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class JsonRequestDecoder
{
    /** @return array<array-key, mixed> */
    public static function decode(Request $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        if (!is_array($data)) {
            throw new BadRequestHttpException('JSON payload must be an object or array.');
        }

        return $data;
    }
}
''')

write('backend/src/EventSubscriber/ApiExceptionSubscriber.php', r'''<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 20]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof BadRequestHttpException) {
            $event->setResponse(new JsonResponse(['message' => $exception->getMessage()], $exception->getStatusCode()));
        }
    }
}
''')

for path in Path('backend/src/Controller').glob('*.php'):
    text = path.read_text()
    changed = False
    if 'json_decode($request->getContent(), true)' in text:
        text = text.replace('json_decode($request->getContent(), true)', r'\App\Http\JsonRequestDecoder::decode($request)')
        changed = True
    if "['error' =>" in text:
        text = text.replace("['error' =>", "['message' =>")
        changed = True
    if changed and 'declare(strict_types=1);' not in text:
        text = text.replace('<?php\n', '<?php\n\ndeclare(strict_types=1);\n', 1)
    if changed:
        path.write_text(text)

# ---------------------------------------------------------------------------
# One JSON logout implementation and exact public/CSRF routes.
# ---------------------------------------------------------------------------
write('backend/src/Controller/AuthController.php', r'''<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[Route('/api', name: 'api_')]
final class AuthController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['message' => 'Login failed. Check your credentials.'], Response::HTTP_UNAUTHORIZED);
    }

    #[Route('/login_check', name: 'login_check', methods: ['GET'])]
    public function loginCheck(): JsonResponse
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $this->json([
                'user' => ['email' => $user->getUserIdentifier(), 'roles' => $user->getRoles()],
                'message' => 'User is authenticated',
            ]);
        }

        return $this->json(['message' => 'User is not authenticated'], Response::HTTP_UNAUTHORIZED);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack,
        EventDispatcherInterface $eventDispatcher,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'No user to logout']);
        }

        $securityLogger->audit(SecurityAuditLogger::USER_LOGGED_OUT, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
        ]);

        $request = $requestStack->getCurrentRequest();
        $token = $tokenStorage->getToken();
        if ($request !== null && $token !== null) {
            $eventDispatcher->dispatch(new LogoutEvent($request, $token));
        }
        $tokenStorage->setToken(null);

        return $this->json(['message' => 'Logout successful']);
    }
}
''')
replace('frontend/src/hooks/use-auth.jsx', '/api/logout_user', '/api/logout')
for path in list(Path('backend/tests').rglob('*.php')) + list(Path('frontend/src').rglob('*')):
    if not path.is_file():
        continue
    try:
        text = path.read_text()
    except UnicodeDecodeError:
        continue
    if '/api/logout_user' in text:
        path.write_text(text.replace('/api/logout_user', '/api/logout'))

security = read('backend/config/packages/security.yaml')
security, removed = re.subn(
    r'''\n            logout:\n                path: /api/logout.*?\n(?:                #.*\n)*''',
    '\n',
    security,
    count=1,
)
if removed != 1:
    raise SystemExit('Could not remove duplicate firewall logout configuration')
security_replacements = {
    '- { path: ^/api/login$, roles: PUBLIC_ACCESS }': '- { path: ^/api/login$, methods: [POST], roles: PUBLIC_ACCESS }',
    '- { path: ^/api/register$, roles: PUBLIC_ACCESS }': '- { path: ^/api/register$, methods: [POST], roles: PUBLIC_ACCESS }',
    '- { path: ^/api/login_check$, roles: PUBLIC_ACCESS }': '- { path: ^/api/login_check$, methods: [GET], roles: PUBLIC_ACCESS }',
    '- { path: ^/api/forgot-password$, roles: PUBLIC_ACCESS }': '- { path: ^/api/forgot-password$, methods: [POST], roles: PUBLIC_ACCESS }',
    '- { path: ^/api/reset-password, roles: PUBLIC_ACCESS }': "- { path: '^/api/reset-password/validate/[A-Fa-f0-9]{64}$', methods: [GET], roles: PUBLIC_ACCESS }\n        - { path: '^/api/reset-password/reset/[A-Fa-f0-9]{64}$', methods: [POST], roles: PUBLIC_ACCESS }",
    '- { path: ^/api/email-verification/verify, roles: PUBLIC_ACCESS }': "- { path: '^/api/email-verification/verify/[A-Fa-f0-9]{64}$', methods: [GET], roles: PUBLIC_ACCESS }",
    '- { path: ^/api/email-verification/resend$, roles: PUBLIC_ACCESS }': '- { path: ^/api/email-verification/resend$, methods: [POST], roles: PUBLIC_ACCESS }',
    '- { path: ^/api/legal-config$, roles: PUBLIC_ACCESS }': '- { path: ^/api/legal-config$, methods: [GET], roles: PUBLIC_ACCESS }',
}
for old, new in security_replacements.items():
    if old not in security:
        raise SystemExit(f'Missing security rule: {old}')
    security = security.replace(old, new)
security = re.sub(r'\s+# (?:Changed from .*|Old provider|New provider|Key for the (?:username|password) in the JSON body)', '', security)
write('backend/config/packages/security.yaml', security)

write('backend/src/EventSubscriber/ApiCsrfSubscriber.php', r'''<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiCsrfSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ID = 'api';
    private const COOKIE_NAME = 'XSRF-TOKEN';
    private const HEADER_NAME = 'X-XSRF-TOKEN';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager, private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['validateToken', -10], KernelEvents::RESPONSE => ['setTokenCookie', 0]];
    }

    public function validateToken(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->shouldValidate($event->getRequest())) {
            return;
        }

        $submittedToken = (string) $event->getRequest()->headers->get(self::HEADER_NAME, '');
        if ($submittedToken === '' || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submittedToken))) {
            $event->setResponse(new JsonResponse(['message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN));
        }
    }

    public function setTokenCookie(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api') || !$this->security->getUser()) {
            return;
        }

        $token = (string) $this->csrfTokenManager->getToken(self::TOKEN_ID);
        $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE_NAME)
            ->withValue($token)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX));
    }

    private function shouldValidate(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api') || $request->isMethodSafe(false) || !$this->security->getUser()) {
            return false;
        }

        if (in_array($path, ['/api/login', '/api/register', '/api/forgot-password', '/api/email-verification/resend'], true)) {
            return false;
        }

        return preg_match('#^/api/reset-password/reset/[A-Fa-f0-9]{64}$#D', $path) !== 1;
    }
}
''')

# ---------------------------------------------------------------------------
# Use the existing Symfony RateLimiter everywhere.
# ---------------------------------------------------------------------------
write('backend/src/Service/ApiRateLimiter.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ApiRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RateLimiterFactory $registerLimiter,
        private readonly RateLimiterFactory $forgotPasswordLimiter,
        private readonly RateLimiterFactory $verificationResendLimiter,
        private readonly RateLimiterFactory $comicSearchLimiter,
        private readonly SecurityAuditLogger $auditLogger
    ) {
    }

    public function limit(Request $request, string $limiterName, ?string $key = null): ?JsonResponse
    {
        $factory = match ($limiterName) {
            'login' => $this->loginLimiter,
            'register' => $this->registerLimiter,
            'forgot_password' => $this->forgotPasswordLimiter,
            'verification_resend' => $this->verificationResendLimiter,
            'comic_search' => $this->comicSearchLimiter,
            default => throw new \InvalidArgumentException(sprintf('Unknown limiter "%s".', $limiterName)),
        };

        $clientKey = $key ?? $this->getClientKey($request);
        $limit = $factory->create($clientKey)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $this->auditLogger->suspicious(
            SecurityAuditLogger::RATE_LIMIT_TRIGGERED,
            sprintf('%s:%s', $limiterName, $clientKey),
            ['limiter' => $limiterName, 'path' => $request->getPathInfo()]
        );

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $response = new JsonResponse([
            'message' => 'Too many requests. Please try again later.',
            'retryAfter' => $retryAfter,
        ], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    private function getClientKey(Request $request): string
    {
        return $request->getClientIp() ?: 'unknown';
    }
}
''')

rate = read('backend/config/packages/rate_limiter.yaml')
marker = "        verification_resend:\n            policy: fixed_window\n            limit: 5\n            interval: '1 hour'\n"
if marker not in rate:
    raise SystemExit('verification_resend limiter block not found')
rate = rate.replace(marker, marker + "        comic_search:\n            policy: sliding_window\n            limit: 10\n            interval: '1 minute'\n", 1)
test_marker = "            verification_resend:\n                limit: 1000\n"
if test_marker not in rate:
    raise SystemExit('test verification limiter block not found')
rate = rate.replace(test_marker, test_marker + "            comic_search:\n                limit: 1000\n", 1)
write('backend/config/packages/rate_limiter.yaml', rate)

comic = read('backend/src/Controller/ComicController.php')
if 'use App\\Service\\ApiRateLimiter;' not in comic:
    comic = comic.replace('use App\\Service\\AdminAuditService;\n', 'use App\\Service\\AdminAuditService;\nuse App\\Service\\ApiRateLimiter;\n', 1)
comic, n = re.subn(
    r'''\n    /\*\*\n     \* Check if user has exceeded search rate limit.*?\n    }\n    \n    #\[Route\('', name: 'list', methods: \['GET'\]\)\]''',
    "\n    #[Route('', name: 'list', methods: ['GET'])]",
    comic,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit('Could not remove custom ComicController search limiter')
old_sig = 'public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse'
if old_sig not in comic:
    raise SystemExit('Comic list signature not found')
comic = comic.replace(old_sig, 'public function list(Request $request, EntityManagerInterface $entityManager, ApiRateLimiter $rateLimiter): JsonResponse', 1)
old_call = """            // Check rate limit
            $rateLimitResponse = $this->checkSearchRateLimit($request);
            if ($rateLimitResponse) {
                return $rateLimitResponse;
            }"""
if old_call not in comic:
    raise SystemExit('Comic search limiter call not found')
comic = comic.replace(old_call, """            $rateLimitResponse = $rateLimiter->limit($request, 'comic_search', 'user:' . $user->getId());
            if ($rateLimitResponse) {
                return $rateLimitResponse;
            }""", 1)
comic = comic.replace("    // Removed getPublicBaseUrlForUploads() method as it's no longer needed.\n\n", '')
write('backend/src/Controller/ComicController.php', comic)

# ---------------------------------------------------------------------------
# Atomic per-user storage quota admission.
# ---------------------------------------------------------------------------
write('backend/src/Service/StorageQuotaExceededException.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

final class StorageQuotaExceededException extends \RuntimeException
{
}
''')
write('backend/src/Service/StorageQuotaBusyException.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

final class StorageQuotaBusyException extends \RuntimeException
{
}
''')
write('backend/src/Service/StorageQuotaService.php', r'''<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class StorageQuotaService
{
    private const LOCK_TTL_SECONDS = 300.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly int $uploadUserQuotaBytes,
    ) {
    }

    public function acquireAdmission(User $user, int $additionalBytes, bool $blocking = true): LockInterface
    {
        if ($additionalBytes < 0 || $user->getId() === null) {
            throw new \InvalidArgumentException('Storage admission requires a persisted user and a non-negative size.');
        }

        $lock = $this->lockFactory->createLock($this->lockResource($user), self::LOCK_TTL_SECONDS, true);
        if (!$lock->acquire($blocking)) {
            throw new StorageQuotaBusyException('Another storage operation is already in progress for this account.');
        }

        if ($this->wouldExceedQuota($user, $additionalBytes)) {
            $lock->release();
            throw new StorageQuotaExceededException('User storage quota exceeded.');
        }

        return $lock;
    }

    public function wouldExceedQuota(User $user, int $additionalBytes): bool
    {
        return $this->getUserStorageBytes($user) + $additionalBytes > $this->uploadUserQuotaBytes;
    }

    public function getUserStorageBytes(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(c.fileSize), 0)')
            ->from(Comic::class, 'c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function lockResource(User $user): string
    {
        if ($user->getId() === null) {
            throw new \InvalidArgumentException('Storage quota locks require a persisted user.');
        }

        return 'storage-quota:user:' . $user->getId();
    }
}
''')

ensure_strict('backend/src/Service/ComicService.php')
service = read('backend/src/Service/ComicService.php')
old_ctor = """        private readonly LoggerInterface $logger,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadUserQuotaBytes,"""
new_ctor = """        private readonly LoggerInterface $logger,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly StorageQuotaService $storageQuota,
        private readonly int $uploadMaxTotalBytes,"""
if old_ctor not in service:
    raise SystemExit('ComicService constructor quota fields not found')
service = service.replace(old_ctor, new_ctor, 1)
old_check = """        if ($this->wouldExceedQuota($user, $incomingSize)) {
            throw new \\RuntimeException('User storage quota exceeded.');
        }"""
if old_check not in service:
    raise SystemExit('ComicService quota check not found')
service = service.replace(old_check, '        $quotaLock = $this->storageQuota->acquireAdmission($user, $incomingSize);', 1)
success = """            $connection->commit();

            return $comic;"""
if success not in service:
    raise SystemExit('ComicService success return not found')
service = service.replace(success, """            $connection->commit();
            $quotaLock->release();

            return $comic;""", 1)
service, n = re.subn(
    r'''    public function wouldExceedQuota\(User \$user, int \$additionalBytes\): bool\n    \{.*?\n    \}\n\n    public function getUserStorageBytes''',
    """    public function wouldExceedQuota(User $user, int $additionalBytes): bool
    {
        return $this->storageQuota->wouldExceedQuota($user, $additionalBytes);
    }

    public function getUserStorageBytes""",
    service,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit('ComicService wouldExceedQuota method not found')
service, n = re.subn(
    r'''    public function getUserStorageBytes\(User \$user\): int\n    \{.*?\n    \}\n\n    /\*\*''',
    """    public function getUserStorageBytes(User $user): int
    {
        return $this->storageQuota->getUserStorageBytes($user);
    }

    /**""",
    service,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit('ComicService getUserStorageBytes method not found')
write('backend/src/Service/ComicService.php', service)

comic = read('backend/src/Controller/ComicController.php')
quota_pattern = r'''        \$currentUsage = \(int\) \$entityManager->createQueryBuilder\(\).*?        if \(\$currentUsage \+ \$totalSize > \$this->uploadUserQuotaBytes\) \{\n            return \$this->json\(\['message' => 'User storage quota exceeded'\], Response::HTTP_REQUEST_ENTITY_TOO_LARGE\);\n        \}\n'''
comic, n = re.subn(
    quota_pattern,
    """        // Friendly preflight; ComicService repeats the authoritative check
        // while holding the per-user storage lock.
        if ($comicService->wouldExceedQuota($user, $totalSize)) {
            return $this->json(['message' => 'User storage quota exceeded'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
""",
    comic,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit('Chunk assembly quota block not found')
# The controller no longer owns quota policy directly.
comic = comic.replace('        private readonly int $uploadUserQuotaBytes,\n', '')
write('backend/src/Controller/ComicController.php', comic)

# ---------------------------------------------------------------------------
# Service wiring.
# ---------------------------------------------------------------------------
services = read('backend/config/services.yaml')
api_marker = """            $forgotPasswordLimiter: '@limiter.forgot_password'
            $verificationResendLimiter: '@limiter.verification_resend'"""
if api_marker not in services:
    raise SystemExit('ApiRateLimiter service args marker not found')
services = services.replace(api_marker, api_marker + "\n            $comicSearchLimiter: '@limiter.comic_search'", 1)
reset_marker = """    App\\Service\\ResetPasswordService:
        arguments:
            $mailerFromAddress: '%mailer_from_address%'
            $mailerFromName: '%mailer_from_name%'
"""
if reset_marker not in services:
    raise SystemExit('ResetPasswordService wiring marker not found')
services = services.replace(reset_marker, reset_marker + "\n    App\\Service\\EmailVerificationMailer:\n        arguments:\n            $mailerFromAddress: '%mailer_from_address%'\n            $mailerFromName: '%mailer_from_name%'\n", 1)
comic_wiring = """    App\\Service\\ComicService:
        arguments:
            $comicsDirectory: '%comics_directory%'
            $uploadMaxTotalBytes: '%upload_max_total_bytes%'
            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'
"""
if comic_wiring not in services:
    raise SystemExit('ComicService wiring marker not found')
services = services.replace(
    comic_wiring,
    """    App\\Service\\StorageQuotaService:
        arguments:
            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'

    App\\Service\\ComicService:
        arguments:
            $comicsDirectory: '%comics_directory%'
            $uploadMaxTotalBytes: '%upload_max_total_bytes%'
""",
    1,
)
services = services.replace("            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'\n            \n    App\\Service\\DropboxImportService:", "            \n    App\\Service\\DropboxImportService:")
write('backend/config/services.yaml', services)

# ---------------------------------------------------------------------------
# Regression tests.
# ---------------------------------------------------------------------------
write('backend/tests/Unit/Service/AppDataEncryptionServiceTest.php', r'''<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppDataEncryptionService;
use PHPUnit\Framework\TestCase;

final class AppDataEncryptionServiceTest extends TestCase
{
    private AppDataEncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new AppDataEncryptionService(str_repeat('ab', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testEncryptsAndDecryptsSecrets(): void
    {
        $encrypted = $this->encryption->encrypt('dropbox-refresh-token');
        self::assertNotSame('dropbox-refresh-token', $encrypted);
        self::assertTrue($this->encryption->isEncrypted($encrypted));
        self::assertSame('dropbox-refresh-token', $this->encryption->decrypt($encrypted));
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        self::assertNotSame($this->encryption->encrypt('same-secret'), $this->encryption->encrypt('same-secret'));
    }

    public function testAlreadyEncryptedValueIsNotEncryptedAgain(): void
    {
        $encrypted = $this->encryption->encrypt('token');
        self::assertSame($encrypted, $this->encryption->encrypt($encrypted));
    }

    /** @dataProvider passthroughValues */
    public function testEmptyValuesPassThrough(?string $value): void
    {
        self::assertSame($value, $this->encryption->encrypt($value));
        self::assertSame($value, $this->encryption->decrypt($value));
    }

    public function passthroughValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function testLegacyPlaintextCanStillBeRead(): void
    {
        self::assertSame('legacy-token', $this->encryption->decrypt('legacy-token'));
    }

    public function testRejectsMalformedCiphertext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt('enc:v1:not-valid-base64');
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $encrypted = (string) $this->encryption->encrypt('sensitive');
        $tampered = substr($encrypted, 0, -2) . 'AA';
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt($tampered);
    }

    public function testAcceptsHexAndBase64EncodedKeys(): void
    {
        $rawKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        foreach ([bin2hex($rawKey), base64_encode($rawKey)] as $encodedKey) {
            $service = new AppDataEncryptionService($encodedKey);
            self::assertSame('value', $service->decrypt($service->encrypt('value')));
        }
    }

    /** @dataProvider invalidKeys */
    public function testRejectsArbitraryOrMalformedKeys(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppDataEncryptionService($key);
    }

    public function invalidKeys(): iterable
    {
        yield 'human passphrase' => ['ChangeMeInEnvLocal'];
        yield 'short hex' => ['deadbeef'];
        yield 'wrong base64 length' => [base64_encode('too-short')];
        yield 'empty' => [''];
    }
}
''')

write('backend/tests/Functional/Service/SecretPersistenceIntegrityTest.php', r'''<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\User;
use App\Service\AppDataEncryptionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class SecretPersistenceIntegrityTest extends AbstractApiTestCase
{
    public function testStaleUnrelatedUserFlushCannotOverwriteNewerDropboxCredential(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();
        $user->setDropboxRefreshToken('old-refresh');
        $entityManager->flush();
        $id = $user->getId();

        $entityManager->clear();
        $stale = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $stale);
        self::assertSame('old-refresh', $stale->getDropboxRefreshToken());

        $entityManager->getConnection()->executeStatement(
            'UPDATE `user` SET dropbox_refresh_token = :token WHERE id = :id',
            ['token' => $encryption->encrypt('new-refresh'), 'id' => $id]
        );
        $stale->setName('Unrelated profile edit');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT dropbox_refresh_token FROM `user` WHERE id = :id', ['id' => $id]);
        self::assertSame('new-refresh', $encryption->decrypt(is_string($stored) ? $stored : null));
    }

    public function testStaleUnrelatedProviderFlushCannotOverwriteNewerCredential(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $configuration = new MetadataProviderConfiguration();
        $configuration->setMetronUsername('before');
        $configuration->setMetronPassword('old-password');
        $entityManager->persist($configuration);
        $entityManager->flush();

        $entityManager->clear();
        $stale = $entityManager->find(MetadataProviderConfiguration::class, 1);
        self::assertInstanceOf(MetadataProviderConfiguration::class, $stale);
        self::assertSame('old-password', $stale->getMetronPassword());

        $entityManager->getConnection()->executeStatement(
            'UPDATE metadata_provider_configuration SET metron_password = :password WHERE id = 1',
            ['password' => $encryption->encrypt('new-password')]
        );
        $stale->setMetronUsername('after');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT metron_password FROM metadata_provider_configuration WHERE id = 1');
        self::assertSame('new-password', $encryption->decrypt(is_string($stored) ? $stored : null));
    }

    public function testIntentionalCredentialChangeIsEncryptedAndReadable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();
        $id = $user->getId();
        $entityManager->clear();

        $managed = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $managed);
        $managed->setDropboxRefreshToken('rotated-refresh');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT dropbox_refresh_token FROM `user` WHERE id = :id', ['id' => $id]);
        self::assertIsString($stored);
        self::assertTrue($encryption->isEncrypted($stored));
        self::assertSame('rotated-refresh', $encryption->decrypt($stored));
    }
}
''')

write('backend/tests/Functional/Controller/EmailVerificationPrivacyTest.php', r'''<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class EmailVerificationPrivacyTest extends AbstractApiTestCase
{
    public function testResendDoesNotRevealAccountOrVerificationState(): void
    {
        UserFactory::createOne(['email' => 'verified@example.test', 'isEmailVerified' => true]);
        UserFactory::createOne(['email' => 'pending@example.test', 'isEmailVerified' => false]);

        $responses = [];
        foreach (['missing@example.test', 'verified@example.test', 'pending@example.test'] as $email) {
            $responses[] = $this->postJson('/api/email-verification/resend', ['email' => $email]);
            self::assertResponseIsSuccessful();
        }

        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[1], $responses[2]);
    }
}
''')

write('backend/tests/Functional/Service/StorageQuotaServiceTest.php', r'''<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\StorageQuotaBusyException;
use App\Service\StorageQuotaService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Lock\LockFactory;

final class StorageQuotaServiceTest extends AbstractApiTestCase
{
    public function testSecondAdmissionForSameUserCannotRaceTheFirst(): void
    {
        $user = UserFactory::createOne()->object();
        $quota = static::getContainer()->get(StorageQuotaService::class);
        $lockFactory = static::getContainer()->get(LockFactory::class);
        $first = $lockFactory->createLock($quota->lockResource($user), 300.0, true);
        self::assertTrue($first->acquire());

        try {
            $this->expectException(StorageQuotaBusyException::class);
            $quota->acquireAdmission($user, 1, false);
        } finally {
            $first->release();
        }
    }
}
''')

write('backend/tests/Functional/Security/PasswordSessionInvalidationTest.php', r'''<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ResetPasswordToken;
use App\Service\ResetPasswordService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PasswordSessionInvalidationTest extends AbstractApiTestCase
{
    public function testSessionCreatedBeforePasswordResetIsRejectedAfterward(): void
    {
        $user = UserFactory::createOne()->object();
        $this->loginAs($user);
        $cookies = $this->browser()->getCookieJar()->all();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $plainToken = bin2hex(random_bytes(32));
        $token = new ResetPasswordToken();
        $token->setUser($user);
        $token->setToken(hash('sha256', $plainToken));
        $token->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $token->setIsUsed(false);
        $entityManager->persist($token);
        $entityManager->flush();

        static::getContainer()->get(ResetPasswordService::class)->resetPassword($plainToken, 'ChangedPassword!123456');

        static::ensureKernelShutdown();
        $freshClient = static::createClient();
        foreach ($cookies as $cookie) {
            $freshClient->getCookieJar()->set($cookie);
        }
        $freshClient->request('GET', '/api/me', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }
}
''')

# ---------------------------------------------------------------------------
# Runtime/dependency cleanup and quality gates.
# ---------------------------------------------------------------------------
composer_path = Path('backend/composer.json')
composer = json.loads(composer_path.read_text())
composer['require']['php'] = '>=8.2 <8.5'
composer.get('scripts', {}).get('auto-scripts', {}).pop('importmap:install', None)
composer.setdefault('scripts', {})['analyse'] = 'phpstan analyse --no-progress'
composer.setdefault('scripts', {})['cs:check'] = 'php-cs-fixer fix --dry-run --diff --using-cache=no'
composer_path.write_text(json.dumps(composer, indent=4, ensure_ascii=False) + '\n')

for path in ['backend/config/packages/asset_mapper.yaml', 'backend/importmap.php']:
    Path(path).unlink(missing_ok=True)
if Path('backend/assets').exists():
    shutil.rmtree('backend/assets')

bundles = read('backend/config/bundles.php')
bundles = bundles.replace("    Symfony\\UX\\StimulusBundle\\StimulusBundle::class => ['all' => true],\n", '')
bundles = bundles.replace("    Symfony\\UX\\Turbo\\TurboBundle::class => ['all' => true],\n", '')
write('backend/config/bundles.php', bundles)

Path('frontend/bun.lockb').unlink(missing_ok=True)
package = read('frontend/package.json').replace('"lint": "eslint ."', '"lint": "eslint . --max-warnings=0"')
write('frontend/package.json', package)
write('frontend/eslint.config.js', '''import js from "@eslint/js";\nimport globals from "globals";\nimport react from "eslint-plugin-react";\nimport reactHooks from "eslint-plugin-react-hooks";\nimport reactRefresh from "eslint-plugin-react-refresh";\n\nexport default [\n  { ignores: ["dist"] },\n  js.configs.recommended,\n  {\n    files: ["**/*.{js,jsx,mjs}"],\n    languageOptions: {\n      ecmaVersion: 2020,\n      sourceType: "module",\n      globals: { ...globals.browser, ...globals.node },\n      parserOptions: { ecmaFeatures: { jsx: true } },\n    },\n    plugins: { react, "react-hooks": reactHooks, "react-refresh": reactRefresh },\n    rules: {\n      "react/jsx-uses-vars": "error",\n      "react/jsx-uses-react": "off",\n      "no-unused-vars": "warn",\n      "no-useless-catch": "warn",\n      "no-useless-escape": "warn",\n      ...reactHooks.configs.recommended.rules,\n      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],\n    },\n  },\n  {\n    files: ["src/components/ui/**/*.{js,jsx}", "src/components/ThemeProvider.jsx", "src/hooks/**/*.{js,jsx}"],\n    rules: { "react-refresh/only-export-components": "off" },\n  },\n];\n''')

form = Path('backend/src/Form/RegistrationFormType.php')
if form.exists():
    form.write_text(re.sub(r'\s*//\s*(?:Changed from|Removed:).*\n', '\n', form.read_text()))

write('backend/phpstan.neon.dist', '''includes:\n    - phpstan-baseline.neon\nparameters:\n    level: 6\n    paths:\n        - src\n    treatPhpDocTypesAsCertain: false\n''')
write('backend/.php-cs-fixer.dist.php', r'''<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'encoding' => true,
        'full_opening_tag' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);
''')

write('docs/application-data-key.md', '''# Application data encryption key\n\n`APP_DATA_KEY` protects Dropbox tokens and metadata-provider credentials at rest. It is a deployment secret, not a passphrase.\n\nGenerate exactly 32 random bytes, encoded as Base64 or 64 hexadecimal characters:\n\n```bash\nopenssl rand -base64 32\n# or\nopenssl rand -hex 32\n```\n\nArbitrary strings and placeholders are rejected. Keep the production key outside the repository and back it up separately from the database. A database backup without this key cannot recover encrypted integration credentials.\n\nDo not casually replace the key: existing ciphertext is authenticated with the old key. Rotation requires an explicit decrypt-with-old / encrypt-with-new migration before the old key is retired. The development and test keys committed in this repository are intentionally public and must never be reused in production.\n''')
write('docs/development-tooling.md', '''# Development tooling\n\n## Frontend\n\nnpm is the supported package manager. `frontend/package-lock.json` is the single lockfile used locally, in Docker and in CI.\n\n```bash\ncd frontend\nnpm ci\nnpm run lint\nnpm run test\nnpm run build\n```\n\nDo not commit a Bun lockfile unless package-manager policy deliberately changes.\n\n## Backend quality gates\n\n```bash\ncd backend\ncomposer analyse\ncomposer cs:check\n```\n\nPHPStan uses a baseline for pre-existing findings so new code cannot silently increase static-analysis debt. Reduce the baseline opportunistically when touching existing code.\n''')

write('.github/workflows/security-audit.yml', '''name: Scheduled Dependency Audit\n\non:\n  schedule:\n    - cron: '17 4 * * 1'\n  workflow_dispatch:\n\npermissions:\n  contents: read\n\njobs:\n  frontend:\n    runs-on: ubuntu-latest\n    defaults:\n      run:\n        working-directory: frontend\n    steps:\n      - uses: actions/checkout@v7\n      - uses: actions/setup-node@v7\n        with:\n          node-version: '22'\n          cache: npm\n          cache-dependency-path: frontend/package-lock.json\n      - run: npm ci --ignore-scripts --no-audit --no-fund\n      - run: npm run audit:production\n\n  backend:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v7\n      - run: docker run --rm -v "$PWD/backend:/app" -w /app composer:2 audit --locked --no-dev\n''')
write('.github/dependabot.yml', '''version: 2\nupdates:\n  - package-ecosystem: npm\n    directory: /frontend\n    schedule:\n      interval: weekly\n    open-pull-requests-limit: 5\n\n  - package-ecosystem: composer\n    directory: /backend\n    schedule:\n      interval: weekly\n    open-pull-requests-limit: 5\n''')

for path in [
    'backend/src/Service/ApiRateLimiter.php',
    'backend/src/Service/ComicService.php',
    'backend/src/Controller/ComicController.php',
    'backend/src/Controller/ResetPasswordController.php',
]:
    ensure_strict(path)

# Remove obsolete scalar wiring left on ComicController now that quota policy is
# delegated to StorageQuotaService.
services = read('backend/config/services.yaml')
services = services.replace("            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'\n", '', 1 if services.count("            $uploadUserQuotaBytes: '%upload_user_quota_bytes%'\n") > 1 else 0)
write('backend/config/services.yaml', services)

# Add quality gates to the existing backend validation job.
workflow = read('.github/workflows/build-frontend.yml')
marker = "      - name: Run backend tests\n        run: docker compose exec -T php php bin/phpunit\n"
if marker not in workflow:
    raise SystemExit('Backend test marker not found in CI workflow')
workflow = workflow.replace(
    marker,
    "      - name: Static analysis and code style\n        run: |\n          docker compose exec -T php composer analyse\n          docker compose exec -T php composer cs:check\n\n" + marker,
    1,
)
write('.github/workflows/build-frontend.yml', workflow)

# Defensive checks: the consolidation should leave no production use of the old
# verification fields/routes.
for needle in ['generateEmailVerificationToken(', 'getEmailVerificationToken()', 'isEmailVerificationTokenExpired()']:
    hits = []
    for path in Path('backend/src').rglob('*.php'):
        if needle in path.read_text():
            hits.append(str(path))
    if hits:
        raise SystemExit(f'Legacy verification API still referenced for {needle}: {hits}')
