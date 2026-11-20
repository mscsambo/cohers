<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Public identifier for URLs: an RFC 9562 UUID version 7 in the `uuid` column.
 * Integer primary keys stay internal (foreign keys, reference codes, jobs); routes
 * bind by uuid, so links never expose sequential record numbers.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A row written without one (e.g. by a worker still running code from before
     * uuids existed) gets its uuid on first link instead of breaking the page.
     */
    public function getRouteKey(): mixed
    {
        if (empty($this->uuid) && $this->exists) {
            $this->uuid = (string) Str::uuid7();
            $this->saveQuietly();
        }

        return $this->uuid;
    }

    /** Fills any missing uuids in id order; returns how many rows were fixed. */
    public static function fillMissingUuids(): int
    {
        $fixed = 0;
        static::query()->whereNull('uuid')->orderBy('id')->select('id')->chunkById(500, function ($rows) use (&$fixed) {
            foreach ($rows as $row) {
                static::query()->whereKey($row->id)->update(['uuid' => (string) Str::uuid7()]);
                $fixed++;
            }
        });

        return $fixed;
    }

    /** Resolve a public uuid (from a URL or filter) to the internal id, or null. */
    public static function idFromUuid(?string $uuid): ?int
    {
        if (! $uuid || ! Str::isUuid($uuid)) {
            return null;
        }

        return static::where('uuid', $uuid)->value('id');
    }
}
