<?php

declare(strict_types=1);

namespace Filagora\Cms\Filament\Forms;

use Filament\Forms\Components\MarkdownEditor;

/**
 * Markdown editor for page and block prose. File attachments are off because media is managed
 * through the media library.
 */
class CmsMarkdownEditor extends MarkdownEditor
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->toolbarButtons([
            ['bold', 'italic', 'strike', 'link'],
            ['heading'],
            ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
            ['table'],
            ['undo', 'redo'],
        ]);
        $this->helperText(__('filagora-cms::ui.cms_markdown_editor.markdown_is_supported_inline_html_in'));
    }

    public function rows(int $rows): static
    {
        return $this->minHeight(max(8, $rows * 2.5).'rem');
    }
}
