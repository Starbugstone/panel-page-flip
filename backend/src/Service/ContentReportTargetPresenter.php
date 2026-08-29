<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;

/**
 * The API representation of a content-report target.
 *
 * Candidate search, queue summaries and saved report details all describe the
 * same three entity types. Keeping that projection here prevents those paths
 * from drifting on labels, owners or deleted-record snapshots.
 */
final class ContentReportTargetPresenter
{
    public function type(Comic|User|ComicShare $entity): string
    {
        return match (true) {
            $entity instanceof Comic => 'comic',
            $entity instanceof User => 'user',
            $entity instanceof ComicShare => 'share',
        };
    }

    /** @return array<string, mixed> */
    public function candidate(Comic|User|ComicShare $entity, string $source): array
    {
        return $this->entity($entity) + ['source' => $source];
    }

    /**
     * The one record a report points at, most specific first.
     *
     * @return array{type: string, id: int|null, label: string|null, title?: string|null, name?: string|null, email?: string|null, owner?: array{id: int|null, name: string}|null}|null
     */
    public function linked(ContentReport $report): ?array
    {
        if ($report->getLinkedShare() !== null) {
            $target = $this->entity($report->getLinkedShare());
            $target['label'] = $target['title'];
            $target['owner'] ??= $this->owner($report->getLinkedUser());

            return $target;
        }
        if ($report->getLinkedComic() !== null) {
            $target = $this->entity($report->getLinkedComic());
            $target['label'] = $target['title'];
            $target['owner'] ??= $this->owner($report->getLinkedUser());

            return $target;
        }
        if ($report->getLinkedUser() !== null) {
            $target = $this->entity($report->getLinkedUser());
            $target['label'] = $target['name'];

            return $target;
        }
        if ($report->getLinkedComicIdSnapshot() !== null || $report->getLinkedUserIdSnapshot() !== null || $report->getLinkedShareIdSnapshot() !== null) {
            return [
                'type' => 'snapshot',
                'id' => $report->getLinkedShareIdSnapshot() ?? $report->getLinkedComicIdSnapshot() ?? $report->getLinkedUserIdSnapshot(),
                'label' => $report->getLinkedComicTitleSnapshot() ?: 'Deleted linked record',
            ];
        }

        return null;
    }

    /** @return array{status: string, method: string|null, candidates: list<array<string, mixed>>} */
    public function settledResolution(ContentReport $report): array
    {
        $linked = $this->linked($report);
        if ($linked === null || $linked['type'] === 'snapshot') {
            return ['status' => 'none', 'method' => null, 'candidates' => []];
        }

        return [
            'status' => 'exact',
            'method' => $report->getResolutionMethod(),
            'candidates' => [$linked + ['source' => 'exact']],
        ];
    }

    /** @return array<string, mixed> */
    private function entity(Comic|User|ComicShare $entity): array
    {
        return match (true) {
            $entity instanceof Comic => [
                'type' => 'comic',
                'id' => $entity->getId(),
                'title' => $entity->getTitle(),
                'owner' => $this->owner($entity->getOwner()),
            ],
            $entity instanceof User => [
                'type' => 'user',
                'id' => $entity->getId(),
                'name' => $entity->getName(),
                'email' => $entity->getEmail(),
            ],
            $entity instanceof ComicShare => [
                'type' => 'share',
                'id' => $entity->getId(),
                'status' => $entity->getStatus(),
                'title' => $entity->getComic()?->getTitle() ?? $entity->getComicTitleSnapshot(),
                'owner' => $this->owner($entity->getOwner()),
            ],
        };
    }

    /** @return array{id: int|null, name: string}|null */
    private function owner(?User $owner): ?array
    {
        return $owner === null ? null : ['id' => $owner->getId(), 'name' => $owner->getName()];
    }
}
