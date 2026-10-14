<?php

namespace App\Memory\Infrastructure\Persistence;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Memory\Domain\ProjectRepository;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessDenied;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMemoryRepository implements MemoryRepository
{
    public function __construct(private ProjectRepository $projects = new QueryProjectRepository) {}

    public function save(Observation $observation): Observation
    {
        $teamId = $observation->teamId ?? throw AccessDenied::noTeam();
        $record = $observation->id === null
            ? new ObservationRecord
            : $this->withinTeam(ObservationRecord::where('id', $observation->id), $teamId)->firstOrFail();
        $record->user_id ??= $observation->userId;
        $record->fill([
            'session_id' => $observation->sessionId,
            'type' => $observation->type,
            'title' => $observation->title,
            'content' => $observation->content,
            'project' => $observation->project,
            'scope' => $observation->scope,
            'topic_key' => $observation->topicKey,
            'team_id' => $teamId,
            'project_id' => $observation->project === null
                ? null
                : $this->projects->getOrCreateId($teamId, $observation->project),
            'updated_by' => $observation->userId,
        ]);

        $record->created_by ??= $observation->userId;
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
     * Project scope resolves per team, whoever wrote it; personal scope only among the writer's own rows.
     */
    public function findByTopicKey(AccessContext $context, ?string $project, string $scope, string $topicKey): ?Observation
    {
        if ($context->isDenied() || $context->isSystem()) {
            return null;
        }

        $record = ObservationRecord::where('team_id', $context->teamId)
            ->where('project', $project)
            ->where('scope', $scope)
            ->where('topic_key', $topicKey)
            ->when($scope === 'personal', fn (Builder $builder) => $builder->where('user_id', $context->userId))
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

    public function recentSessionSummaries(AccessContext $context, string $project, int $limit): array
    {
        return $this->recentOf($context, $project, $limit, fn (Builder $builder) => $builder->where('type', 'session_summary'));
    }

    public function withTopicKey(AccessContext $context, string $project, int $limit): array
    {
        return $this->recentOf($context, $project, $limit, fn (Builder $builder) => $builder
            ->where('type', '!=', 'session_summary')
            ->whereNotNull('topic_key'));
    }

    public function recentWithoutTopicKey(AccessContext $context, string $project, int $limit): array
    {
        return $this->recentOf($context, $project, $limit, fn (Builder $builder) => $builder
            ->where('type', '!=', 'session_summary')
            ->whereNull('topic_key'));
    }

    public function countTopicKeyCollisions(AccessContext $context, string $from, string $to): int
    {
        if ($context->isDenied()) {
            return 0;
        }

        return ObservationRecord::where('project', $from)
            ->when(! $context->isSystem(), fn ($builder) => $builder->where('team_id', $context->teamId))
            ->whereNotNull('topic_key')
            // The same identity findByTopicKey() upserts on: team, scope and
            // topic_key, plus the author for personal scope.
            ->whereExists(fn ($query) => $query->from('observations as target')
                ->whereColumn('target.team_id', 'observations.team_id')
                ->whereColumn('target.scope', 'observations.scope')
                ->whereColumn('target.topic_key', 'observations.topic_key')
                ->where(fn ($author) => $author->where('observations.scope', '<>', 'personal')
                    ->orWhereColumn('target.user_id', 'observations.user_id'))
                ->where('target.project', $to))
            ->count();
    }

    public function moveToProject(AccessContext $context, string $from, string $to): int
    {
        return (new MoveToProject)($context, 'observations', $from, $to);
    }

    /**
     * @param  callable(Builder): Builder  $narrow
     * @return list<Observation>
     */
    private function recentOf(AccessContext $context, string $project, int $limit, callable $narrow): array
    {
        if ($context->isDenied()) {
            return [];
        }

        return $narrow($this->scopeTo(ObservationRecord::where('project', $project), $context))
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

    private function withinTeam(Builder $builder, int $teamId): Builder
    {
        return $builder->where('team_id', $teamId);
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
