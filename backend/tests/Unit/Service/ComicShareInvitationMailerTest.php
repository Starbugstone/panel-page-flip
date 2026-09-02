<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ComicShareInvitationMailer;
use App\Service\PreparedInvitation;
use App\Service\PublicUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class ComicShareInvitationMailerTest extends TestCase
{
    public function testSingleExplicitInvitationWithholdsItsIdentityFromTheEmailContext(): void
    {
        $owner = $this->owner();
        [$share, $comic] = $this->invitation($owner, 'Private title', true);
        $context = null;
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            static function (string $template, array $variables) use (&$context): string {
                self::assertSame('emails/share_comic.html.twig', $template);
                $context = $variables;

                return '<p>mail</p>';
            }
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::isInstanceOf(Email::class));

        $this->mailer($mailer, $twig)->send($share, $comic, $owner, 'plain-token');

        self::assertTrue($context['explicitContent']);
        self::assertSame('https://reader.example/share/invitation/plain-token', $context['shareLink']);
        self::assertSame('https://reader.example/privacy', $context['privacyUrl']);
    }

    public function testLargeGroupedInvitationUsesATitleSampleWithoutExplicitComicsOrIndividualLinks(): void
    {
        $owner = $this->owner();
        $prepared = [];
        for ($index = 1; $index <= ComicShareInvitationMailer::MAX_LISTED_INVITATIONS + 1; ++$index) {
            [$share, $comic] = $this->invitation($owner, 'Title '.$index, $index === 2);
            $prepared[] = new PreparedInvitation($share, $comic, 'token-'.$index);
        }

        $context = null;
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            static function (string $template, array $variables) use (&$context): string {
                self::assertSame('emails/share_comics.html.twig', $template);
                $context = $variables;

                return '<p>mail</p>';
            }
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->mailer($mailer, $twig)->sendGrouped($prepared, $owner);

        self::assertFalse($context['listLinks']);
        self::assertSame(21, $context['comicCount']);
        self::assertSame(1, $context['explicitCount']);
        self::assertCount(10, $context['sampleTitles']);
        self::assertNotContains('Title 2', $context['sampleTitles']);
        self::assertSame([], array_values(array_filter(array_column($context['invitations'], 'shareLink'))));
    }

    private function mailer(MailerInterface $mailer, Environment $twig): ComicShareInvitationMailer
    {
        return new ComicShareInvitationMailer(
            $mailer,
            $twig,
            new PublicUrl('https://reader.example'),
            'noreply@reader.example',
            'Panel Page Flip',
        );
    }

    private function owner(): User
    {
        return (new User())
            ->setEmail('owner@example.test')
            ->setName('Comic Owner');
    }

    /** @return array{ComicShare, Comic} */
    private function invitation(User $owner, string $title, bool $explicit): array
    {
        $comic = (new Comic())
            ->setOwner($owner)
            ->setTitle($title)
            ->setExplicitContent($explicit);
        $share = (new ComicShare($comic, $owner, 'reader@example.test'))
            ->markPending(new \DateTimeImmutable('+2 months'));

        return [$share, $comic];
    }
}
