<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Test the email verification system
 * 
 * This command helps test the email verification system by:
 * 1. Creating a test user (if email doesn't exist)
 * 2. Generating a verification token
 * 3. Sending a verification email
 * 4. Displaying the verification URL
 * 
 * Usage:
 *   # Via Docker:
 *   docker-compose exec php bin/console app:test-email-verification test@example.com
 *   
 *   # Local install:
 *   php bin/console app:test-email-verification test@example.com
 */
#[AsCommand(
    name: 'app:test-email-verification',
    description: 'Test the email verification system',
)]
class TestEmailVerificationCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private MailerInterface $mailer;
    private UrlGeneratorInterface $urlGenerator;
    private Environment $twig;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        Environment $twig
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->twig = $twig;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to test verification')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        $io->title('Email Verification Test');
        $io->section('Testing email verification for: ' . $email);

        try {
            // Check if user exists
            $user = $this->userRepository->findOneBy(['email' => $email]);
            
            if (!$user) {
                $io->note('User does not exist. Creating a test user...');
                
                $user = new User();
                $user->setEmail($email);
                $user->setName('Test User');
                $user->setPassword('$2y$13$hMmMQVwloXHjhKs.EuiGJOsWQR0eBGGE/rYFcUmFmPQhO9VLWvLK6'); // "password"
                $user->setRoles(['ROLE_USER']);
                
                // Check if the isEmailVerified method exists before calling it
                if (method_exists($user, 'setIsEmailVerified')) {
                    $user->setIsEmailVerified(false);
                } else {
                    $io->warning('Email verification fields not found in User entity. Did you run the migration?');
                    $io->note('Continuing without setting verification status...');
                }
                
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                
                $io->success('Test user created with email: ' . $email);
            } else {
                $io->note('User already exists. Resetting verification status...');
                
                // Check if the isEmailVerified method exists before calling it
                if (method_exists($user, 'setIsEmailVerified')) {
                    $user->setIsEmailVerified(false);
                    $this->entityManager->flush();
                } else {
                    $io->warning('Email verification fields not found in User entity. Did you run the migration?');
                    $io->note('Continuing without setting verification status...');
                }
            }
        } catch (\Exception $e) {
            $io->error('Error accessing user data: ' . $e->getMessage());
            $io->note('This may be due to missing database columns. Make sure you have run the migration:');
            $io->text('docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction');
            return Command::FAILURE;
        }

        try {
            // Generate verification token
            if (!method_exists($user, 'generateEmailVerificationToken')) {
                $io->error('The User entity does not have a generateEmailVerificationToken method.');
                $io->note('Make sure you have updated the User entity and run the migration:');
                $io->text('docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction');
                return Command::FAILURE;
            }
            
            $token = $user->generateEmailVerificationToken();
            $this->entityManager->flush();
            
            $io->success('Generated verification token: ' . $token);
            
            // Generate verification URL (backend API URL)
            $apiVerificationUrl = $this->urlGenerator->generate(
                'app_email_verification_verify',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
            
            $io->section('API Verification URL');
            $io->writeln($apiVerificationUrl);
            
            // Get the frontend URL from parameters for display purposes
            $frontendUrl = $this->getParameter('frontend_url');
            // Make sure the frontend URL doesn't have a trailing slash
            $frontendUrl = rtrim($frontendUrl, '/');
            
            $frontendVerificationUrl = sprintf('%s/email-verification?status=verification-pending&token=%s', 
                $frontendUrl, 
                $token
            );
            
            $io->section('Frontend Verification URL (for manual testing)');
            $io->writeln($frontendVerificationUrl);
            
            // Send verification email
            $io->section('Sending verification email...');
            
            // Get the frontend URL from parameters
            $frontendUrl = $this->getParameter('frontend_url');
            $io->note("Using frontend URL: {$frontendUrl}");
            
            // Get the mailer configuration
            $fromEmail = $this->getParameter('mailer_from_address') ?: 'noreply@comicreader.example.com';
            $fromName = $this->getParameter('mailer_from_name') ?: 'Comic Reader';
            
            $emailMessage = (new \Symfony\Component\Mime\Email())
                ->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName))
                ->to($user->getEmail())
                ->subject('Verify your email address')
                ->html(
                    $this->twig->render('emails/email_verification.html.twig', [
                        'user' => $user,
                        'verificationUrl' => $apiVerificationUrl
                    ])
                );

            $this->mailer->send($emailMessage);
            
            $io->success('Verification email sent! Check the Mailpit interface at http://localhost:8025');
        } catch (\Exception $e) {
            $io->error('Error generating verification token or sending email: ' . $e->getMessage());
            return Command::FAILURE;
        }
        
        // Display test instructions
        $io->section('Test Instructions');
        $io->writeln([
            '1. Check the email in Mailpit (http://localhost:8025)',
            '2. Click the verification link in the email',
            '3. You should be redirected to the frontend with a success message',
            '4. Try to log in with the verified email',
            '',
            'Test user credentials:',
            '- Email: ' . $user->getEmail(),
            '- Password: password',
            '',
            'To manually verify the email in the database:',
            'UPDATE user SET is_email_verified = 1 WHERE email = \'' . $user->getEmail() . '\';',
        ]);

        return Command::SUCCESS;
    }
}
