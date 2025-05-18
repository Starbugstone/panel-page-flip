<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-mail',
    description: 'Send a test email to verify mail configuration',
)]
class TestMailCommand extends Command
{
    private MailerInterface $mailer;
    private string $fromAddress;
    private string $fromName;

    public function __construct(MailerInterface $mailer, string $mailerFromAddress, string $mailerFromName)
    {
        $this->mailer = $mailer;
        $this->fromAddress = $mailerFromAddress;
        $this->fromName = $mailerFromName;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Email address to send the test email to')
            ->addOption('subject', null, InputOption::VALUE_OPTIONAL, 'Subject of the test email', 'Test Email from Comic Reader')
            ->addOption('body', null, InputOption::VALUE_OPTIONAL, 'Body of the test email', 'This is a test email from the Comic Reader application.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $toEmail = $input->getOption('to');
        $subject = $input->getOption('subject');
        $body = $input->getOption('body');

        if (!$toEmail) {
            $io->error('The --to option is required. Please provide an email address.');
            return Command::FAILURE;
        }

        $io->note(sprintf('Attempting to send test email to %s from %s <%s> with subject "%s"...', $toEmail, $this->fromName, $this->fromAddress, $subject));

        try {
            $email = (new Email())
                ->from(sprintf('"%s" <%s>', $this->fromName, $this->fromAddress))
                ->to($toEmail)
                ->subject($subject)
                ->text($body)
                ->html('<p>' . nl2br(htmlspecialchars($body)) . '</p>');

            $this->mailer->send($email);

            $io->success('Test email sent successfully!');
            $io->note('Please check the Mailpit web interface (usually http://localhost:8025) to see if the email was received.');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to send test email: ' . $e->getMessage());
            $io->writeln('Error details: ' . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }
    }
}

