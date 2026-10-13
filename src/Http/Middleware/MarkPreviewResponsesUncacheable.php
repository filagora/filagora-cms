<?php

declare(strict_types=1);

namespace Filagora\Cms\Http\Middleware;

use Closure;
use Filagora\Cms\Preview\PreviewSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a browser is previewing unpublished menus or pages, every page it loads can contain
 * draft content at a normal public URL. Make sure no shared cache stores such a response and no
 * crawler indexes it, whatever caching rules are added in front of the application later.
 */
class MarkPreviewResponsesUncacheable
{
    public function __construct(private readonly PreviewSession $preview) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->preview->isActive()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
