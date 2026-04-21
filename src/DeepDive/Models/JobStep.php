<?php

declare(strict_types=1);

namespace App\DeepDive\Models;

final class JobStep
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int    $weight = 10,
        public string          $status = self::PENDING,
        public ?string         $detail = null,
        public ?int            $startedAt = null,
        public ?int            $finishedAt = null,
        public ?string         $error = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'weight'      => $this->weight,
            'status'      => $this->status,
            'detail'      => $this->detail,
            'started_at'  => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'error'       => $this->error,
        ];
    }
}
