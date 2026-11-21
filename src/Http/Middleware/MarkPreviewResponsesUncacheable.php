<?php

declare(strict_types=1);

namespace Filagora\Cms\Http\Middleware;

use Closure;
use Filagora\Cms\Preview\PreviewSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response to a request that carries a preview token can contain draft content at a normal
 * public address. Make sure no shared cache stores it and no crawler indexes it, whatever caching
 * rules are added in front of the application later, and withhold the referrer so the token in
 * the address is not passed on to other sites.
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
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
