<?php

declare(strict_types=1);

namespace Filagora\Cms\Http\Middleware;

use Closure;
use Filagora\Cms\Preview\PreviewSession;
use Filagora\Cms\Preview\PreviewUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * While a request carries a preview token, keep it on the way through the site: links to other
 * pages of this site in the HTML, and redirects within it, get the token too. That is what lets a
 * draft menu, or a draft page that other pages link to, be followed from page to page with
 * nothing stored in a session. Links to other sites, in-page anchors, files and the staff area are
 * left alone, and so is any request without a token.
 */
class CarryPreviewToken
{
    private const FILE_EXTENSIONS = 'css|js|map|png|jpe?g|gif|svg|webp|avif|ico|pdf|zip|gz|woff2?|ttf|eot|xml|json|txt|mp4|webm';

    public function __construct(private readonly PreviewSession $preview) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $token = $this->preview->token();

        if ($token === null || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        if ($response instanceof RedirectResponse) {
            $target = $response->getTargetUrl();

            if ($this->isOnThisSite($target, $request)) {
                $response->setTargetUrl(PreviewUrl::with($target, $token));
            }

            return $response;
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $html = $response->getContent();

            if (is_string($html) && $html !== '') {
                $response->setContent($this->carry($html, $token, $request));
            }
        }

        return $response;
    }

    private function carry(string $html, string $token, Request $request): string
    {
        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\shref=)(["\'])(.*?)\2/is',
            function (array $match) use ($token, $request): string {
                $href = $match[3];

                return $this->shouldCarry($href, $request)
                    ? $match[1].$match[2].$this->withToken($href, $token).$match[2]
                    : $match[0];
            },
            $html,
        );
    }

    private function shouldCarry(string $href, Request $request): bool
    {
        $decoded = html_entity_decode($href);

        if ($decoded === '' || $decoded[0] === '#' || ! $this->isOnThisSite($decoded, $request) || PreviewUrl::has($decoded)) {
            return false;
        }

        $path = (string) parse_url($decoded, PHP_URL_PATH);
        $skipped = (array) config('filagora-cms.preview.carry_token_except', []);

        foreach ($skipped as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim((string) $prefix, '/').'/')) {
                return false;
            }
        }

        return preg_match('/\.('.self::FILE_EXTENSIONS.')$/i', $path) !== 1;
    }

    private function withToken(string $href, string $token): string
    {
        $with = PreviewUrl::with(html_entity_decode($href), $token);

        return htmlspecialchars($with, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * A relative address, or an absolute one on this request's host.
     */
    private function isOnThisSite(string $url, Request $request): bool
    {
        if (preg_match('#^(mailto|tel|javascript|data|sms):#i', $url) === 1 || str_starts_with($url, '//')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === false || strcasecmp($host, $request->getHost()) === 0;
    }
}
