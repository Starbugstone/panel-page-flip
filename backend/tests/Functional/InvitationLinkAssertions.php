<?php

namespace App\Tests\Functional;

/**
 * Reading an invitation link out of the email that carries it.
 *
 * The link used to come back in the response that created the share, because
 * the token was minted while the share was being written. It is not any more:
 * the share is committed first and announced afterwards, and the worker mints
 * the token at the moment it writes it into the message — so the email is the
 * only place a fresh link exists.
 *
 * Which makes this the better assertion anyway. A test that follows the link it
 * found in the email is proving the thing a recipient actually does.
 */
trait InvitationLinkAssertions
{
    /** The invitation link in the most recent email, or a failed assertion. */
    protected function invitationUrlFromEmail(int $index = 0): string
    {
        // Any of the links will do for a single-comic invitation; a grouped
        // email carries one per comic, and {@see invitationUrlsFromEmail}
        // returns all of them in the order the template rendered.
        $urls = $this->invitationUrlsFromEmail($index);
        self::assertNotEmpty($urls, 'The invitation email carried no link.');

        return $urls[0];
    }

    /**
     * Every invitation link in an email, in order.
     *
     * @return list<string>
     */
    protected function invitationUrlsFromEmail(int $index = 0): array
    {
        $message = self::getMailerMessage($index);
        self::assertNotNull($message, 'No invitation email was sent.');

        // Decoded first: the template escapes the href with `html_attr`, which
        // turns every slash and colon in the URL into an entity. Matching the
        // raw body would look for something the recipient's mail client never
        // sees either.
        $body = html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES | ENT_HTML5);

        preg_match_all('#https?://[^"\'\s<]+/share/invitation/[A-Za-z0-9_-]+#', $body, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** The plaintext token out of the most recent invitation email. */
    protected function invitationTokenFromEmail(int $index = 0): string
    {
        $url = $this->invitationUrlFromEmail($index);

        return substr($url, strrpos($url, '/') + 1);
    }
}
