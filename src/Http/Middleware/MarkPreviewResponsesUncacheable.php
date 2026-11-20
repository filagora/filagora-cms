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
 * crawler indexes it, whatever caching rules are added in front of the application later. The
 * same goes for a request that asks for the live site beside a preview (`?preview=off`), which
 * is a duplicate of a public address. Referrers are withheld so a token in the address is not
 * passed to other sites.
 */
class MarkPreviewResponsesUncacheable
{
    public function __construct(private readonly PreviewSession $preview) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->preview->isActive() || $this->preview->isViewingLive()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
