<?php

namespace App\Command;

use App\Entity\User;
use App\Service\PasswordValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:reset-user-password',
    description: 'Resets the password for an existing user',
)]
class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordValidator $passwordValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email of the user')
            ->addArgument('password', InputArgument::REQUIRED, 'The new password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $io->error(sprintf('User with email "%s" not found', $email));
            return Command::FAILURE;
        }

        $passwordErrors = $this->passwordValidator->validate($password);
        if ($passwordErrors !== []) {
            $io->error(array_merge(['Password does not meet policy requirements:'], $passwordErrors));
            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setIsEmailVerified(true);
        $this->entityManager->flush();

        $io->success(sprintf('Password reset for "%s"', $email));

        return Command::SUCCESS;
    }
}
