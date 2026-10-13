<?php

declare(strict_types=1);

namespace Filagora\Cms\BlockTypes\Fields;

enum ScalarKind: string
{
    case Text = 'text';
    case Url = 'url';
    case Textarea = 'textarea';
    case Markdown = 'markdown';
    case Select = 'select';
    case Toggle = 'toggle';
    case Number = 'number';
}
