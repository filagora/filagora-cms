<?php

declare(strict_types=1);

namespace Filagora\Cms\Services\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Name rules shared by the page and menu draft services: a variant name is unique within its page
 * or menu, across the working draft and the parked ones.
 */
trait NamesDraftVariants
{
    protected const DEFAULT_VARIANT_NAME = 'Main';

    /**
     * @param  list<string>  $taken
     *
     * @throws ValidationException
     */
    protected function validatedVariantName(string $name, array $taken): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Give the variant a name of up to 100 characters.']);
        }

        if (in_array(mb_strtolower($name), array_map('mb_strtolower', $taken), true)) {
            throw ValidationException::withMessages(['name' => 'Another variant already uses this name.']);
        }

        return $name;
    }

    /**
     * The first free "Main", "Main 2", "Main 3"... for seeding a draft.
     *
     * @param  list<string>  $taken
     */
    protected function freeVariantName(array $taken): string
    {
        $taken = array_map('mb_strtolower', $taken);
        $name = self::DEFAULT_VARIANT_NAME;

        for ($i = 2; in_array(mb_strtolower($name), $taken, true); $i++) {
            $name = self::DEFAULT_VARIANT_NAME.' '.$i;
        }

        return $name;
    }

    /**
     * @throws ValidationException
     */
    protected function refuseWhileScheduled(bool $scheduled): void
    {
        if ($scheduled) {
            throw ValidationException::withMessages([
                'name' => 'This draft has a scheduled publish. Cancel the schedule before switching away from it.',
            ]);
        }
    }
}
