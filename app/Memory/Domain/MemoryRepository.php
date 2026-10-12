<?php

namespace App\Memory\Domain;

interface MemoryRepository
{
    public function save(Observation $observation): Observation;

    public function find(int $id): ?Observation;

    public function findByTopicKey(int $userId, ?string $project, string $scope, string $topicKey, ?int $teamId = null): ?Observation;

    /**
     * @return list<Observation>
     */
    public function search(int $userId, string $query, int $limit, ?string $project = null): array;

    /**
     * @return list<Observation>
     */
    public function recentSessionSummaries(int $userId, string $project, int $limit): array;

    /**
     * @return list<Observation>
     */
    public function withTopicKey(int $userId, string $project, int $limit): array;

    /**
     * @return list<Observation>
     */
    public function recentWithoutTopicKey(int $userId, string $project, int $limit): array;

    /**
     * Count the observations of a project whose user already has the same
     * topic key in another project. A null user counts every user.
     */
    public function countTopicKeyCollisions(string $from, string $to, ?int $userId): int;

    /**
     * Move observations to another project, returning how many were moved.
     * A null user moves the rows of every user.
     */
    public function moveToProject(string $from, string $to, ?int $userId): int;
}
