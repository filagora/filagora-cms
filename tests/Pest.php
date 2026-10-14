<?php

declare(strict_types=1);

use Filagora\Cms\Tests\NoEntitlementsTestCase;
use Filagora\Cms\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(NoEntitlementsTestCase::class)->use(RefreshDatabase::class)->in('NoEntitlements');
