<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates a new admin user in the system.
 *
 * This command allows you to provision a user with administrative privileges (ROLE_ADMIN)
 * directly from the command line. It requires an email and a password for the new user.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:create-admin-user admin@example.com YourSecureP@ssw0rd
 *
 *    Replace `panel-page-flip_php` with the actual name of your PHP service container if different.
 *    Replace `admin@example.com` with the desired email for the admin user.
 *    Replace `YourSecureP@ssw0rd` with a strong password for the admin user.
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:create-admin-user anotheradmin@example.com AnotherP@ssw0rd123
 *
 *    Ensure your local environment is configured (e.g., .env file points to the correct database).
 *
 * Arguments:
 *   email:    (Required) The email address for the new admin user. Must be a valid email format.
 *   password: (Required) The plain text password for the new admin user. It will be hashed before storage.
 *             Minimum length requirements may apply (e.g., 6 characters as per current implementation).
 *
 * Important Considerations:
 * - Ensure the email provided is not already in use by another user.
 * - Choose a strong, unique password for admin accounts.
 * - This command directly interacts with the database. Ensure your database connection is correctly
 *   configured in your Symfony application's environment settings.
 */
#[AsCommand(
    name: 'app:create-admin-user',
    description: 'Creates a new admin user.',
)]
class CreateAdminUserCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private UserRepository $userRepository;
    private ValidatorInterface $validator;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        ValidatorInterface $validator
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->userRepository = $userRepository;
        $this->validator = $validator;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email of the admin user.')
            ->addArgument('password', InputArgument::REQUIRED, 'The plain password of the admin user.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $plainPassword = $input->getArgument('password');

        // Check if user already exists
        if ($this->userRepository->findOneBy(['email' => $email])) {
            $io->error(sprintf('User with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        
        // Validate basic User entity constraints (like email format)
        $errors = $this->validator->validate($user, null, ['Default']); // 'Default' group for now
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getPropertyPath() . ': ' . $error->getMessage();
            }
            $io->error(['User validation failed:', implode("\n", $errorMessages)]);
            return Command::FAILURE;
        }
        
        // Password validation (length, etc.) - could also be done via form constraints if we used a form
        if (strlen($plainPassword) < 6) { // Example basic check
            $io->error('Password must be at least 6 characters long.');
            return Command::FAILURE;
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']); // Admin also gets standard user role
        // Timestamps are handled by User entity constructor/PreUpdate

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Admin user "%s" created successfully.', $email));

        return Command::SUCCESS;
    }
}
