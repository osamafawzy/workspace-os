<?php

namespace Modules\Workspace\FloorMap;

/**
 * One kind of thing that can be put on a floor map.
 *
 * A type is data, not code: its size, colour, toolbar icon, which settings it
 * takes, and which of the map's renderers draws it. Adding a type — a fire
 * extinguisher, a plant, a camera — is registering one of these from a
 * module's service provider with an existing renderer. Only a genuinely new
 * shape needs a new renderer in floor-map.js.
 *
 * Sizes are metres. `width` runs along the object's own x axis, `depth` along
 * its y axis; at rotation 0 those are the map's x and y.
 */
final class FloorObjectType
{
    /** The renderers floor-map.js knows how to draw. */
    public const RENDERERS = ['workstation', 'desk', 'box', 'room', 'wall', 'door', 'marker', 'text'];

    /** The settings a type may take in `props`, and what each must be. */
    public const PROP_RULES = [
        'color' => 'color',
        'text' => 'text',
        'swing' => ['left', 'right'],
        'size' => 'number',
    ];

    /**
     * @param  list<string>  $props  keys of PROP_RULES this type accepts
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $renderer,
        public readonly string $group,
        public readonly string $icon,
        public readonly float $width,
        public readonly float $depth,
        public readonly float $height = 0.0,
        public readonly string $color = '#64748b',
        /** A workstation object is the place of one workstation record. */
        public readonly bool $linksWorkstation = false,
        /** Walls and rooms stretch with the floor when it is resized; furniture only moves. */
        public readonly bool $scalesWithFloor = false,
        public readonly bool $resizable = true,
        public readonly array $props = [],
        /** In the main toolbar, rather than under "More". */
        public readonly bool $primary = false,
        /** Drawing order: floor areas below walls below furniture below labels. */
        public readonly int $layer = 10,
        public readonly float $minSize = 0.05,
        public readonly float $maxSize = 500.0,
    ) {
        if (! in_array($renderer, self::RENDERERS, true)) {
            throw new \InvalidArgumentException("Unknown floor map renderer [{$renderer}].");
        }
    }

    /** @return array<string, mixed> what the editor needs to know about the type */
    public function toClient(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'renderer' => $this->renderer,
            'group' => $this->group,
            'width' => $this->width,
            'depth' => $this->depth,
            'height' => $this->height,
            'color' => $this->color,
            'linksWorkstation' => $this->linksWorkstation,
            'resizable' => $this->resizable,
            'props' => $this->props,
            'primary' => $this->primary,
            'layer' => $this->layer,
            'minSize' => $this->minSize,
            'maxSize' => $this->maxSize,
        ];
    }
}
