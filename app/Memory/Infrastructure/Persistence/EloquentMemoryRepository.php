<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Memory\Domain\ProjectRepository;
use App\Team\Domain\AccessContext;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMemoryRepository implements MemoryRepository
{
    public function __construct(private ProjectRepository $projects = new QueryProjectRepository) {}

    public function save(Observation $observation): Observation
    {
        $record = $observation->id === null
            ? new ObservationRecord
            : $this->withinTeam(ObservationRecord::where('id', $observation->id), $observation->teamId)->firstOrFail();
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

            // a new row, or a legacy row being adopted, is authored by its owner
            $record->created_by ??= $observation->userId;
        }

        $record->save();

        return $this->toDomain($record);
    }

    public function find(AccessContext $context, int $id): ?Observation
    {
        if ($context->isDenied()) {
            return null;
        }

        $record = $this->scopeTo(ObservationRecord::where('id', $id), $context)->first();

        return $record ? $this->toDomain($record) : null;
    }

    /**
     * With a team, matches that team's rows first and then the user's still-unowned legacy rows
     * (adopted on save); never another team's. Without a team, matches legacy rows only.
     */
    public function findByTopicKey(int $userId, ?string $project, string $scope, string $topicKey, ?int $teamId = null): ?Observation
    {
        $record = $this->withinTeam(ObservationRecord::where('user_id', $userId), $teamId)
            ->where('project', $project)
            ->where('scope', $scope)
            ->where('topic_key', $topicKey)
            ->orderByRaw('team_id IS NULL')
            ->first();

        return $record ? $this->toDomain($record) : null;
    }

    public function search(AccessContext $context, string $query, int $limit, ?string $project = null): array
    {
        if ($context->isDenied()) {
            return [];
        }

        return $this->scopeTo(ObservationRecord::query(), $context)
            ->when($project, fn ($builder) => $builder->where('project', $project))
            ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$query])
            ->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('english', ?)) DESC", [$query])
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    public function recentSessionSummaries(AccessContext $context, string $project, int $limit, ?int $onlyUserId = null): array
    {
        return $this->recentOf($context, $project, $limit, $onlyUserId, fn (Builder $builder) => $builder->where('type', 'session_summary'));
    }

    public function withTopicKey(AccessContext $context, string $project, int $limit, ?int $onlyUserId = null): array
    {
        return $this->recentOf($context, $project, $limit, $onlyUserId, fn (Builder $builder) => $builder
            ->where('type', '!=', 'session_summary')
            ->whereNotNull('topic_key'));
    }

    public function recentWithoutTopicKey(AccessContext $context, string $project, int $limit, ?int $onlyUserId = null): array
    {
        return $this->recentOf($context, $project, $limit, $onlyUserId, fn (Builder $builder) => $builder
            ->where('type', '!=', 'session_summary')
            ->whereNull('topic_key'));
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

    /**
     * @param  callable(Builder): Builder  $narrow
     * @return list<Observation>
     */
    private function recentOf(AccessContext $context, string $project, int $limit, ?int $onlyUserId, callable $narrow): array
    {
        if ($context->isDenied()) {
            return [];
        }

        return $narrow($this->scopeTo(ObservationRecord::where('project', $project), $context))
            ->when($onlyUserId !== null, fn (Builder $builder) => $builder->where('user_id', $onlyUserId))
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ObservationRecord $record) => $this->toDomain($record))
            ->all();
    }

    /**
     * Tenant filter for reads: the context team, and for a project-restricted member only the
     * granted projects (rows without a project are excluded, so it fails closed). System sees all.
     */
    private function scopeTo(Builder $builder, AccessContext $context): Builder
    {
        if ($context->isSystem()) {
            return $builder;
        }

        return $builder->where('team_id', $context->teamId)
            ->when(! $context->projects->isAll(), fn (Builder $scope) => $scope->whereIn('project_id', $context->projects->ids()));
    }

    private function withinTeam(Builder $builder, ?int $teamId): Builder
    {
        return $builder->where(fn (Builder $scope) => $scope->whereNull('team_id')
            ->when($teamId !== null, fn (Builder $scope) => $scope->orWhere('team_id', $teamId)));
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
            teamId: $record->team_id,
        );
    }
}
