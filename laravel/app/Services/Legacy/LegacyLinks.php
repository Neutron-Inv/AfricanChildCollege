<?php

namespace App\Services\Legacy;

use App\Models\LegacyLink;
use Illuminate\Support\Str;

/**
 * Idempotency ledger: legacy_links maps (entity, legacy key) -> new record id, per school.
 */
class LegacyLinks
{
    public function __construct(private readonly string $schoolId) {}

    public function get(string $entity, string|int $key): ?string
    {
        return LegacyLink::query()
            ->where('school_id', $this->schoolId)
            ->where('entity', $entity)
            ->where('legacy_key', (string) $key)
            ->value('record_id');
    }

    /**
     * @param  iterable<string|int>  $keys
     * @return array<string, string> legacy key => record id
     */
    public function many(string $entity, iterable $keys): array
    {
        $keys = collect($keys)->map(fn ($k) => (string) $k)->unique()->values();
        $found = [];

        foreach ($keys->chunk(500) as $chunk) {
            $found += LegacyLink::query()
                ->where('school_id', $this->schoolId)
                ->where('entity', $entity)
                ->whereIn('legacy_key', $chunk->all())
                ->pluck('record_id', 'legacy_key')
                ->all();
        }

        return $found;
    }

    /** Every link of an entity: legacy key => record id. */
    public function all(string $entity): array
    {
        return LegacyLink::query()
            ->where('school_id', $this->schoolId)
            ->where('entity', $entity)
            ->pluck('record_id', 'legacy_key')
            ->all();
    }

    public function remember(string $entity, string|int $key, string $recordId): void
    {
        LegacyLink::query()->firstOrCreate(
            ['school_id' => $this->schoolId, 'entity' => $entity, 'legacy_key' => (string) $key],
            ['record_id' => $recordId],
        );
    }

    /**
     * Bulk-insert links (ignoring ones that already exist).
     *
     * @param  array<string, string>  $links  legacy key => record id
     */
    public function rememberMany(string $entity, array $links): void
    {
        foreach (array_chunk($links, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $key => $recordId) {
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'school_id' => $this->schoolId,
                    'entity' => $entity,
                    'legacy_key' => (string) $key,
                    'record_id' => $recordId,
                ];
            }
            LegacyLink::query()->insertOrIgnore($rows);
        }
    }
}
