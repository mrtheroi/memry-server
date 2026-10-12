<?php

namespace App\Memory\Domain;

use App\Team\Domain\AccessContext;

interface MemoryRepository
{
    public function save(Observation $observation): Observation;

    public function find(AccessContext $context, int $id): ?Observation;

    public function findByTopicKey(AccessContext $context, ?string $project, string $scope, string $topicKey): ?Observation;

    /**
     * @return list<Observation>
     */
    public function search(AccessContext $context, string $query, int $limit, ?string $project = null): array;

    /**
     * @return list<Observation>
     */
    public function recentSessionSummaries(AccessContext $context, string $project, int $limit): array;

    /**
     * @return list<Observation>
     */
    public function withTopicKey(AccessContext $context, string $project, int $limit): array;

    /**
     * @return list<Observation>
     */
    public function recentWithoutTopicKey(AccessContext $context, string $project, int $limit): array;

    /**
     * Count the observations of a project whose team already has the same
     * topic key in another project. The system context counts every team.
     */
    public function countTopicKeyCollisions(AccessContext $context, string $from, string $to): int;

    /**
     * Move observations to another project, returning how many were moved.
     * The system context moves the rows of every team.
     */
    public function moveToProject(AccessContext $context, string $from, string $to): int;
}
