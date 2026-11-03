<?php

declare(strict_types=1);

/**
 * Returns the absolute URL path for an application route.
 * Prepends APP_BASE so the app works when deployed in a subdirectory.
 *
 * Example (deployed at /TEST/BadEvent/public/):
 *   url('/login')   → '/TEST/BadEvent/public/login'
 *   url('/')        → '/TEST/BadEvent/public/'
 */
function url(string $path = '/'): string
{
    return APP_BASE . $path;
}

/**
 * Like url(), but for static files under public/ — appends a `?v=<mtime>` cache-buster
 * so browsers fetch a fresh copy whenever the file changes, instead of serving a stale
 * cached one indefinitely (there are no other cache-control headers set for static assets).
 */
function asset_url(string $path): string
{
    $file = __DIR__ . '/../../public' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '0';
    return url($path) . '?v=' . $version;
}

/**
 * URL of the organization logo — 'logo_path' from .envConfig/app.php: a path inside public/ (gets
 * asset_url()'s cache-buster) or a full http(s) URL used as is. Falls back to the bundled logo.
 */
function logo_url(): string
{
    $path = (string) (\App\Core\Config::load('app')['logo_path'] ?? '/assets/images/logo-min.svg');
    return preg_match('#^https?://#', $path) === 1 ? $path : asset_url('/' . ltrim($path, '/'));
}

/** Returns the current request path (without APP_BASE), for building "return here" redirects. */
function currentPath(): string
{
    $base = defined('APP_BASE') ? APP_BASE : '';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    return ($base !== '' && str_starts_with($path, $base))
        ? (substr($path, strlen($base)) ?: '/')
        : ($path ?: '/');
}

/**
 * Renders a permission-gated link: a real <a> when allowed, otherwise a
 * greyed-out, non-navigable <span> with a tooltip explaining why.
 */
function permLink(bool $allowed, string $href, string $label, string $class = 'btn', string $deniedTooltip = 'Nemáte oprávnění k této akci.'): string
{
    $label = \App\Core\View::e($label);
    if ($allowed) {
        return '<a href="' . \App\Core\View::e($href) . '" class="' . \App\Core\View::e($class) . '">' . $label . '</a>';
    }
    return '<span class="' . \App\Core\View::e($class) . ' btn-disabled" title="' . \App\Core\View::e($deniedTooltip) . '">' . $label . '</span>';
}

/** Standard "insufficient permission" notice for a page section whose heading stays visible. */
function permDenied(string $text = 'Nemáte oprávnění zobrazit tento obsah.'): string
{
    return '<p class="perm-denied">' . \App\Core\View::e($text) . '</p>';
}
