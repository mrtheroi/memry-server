<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Memory\Domain\ProjectRepository;

final class EloquentMemoryRepository implements MemoryRepository
{
    public function __construct(private ProjectRepository $projects = new QueryProjectRepository) {}

    public function save(Observation $observation): Observation
    {
        $record = ObservationRecord::firstOrNew(['id' => $observation->id]);
        $record->fill([
            'user_id' => $observation->userId,
            'session_id' => $observation->sessionId,
            'type' => $observation->type,
            'title' => $observation->title,
            'content' => $observation->content,
            'project' => $observation->project,
            'scope' => $observation->scope,
            'topic_key' => $observation->topicKey,
        ]);

        if ($observation->teamId !== null) {
            $record->fill([
                'team_id' => $observation->teamId,
                'project_id' => $observation->project === null
                    ? null
                    : $this->projects->getOrCreateId($observation->teamId, $observation->project),
                'updated_by' => $observation->userId,
            ]);

            if (! $record->exists) {
                $record->created_by = $observation->userId;
            }
        }

        $record->save();

        return $this->toDomain($record);
    }

    public function find(int $id): ?Observation
    {
        $record = ObservationRecord::find($id);

        return $record ? $this->toDomain($record) : null;
    }

    public function findByTopicKey(int $userId, ?string $project, string $scope, string $topicKey): ?Observation
    {
        $record = ObservationRecord::where('user_id', $userId)
            ->where('project', $project)
            ->where('scope', $scope)
            ->where('topic_key', $topicKey)
            ->first();

        return $record ? $this->toDomain($record) : null;
    }

    public function search(int $userId, string $query, int $limit, ?string $project = null): array
    {
        return ObservationRecord::where('user_id', $userId)
            ->when($project, fn ($builder) => $builder->where('project', $project))
            ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$query])
            ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('english', ?)) DESC", [$query])
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    public function recentSessionSummaries(int $userId, string $project, int $limit): array
    {
        return ObservationRecord::where('user_id', $userId)
            ->where('project', $project)
            ->where('type', 'session_summary')
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    public function withTopicKey(int $userId, string $project, int $limit): array
    {
        return ObservationRecord::where('user_id', $userId)
            ->where('project', $project)
            ->where('type', '!=', 'session_summary')
            ->whereNotNull('topic_key')
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    public function recentWithoutTopicKey(int $userId, string $project, int $limit): array
    {
        return ObservationRecord::where('user_id', $userId)
            ->where('project', $project)
            ->where('type', '!=', 'session_summary')
            ->whereNull('topic_key')
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    public function countTopicKeyCollisions(string $from, string $to, ?int $userId): int
    {
        return ObservationRecord::where('project', $from)
            ->when($userId, fn ($builder) => $builder->where('user_id', $userId))
            ->whereNotNull('topic_key')
            ->whereExists(fn ($query) => $query->from('observations as target')
                ->whereColumn('target.user_id', 'observations.user_id')
                ->whereColumn('target.topic_key', 'observations.topic_key')
                ->where('target.project', $to))
            ->count();
    }

    public function moveToProject(string $from, string $to, ?int $userId): int
    {
        return (new MoveToProject)('observations', $from, $to, $userId);
    }

    private function toDomain(ObservationRecord $record): Observation
    {
        return new Observation(
            userId: $record->user_id,
            sessionId: $record->session_id,
            type: $record->type,
            title: $record->title,
            content: $record->content,
            project: $record->project,
            scope: $record->scope,
            topicKey: $record->topic_key,
            id: $record->id,
            updatedAt: $record->updated_at?->toDateTimeImmutable(),
        );
    }
}
