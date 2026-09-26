<?php

namespace Modules\Workspace\FloorMap;

/**
 * Every kind of object the floor map knows, in toolbar order.
 *
 * The built-in types are registered here; another module adds its own with
 * `app(FloorObjectTypes::class)->register(new FloorObjectType(...))` from its
 * service provider.
 */
class FloorObjectTypes
{
    /** @var array<string, FloorObjectType> */
    protected array $types = [];

    public static function withDefaults(): self
    {
        $types = new self;

        foreach (self::defaults() as $type) {
            $types->register($type);
        }

        return $types;
    }

    public function register(FloorObjectType $type): void
    {
        $this->types[$type->key] = $type;
    }

    public function get(string $key): ?FloorObjectType
    {
        return $this->types[$key] ?? null;
    }

    /** @return array<string, FloorObjectType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return list<array<string, mixed>> */
    public function toClient(): array
    {
        return array_values(array_map(fn (FloorObjectType $type): array => $type->toClient(), $this->types));
    }

    /** @return list<FloorObjectType> */
    protected static function defaults(): array
    {
        return [
            // The desk record's place on the map: desk, monitor, keyboard and
            // chair, with the desk's ID on it.
            new FloorObjectType('workstation', 'Workstation', 'workstation', 'Furniture', 'heroicon-o-computer-desktop',
                width: 1.4, depth: 1.5, height: 0.75, color: '#cbd5e1', linksWorkstation: true, primary: true, minSize: 0.6, maxSize: 4),

            new FloorObjectType('desk', 'Desk', 'desk', 'Furniture', 'heroicon-o-view-columns',
                width: 1.4, depth: 0.75, height: 0.75, color: '#cbd5e1', props: ['color'], primary: true, minSize: 0.3, maxSize: 10),

            new FloorObjectType('rack', 'Network rack', 'box', 'Equipment', 'heroicon-o-server-stack',
                width: 0.6, depth: 1.0, height: 2.0, color: '#334155', props: ['color'], primary: true, maxSize: 5),

            new FloorObjectType('printer', 'Printer', 'box', 'Equipment', 'heroicon-o-printer',
                width: 0.6, depth: 0.5, height: 1.0, color: '#e2e8f0', props: ['color'], primary: true, maxSize: 5),

            new FloorObjectType('room', 'Room', 'room', 'Structure', 'heroicon-o-rectangle-group',
                width: 6, depth: 4, color: '#38bdf8', scalesWithFloor: true, props: ['color'], primary: true, layer: 0, minSize: 0.5),

            new FloorObjectType('wall', 'Wall', 'wall', 'Structure', 'heroicon-o-minus',
                width: 5, depth: 0.15, height: 2.8, color: '#64748b', scalesWithFloor: true, props: ['color'], primary: true, layer: 5),

            new FloorObjectType('door', 'Door', 'door', 'Structure', 'heroicon-o-arrow-right-end-on-rectangle',
                width: 0.9, depth: 0.15, height: 2.1, color: '#b45309', props: ['swing'], primary: true, layer: 6, maxSize: 6),

            new FloorObjectType('text', 'Text', 'text', 'Signs', 'heroicon-o-language',
                width: 3, depth: 0.6, color: '#e2e8f0', resizable: false, props: ['text', 'size', 'color'], primary: true, layer: 20),

            new FloorObjectType('meeting-room', 'Meeting room', 'room', 'Structure', 'heroicon-o-rectangle-group',
                width: 5, depth: 4, color: '#a78bfa', scalesWithFloor: true, props: ['color'], layer: 0, minSize: 0.5),

            new FloorObjectType('it-room', 'IT room', 'room', 'Structure', 'heroicon-o-rectangle-group',
                width: 4, depth: 3, color: '#f59e0b', scalesWithFloor: true, props: ['color'], layer: 0, minSize: 0.5),

            new FloorObjectType('column', 'Column', 'box', 'Structure', 'heroicon-o-stop',
                width: 0.5, depth: 0.5, height: 2.8, color: '#94a3b8', props: ['color'], layer: 5, maxSize: 5),

            new FloorObjectType('emergency-exit', 'Emergency exit', 'marker', 'Signs', 'heroicon-o-arrow-right-start-on-rectangle',
                width: 1.2, depth: 0.5, color: '#16a34a', props: ['text'], layer: 20, maxSize: 5),

            new FloorObjectType('custom', 'Custom object', 'box', 'Other', 'heroicon-o-cube',
                width: 1, depth: 1, height: 1, color: '#0ea5e9', props: ['color'], maxSize: 50),
        ];
    }
}
