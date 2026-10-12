<?php

declare(strict_types=1);

namespace ForumKit\Cms\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the package's model classes. The package never names a model class directly:
 * a host swaps one under `forumkit.models.<key>` (or `forumkit-cms.models.<key>`) and every
 * relation and query follows.
 */
final class Models
{
    /**
     * @return class-string<Model>
     */
    public static function class(string $key): string
    {
        /** @var class-string<Model>|null $class */
        $class = config("forumkit.models.{$key}") ?? config("forumkit-cms.models.{$key}");

        return $class ?? throw new InvalidArgumentException("No ForumKit CMS model is registered for [{$key}].");
    }
}
