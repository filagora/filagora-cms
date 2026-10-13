<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes;

/**
 * One kind of content block. `mediaPaths` says where in the block's `data` a media asset id is
 * kept (dot notation, `*` for each item of a list), so the package can tell which blocks use an
 * asset without knowing the block's shape.
 */
final readonly class BlockType
{
    /**
     * @param  list<string>  $mediaPaths
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $mediaPaths = [],
        public ?string $description = null,
    ) {}
}
