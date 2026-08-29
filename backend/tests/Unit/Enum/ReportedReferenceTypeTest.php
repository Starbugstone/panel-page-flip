<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ReportedReferenceType;
use App\Service\PublicUrl;
use PHPUnit\Framework\TestCase;

/**
 * The rules that used to be three parallel `if` chains in unrelated services.
 *
 * Each kind of reference behaves differently in validation, in what may be
 * echoed back to the reporter, and in whether it is worth searching for. A kind
 * that missed one of those failed silently and differently in each place, so
 * every case is exercised here rather than only the interesting ones.
 */
final class ReportedReferenceTypeTest extends TestCase
{
    private const APP_URL = 'https://panel.example';

    /** @dataProvider validReferenceProvider */
    public function testItAcceptsAWellFormedReference(ReportedReferenceType $type, string $reference): void
    {
        self::assertNull($type->validate($reference, new PublicUrl(self::APP_URL)));
    }

    /** @return iterable<string, array{ReportedReferenceType, string}> */
    public static function validReferenceProvider(): iterable
    {
        yield 'invitation URL' => [ReportedReferenceType::InvitationUrl, self::APP_URL.'/share/invitation/abc123'];
        yield 'panel URL' => [ReportedReferenceType::PanelUrl, self::APP_URL.'/read/17'];
        yield 'comic code' => [ReportedReferenceType::SharingCode, 'C-ABCD-EFGH-JKLM'];
        yield 'group code' => [ReportedReferenceType::SharingCode, 'G-ABCD-EFGH-JKLM'];
        yield 'user code' => [ReportedReferenceType::UserCode, 'U-ABCD-EFGH-JKLM'];
        yield 'free text account' => [ReportedReferenceType::Account, 'someone@example.com'];
        yield 'free text comic' => [ReportedReferenceType::Comic, 'Some Title, issue 4'];
        yield 'anything else' => [ReportedReferenceType::Other, 'Case reference 12/2026'];
    }

    /** @dataProvider rejectedReferenceProvider */
    public function testItRefusesAReferenceThatIsNotWhatItClaims(ReportedReferenceType $type, string $reference): void
    {
        self::assertNotNull($type->validate($reference, new PublicUrl(self::APP_URL)));
    }

    /** @return iterable<string, array{ReportedReferenceType, string}> */
    public static function rejectedReferenceProvider(): iterable
    {
        yield 'invitation URL on another origin' => [ReportedReferenceType::InvitationUrl, 'https://foreign.example/share/invitation/abc123'];
        yield 'panel URL that is not a reading URL' => [ReportedReferenceType::PanelUrl, self::APP_URL.'/library/17'];
        yield 'a user code where a content code belongs' => [ReportedReferenceType::SharingCode, 'U-ABCD-EFGH-JKLM'];
        yield 'a content code where a user code belongs' => [ReportedReferenceType::UserCode, 'C-ABCD-EFGH-JKLM'];
        yield 'not a code at all' => [ReportedReferenceType::SharingCode, 'have a look at this comic'];
    }

    /**
     * An invitation link and a sharing code are capabilities: whoever holds one
     * can use it, and the receipt travels by email.
     */
    public function testCapabilityBearingReferencesAreMaskedInTheReceipt(): void
    {
        self::assertSame(
            'https://panel.example/share/invitation/[masked-token]',
            ReportedReferenceType::InvitationUrl->maskForReceipt(self::APP_URL.'/share/invitation/secret-token'),
        );
        self::assertSame('C-[masked-code]', ReportedReferenceType::SharingCode->maskForReceipt('C-ABCD-EFGH-JKLM'));
        self::assertSame('U-[masked-code]', ReportedReferenceType::UserCode->maskForReceipt('U-ABCD-EFGH-JKLM'));
    }

    /** @dataProvider unmaskedProvider */
    public function testReferencesThatCarryNoCapabilityComeBackUnchanged(ReportedReferenceType $type): void
    {
        self::assertSame('the reporter typed this', $type->maskForReceipt('the reporter typed this'));
    }

    /** @return iterable<string, array{ReportedReferenceType}> */
    public static function unmaskedProvider(): iterable
    {
        yield 'account' => [ReportedReferenceType::Account];
        yield 'comic' => [ReportedReferenceType::Comic];
        yield 'panel URL' => [ReportedReferenceType::PanelUrl];
        yield 'other' => [ReportedReferenceType::Other];
    }

    public function testOnlyAComicReportHasToNameTheWork(): void
    {
        foreach (ReportedReferenceType::cases() as $type) {
            self::assertSame($type === ReportedReferenceType::Comic, $type->requiresContentTitle(), $type->value);
        }
    }

    /**
     * An application-issued URL or code resolves exactly or not at all. Putting
     * one through a leading-wildcard LIKE scan reads the whole table to find
     * nothing.
     */
    public function testOnlyFreeTextReferencesAreWorthSearchingFor(): void
    {
        foreach (ReportedReferenceType::cases() as $type) {
            $expected = in_array($type, [ReportedReferenceType::Comic, ReportedReferenceType::Account], true);
            self::assertSame($expected, $type->isSearchableText(), $type->value);
        }
    }
}
