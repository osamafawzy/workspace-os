<?php

namespace Modules\Workspace\Actions;

use App\Support\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Workspace\FloorMap\FloorMapConflict;
use Modules\Workspace\FloorMap\FloorObjectType;
use Modules\Workspace\FloorMap\FloorObjectTypes;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\FloorObject;

/**
 * Saves a floor's whole map, as the editor sends it.
 *
 * The editor keeps its changes as an unsaved draft — that is what makes undo
 * possible — and sends the complete map when somebody presses Save. This works
 * out what was added, changed and removed, and writes it in one transaction.
 *
 * Everything in the payload comes from a browser, so none of it is trusted:
 * types must be registered, numbers must be finite and inside sensible bounds,
 * settings are filtered to what each type takes, an object id must already
 * belong to this floor, and a workstation object must point at a desk on this
 * floor that no other object on the map points at.
 *
 * A save is refused if somebody else saved the map (or desks were arranged
 * onto it) since this editor loaded it; the map's revision says so.
 */
class SaveFloorMap
{
    /** More objects than this on one floor is not a floor plan. */
    public const MAX_OBJECTS = 5000;

    /** How far outside the floor an object may sit: an exit sign on the outside wall, a door swinging out. */
    public const MARGIN = 20.0;

    public function __construct(
        protected FloorObjectTypes $types,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array{revision: int, objects: list<array<string, mixed>>, summary: array<string, int>}
     *
     * @throws FloorMapConflict
     * @throws ValidationException
     */
    public function handle(Floor $floor, int $revision, array $objects): array
    {
        if (count($objects) > self::MAX_OBJECTS) {
            throw ValidationException::withMessages(['map' => 'A floor map can hold at most '.number_format(self::MAX_OBJECTS).' objects.']);
        }

        $clean = $this->validate($floor, $objects);

        return DB::transaction(function () use ($floor, $revision, $clean): array {
            // Locked for the length of the save, so two saves cannot both pass
            // the revision check and then both write.
            $current = Floor::query()->whereKey($floor->getKey())->lockForUpdate()->value('map_revision');

            if ((int) $current !== $revision) {
                throw new FloorMapConflict('This map has been changed since you opened it.');
            }

            $existing = $floor->mapObjects()->with('workstation')->get()->keyBy('id');
            $kept = [];
            $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0];
            $moved = [];
            $placed = [];

            // Removed first: a desk taken off the map and put back in the same
            // save must not trip the one-object-per-desk constraint.
            $incomingIds = array_filter(array_column($clean, 'id'));
            $removed = $existing->except($incomingIds);

            if ($removed->isNotEmpty()) {
                FloorObject::query()->whereKey($removed->modelKeys())->delete();
                $summary['deleted'] = $removed->count();
            }

            // Objects whose desk changes let go of it before any are written,
            // so two objects swapping desks do not collide on the
            // one-object-per-desk constraint halfway through.
            $relinked = collect($clean)
                ->filter(fn (array $object): bool => $object['id'] !== null
                    && $existing[$object['id']]->workstation_id !== $object['workstation_id'])
                ->pluck('id');

            if ($relinked->isNotEmpty()) {
                FloorObject::query()->whereKey($relinked)->update(['workstation_id' => null]);
            }

            foreach ($clean as $object) {
                $values = Arr::except($object, ['id']);

                if ($object['id'] === null) {
                    $kept[] = $floor->mapObjects()->create($values)->getKey();
                    $summary['created']++;

                    if ($object['workstation_id']) {
                        $placed[] = $object['workstation_id'];
                    }

                    continue;
                }

                /** @var FloorObject $model */
                $model = $existing[$object['id']];
                $model->fill($values);

                if ($model->isDirty()) {
                    if ($model->workstation && $model->isDirty(['x', 'y', 'rotation'])) {
                        $moved[] = $model->workstation->name;
                    }

                    $model->save();
                    $summary['updated']++;
                }

                $kept[] = $model->getKey();
            }

            $floor->newQuery()->whereKey($floor->getKey())->increment('map_revision');
            $floor->refresh();

            if (array_sum($summary) > 0) {
                $this->audit->log('map saved', 'Workspace', $floor, [], array_filter([
                    ...$summary,
                    'desks moved' => $moved ? implode(', ', array_slice($moved, 0, 50)).(count($moved) > 50 ? ' and '.(count($moved) - 50).' more' : '') : null,
                    'desks placed' => $placed ? count($placed) : null,
                    'desks taken off' => $removed->whereNotNull('workstation_id')->count() ?: null,
                ]), $floor->name);
            }

            return [
                'revision' => $floor->map_revision,
                'objects' => $floor->mapObjects()->orderBy('id')->get()->map->toClient()->all(),
                'summary' => $summary,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return list<array<string, mixed>>
     */
    protected function validate(Floor $floor, array $objects): array
    {
        $errors = [];
        $clean = [];
        $existingIds = $floor->mapObjects()->pluck('id')->flip();
        $floorDesks = $floor->workstations()->pluck('id')->flip();
        $seenIds = [];
        $seenDesks = [];

        foreach (array_values($objects) as $index => $object) {
            $where = 'Object '.($index + 1);

            if (! is_array($object)) {
                $errors["objects.{$index}"] = "{$where} is not an object.";

                continue;
            }

            $type = $this->types->get((string) ($object['type'] ?? ''));

            if (! $type) {
                $errors["objects.{$index}.type"] = "{$where} has an unknown type.";

                continue;
            }

            $where = "{$where} ({$type->label})";
            $id = isset($object['id']) && $object['id'] !== null ? (int) $object['id'] : null;

            if ($id !== null && ! isset($existingIds[$id])) {
                $errors["objects.{$index}.id"] = "{$where} is not on this floor.";

                continue;
            }

            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    $errors["objects.{$index}.id"] = "{$where} appears twice.";

                    continue;
                }

                $seenIds[$id] = true;
            }

            $number = function (string $field, float $min, float $max) use ($object, $where, $index, &$errors): float {
                $value = $object[$field] ?? null;

                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
                    $errors["objects.{$index}.{$field}"] = "{$where}: {$field} must be a number from {$min} to {$max}.";

                    return 0.0;
                }

                return (float) $value;
            };

            $values = [
                'id' => $id,
                'type' => $type->key,
                'x' => round($number('x', -self::MARGIN, $floor->width_m + self::MARGIN), 3),
                'y' => round($number('y', -self::MARGIN, $floor->depth_m + self::MARGIN), 3),
                'z' => round($number('z', -10, 50), 3),
                'width' => round($number('width', $type->minSize, $type->maxSize), 3),
                'depth' => round($number('depth', $type->minSize, $type->maxSize), 3),
                'height' => round($number('height', 0, 50), 3),
                'rotation' => round(fmod(fmod($number('rotation', -3600, 3600), 360) + 360, 360), 2),
                'label' => $this->label($object['label'] ?? null),
                'props' => $this->props($type, $object['props'] ?? []),
                'locked' => (bool) ($object['locked'] ?? false),
                'workstation_id' => null,
            ];

            if ($type->linksWorkstation) {
                $desk = isset($object['workstation_id']) ? (int) $object['workstation_id'] : 0;

                if (! isset($floorDesks[$desk])) {
                    $errors["objects.{$index}.workstation_id"] = "{$where} is not a workstation on this floor.";

                    continue;
                }

                if (isset($seenDesks[$desk])) {
                    $errors["objects.{$index}.workstation_id"] = "{$where}: the same workstation is on the map twice.";

                    continue;
                }

                $seenDesks[$desk] = true;
                $values['workstation_id'] = $desk;
            }

            $clean[] = $values;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    protected function label(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim(mb_substr($label, 0, 100));

        return $label === '' ? null : $label;
    }

    /**
     * Only the settings this type takes, each checked against its rule.
     * Anything else is dropped rather than stored.
     *
     * @return array<string, mixed>|null
     */
    protected function props(FloorObjectType $type, mixed $props): ?array
    {
        if (! is_array($props)) {
            return null;
        }

        $clean = [];

        foreach ($type->props as $key) {
            if (! array_key_exists($key, $props)) {
                continue;
            }

            $value = $props[$key];
            $rule = FloorObjectType::PROP_RULES[$key] ?? null;

            $valid = match (true) {
                $rule === 'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value),
                $rule === 'text' => is_string($value) && mb_strlen($value) <= 200,
                $rule === 'number' => is_numeric($value) && (float) $value >= 0.1 && (float) $value <= 10,
                is_array($rule) => in_array($value, $rule, true),
                default => false,
            };

            if ($valid) {
                $clean[$key] = $rule === 'number' ? round((float) $value, 2) : $value;
            }
        }

        return $clean ?: null;
    }
}
