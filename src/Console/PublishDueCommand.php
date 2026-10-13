<?php

declare(strict_types=1);

namespace ForumKit\Cms\Console;

use ForumKit\Cms\Services\PageDrafts;
use Illuminate\Console\Command;

/**
 * Publishes the page drafts (and, once menus have drafts, the menu drafts) whose scheduled time
 * has arrived. Schedule it every minute or so.
 */
class PublishDueCommand extends Command
{
    protected $signature = 'forumkit:cms:publish-due';

    protected $description = 'Publish CMS page drafts whose scheduled publish time has arrived';

    public function handle(PageDrafts $pages): int
    {
        $this->info('Published '.$pages->publishDue().' scheduled CMS page(s).');

        return self::SUCCESS;
    }
}
