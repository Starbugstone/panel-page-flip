<?php

namespace App\Tests\Unit\Metadata;

use App\Entity\Comic;
use App\Metadata\StructuredMetadataInput;
use PHPUnit\Framework\TestCase;

/**
 * The one seam that writes structured metadata sent by a client.
 *
 * Both the single-comic update and the dashboard's batch save go through it,
 * so anything accepted here is accepted by both and anything rejected is
 * rejected by both.
 */
final class StructuredMetadataInputTest extends TestCase
{
    public function testAcceptsReviewedCredits(): void
    {
        $comic = new Comic();

        self::assertTrue($this->input()->applyTo([
            'creators' => ['Writer' => ['Garth Ennis'], 'penciller' => ['Darick Robertson']],
        ], $comic));

        self::assertSame(
            ['writer' => ['Garth Ennis'], 'penciller' => ['Darick Robertson']],
            $comic->getCreators()
        );
    }

    /** Keeping half of somebody's accepted credit list silently is worse. */
    public function testRejectsMalformedCreditsRatherThanTrimmingThem(): void
    {
        $input = $this->input();

        self::assertFalse($input->applyTo(['creators' => ['writer' => 'Garth Ennis']], new Comic()));
        self::assertNotSame([], $input->errors());
    }

    public function testRefusesAnAbsurdNumberOfCredits(): void
    {
        $roles = [];
        for ($i = 0; $i < 50; ++$i) {
            $roles['role'.$i] = ['Somebody'];
        }

        self::assertFalse($this->input()->applyTo(['creators' => $roles], new Comic()));
    }

    public function testAnEmptyCreditListClearsTheField(): void
    {
        $comic = (new Comic())->setCreators(['writer' => ['Garth Ennis']]);

        self::assertTrue($this->input()->applyTo(['creators' => []], $comic));
        self::assertSame([], $comic->getCreators());
    }

    /** Recorded so a later refresh can ask for that exact record again. */
    public function testRecordsWhichExternalRecordTheComicWasMatchedTo(): void
    {
        $comic = new Comic();

        self::assertTrue($this->input()->applyTo([
            'metadataProvider' => 'metron',
            'metadataExternalId' => '123925',
        ], $comic));

        self::assertSame('metron', $comic->getMetadataProvider());
        self::assertSame('123925', $comic->getMetadataExternalId());
        self::assertNotNull($comic->getMetadataFetchedAt());
    }

    /** The value reaches a URL path, so it is checked rather than trusted. */
    public function testRefusesAProviderThisServerDoesNotHave(): void
    {
        self::assertFalse($this->input()->applyTo([
            'metadataProvider' => 'somewhere-else',
            'metadataExternalId' => '1',
        ], new Comic()));
    }

    public function testRefusesARecordReferenceThatIsNotAnIdentifier(): void
    {
        self::assertFalse($this->input()->applyTo([
            'metadataProvider' => 'metron',
            'metadataExternalId' => '../../series/1',
        ], new Comic()));
    }

    /** Half a reference cannot be looked up, so it is worse than none. */
    public function testClearingEitherHalfClearsBoth(): void
    {
        $comic = new Comic();
        $this->input()->applyTo(['metadataProvider' => 'metron', 'metadataExternalId' => '1'], $comic);

        self::assertTrue($this->input()->applyTo(['metadataExternalId' => null], $comic));
        self::assertNull($comic->getMetadataProvider());
        self::assertNull($comic->getMetadataExternalId());
        self::assertNull($comic->getMetadataFetchedAt());
    }

    /** A field the payload does not mention is not a field the user changed. */
    public function testLeavesUnmentionedFieldsAlone(): void
    {
        $comic = (new Comic())->setCreators(['writer' => ['Garth Ennis']]);
        $this->input()->applyTo(['metadataProvider' => 'metron', 'metadataExternalId' => '1'], $comic);

        self::assertTrue($this->input()->applyTo(['series' => 'The Boys'], $comic));
        self::assertSame(['writer' => ['Garth Ennis']], $comic->getCreators());
        self::assertSame('metron', $comic->getMetadataProvider());
    }

    private function input(): StructuredMetadataInput
    {
        return new StructuredMetadataInput(['metron', 'comicvine']);
    }
}
