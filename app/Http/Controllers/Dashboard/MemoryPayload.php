<?php

namespace App\Http\Controllers\Dashboard;

use App\Memory\Domain\Observation;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The JSON shape of a memory for the dashboard: list items carry an excerpt, the detail adds the full content.
 */
final class MemoryPayload
{
    private const EXCERPT_LENGTH = 200;

    /**
     * @return array<string, mixed>
     */
    public static function summary(Observation $memory): array
    {
        return [
            'id' => $memory->id,
            'title' => $memory->title,
            'type' => $memory->type,
            'project' => $memory->project,
            'scope' => $memory->scope,
            'topic_key' => $memory->topicKey,
            'excerpt' => self::excerpt($memory->content),
            'created_at' => self::time($memory->createdAt),
            'updated_at' => self::time($memory->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Observation $memory): array
    {
        return [...self::summary($memory), 'content' => $memory->content];
    }

    private static function excerpt(string $content): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', $content) ?? '');

        return mb_strlen($plain) > self::EXCERPT_LENGTH ? mb_substr($plain, 0, self::EXCERPT_LENGTH).'…' : $plain;
    }

    private static function time(?DateTimeImmutable $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
