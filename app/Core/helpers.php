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
 * Visual themes live in public/themes/<name>/ — each holds theme.css (overrides the CSS variables of
 * main.css: company colors etc.) and logo.svg. 'demo' is the bundled showcase theme (in git, used by
 * tests); a company's own theme folder exists only on its server (gitignored, like .envConfig/).
 */
const DEFAULT_THEME = 'demo';

/**
 * Active theme: 'theme' from .envConfig/app.php. An unsafe name (anything but letters, digits, '-', '_')
 * or a folder that doesn't exist falls back to the demo theme, so a config typo never breaks the look.
 * $config / $publicDir are for tests only.
 */
function theme_name(?array $config = null, ?string $publicDir = null): string
{
    $config    ??= \App\Core\Config::load('app');
    $publicDir ??= __DIR__ . '/../../public';
    $name = (string) ($config['theme'] ?? DEFAULT_THEME);
    if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1 || !is_dir($publicDir . '/themes/' . $name)) {
        return DEFAULT_THEME;
    }
    return $name;
}

/**
 * Public path ('/themes/<name>/<file>') of a file of the active theme; a file the theme doesn't have is
 * taken from the demo theme instead (so a company theme may hold only what it changes). Null when
 * neither has it.
 */
function theme_file(string $file, ?array $config = null, ?string $publicDir = null): ?string
{
    $publicDir ??= __DIR__ . '/../../public';
    foreach (array_unique([theme_name($config, $publicDir), DEFAULT_THEME]) as $theme) {
        $path = '/themes/' . $theme . '/' . $file;
        if (is_file($publicDir . $path)) {
            return $path;
        }
    }
    return null;
}

/** URL (with cache-buster) of the active theme's stylesheet, or null when there is none. */
function theme_css_url(): ?string
{
    $path = theme_file('theme.css');
    return $path === null ? null : asset_url($path);
}

/** URL (with cache-buster) of the organization logo — logo.svg of the active theme (header + exports). */
function logo_url(): string
{
    return asset_url(theme_file('logo.svg') ?? '/themes/' . DEFAULT_THEME . '/logo.svg');
}

/**
 * Application name: 'app_name' from .envConfig/app.php — page titles, login/registration heading.
 * 'Bad Event' (the product name, also in the footer) when unset.
 */
function app_name(): string
{
    $name = trim((string) (\App\Core\Config::load('app')['app_name'] ?? ''));
    return $name !== '' ? $name : 'Bad Event';
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
