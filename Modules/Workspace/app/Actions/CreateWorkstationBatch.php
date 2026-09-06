<?php

namespace Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Workspace\Models\Floor;

/**
 * Creates a run of workstations on a floor in one go.
 *
 * A floor of three hundred desks is not something anyone is going to type in
 * one form at a time, and the names on such a floor are always a sequence —
 * A-001 to A-300 — because that is how a building labels them.
 */
class CreateWorkstationBatch
{
    /** More than this in one go is almost certainly a typo in "how many". */
    public const MAX = 1000;

    /**
     * @return array{created: int, skipped: array<int, string>}
     */
    public function handle(
        Floor $floor,
        string $prefix,
        int $count,
        int $startAt = 1,
        int $pad = 3,
    ): array {
        $count = max(0, min(self::MAX, $count));

        if ($count === 0) {
            return ['created' => 0, 'skipped' => []];
        }

        $names = $this->names($prefix, $count, $startAt, $pad);

        // Names are unique per floor at the database level, so a clash would
        // abort the whole batch part-way through. Checking first turns that
        // into a list of names left alone, which is what somebody re-running a
        // batch to fill a gap actually wants.
        $taken = $floor->workstations()
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        $fresh = array_values(array_diff($names, $taken));

        if ($fresh !== []) {
            $now = now();

            DB::transaction(fn () => $floor->workstations()->insert(
                array_map(fn (string $name): array => [
                    'floor_id' => $floor->getKey(),
                    'name' => $name,
                    'position_x' => null,
                    'position_y' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $fresh),
            ));
        }

        return ['created' => count($fresh), 'skipped' => $taken];
    }

    /**
     * @return array<int, string>
     */
    public function names(string $prefix, int $count, int $startAt = 1, int $pad = 3): array
    {
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $names[] = $prefix.str_pad((string) ($startAt + $i), max(1, $pad), '0', STR_PAD_LEFT);
        }

        return $names;
    }
}
