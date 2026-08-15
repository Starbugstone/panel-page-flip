<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationService;
use App\Service\PublicUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:test-email-verification',
    description: 'Test the email verification system',
)]
final class TestEmailVerificationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly EmailVerificationService $verification,
        private readonly EmailVerificationMailer $verificationMailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PublicUrl $publicUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address to test verification');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $io->title('Email Verification Test');

        try {
            if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
                $io->error('Refusing to modify an existing account. Use a dedicated email address that is not already registered.');

                return Command::FAILURE;
            }

            $user = new User();
            $user->setEmail($email);
            $user->setName('Test User');
            // bcrypt hash for the documented local-test password "password".
            $user->setPassword('$2y$13$hMmMQVwloXHjhKs.EuiGJOsWQR0eBGGE/rYFcUmFmPQhO9VLWvLK6');
            $user->setRoles(['ROLE_USER']);
            $user->setIsEmailVerified(false);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Exercise the same token lifecycle and mailer as production rather
            // than carrying a second test-only implementation of verification.
            $token = $this->verification->issue($user);
            $apiVerificationUrl = $this->urlGenerator->generate(
                'app_email_verification_verify',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            $this->verificationMailer->send($user, $token);
        } catch (\Throwable $exception) {
            $io->error('Email verification test failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Verification email sent.');
        $io->section('API verification URL');
        $io->writeln($apiVerificationUrl);
        $io->section('Frontend verification page');
        $io->writeln($this->publicUrl->to('/email-verification'));
        $io->writeln([
            '',
            'Test user credentials:',
            '- Email: '.$user->getEmail(),
            '- Password: password',
        ]);

        return Command::SUCCESS;
    }
}
