<?php
$__authed = \App\Core\Auth::check();
$__userId = $__authed ? \App\Core\Auth::userId() : null;

$__headerInfo     = null;
$__activeRole     = null;
$__otherRoles     = [];
$__canManageUsers = false;
$__canManageDictionaries = false;
$__canViewPeople  = false;
$__canExport      = false;
$__displayName    = null;
$__noPermission   = false;

if ($__authed) {
    $__perm           = new \App\Services\PermissionService();
    $__headerInfo     = (new \App\Repositories\UserRepository())->findHeaderInfo($__userId);
    $__activeRole     = $__perm->activeRole($__userId);
    $__myRoles        = $__perm->userRoles($__userId);
    $__otherRoles     = array_values(array_filter(
        $__myRoles,
        fn($r) => (int) $r['id'] !== (int) ($__activeRole['id'] ?? 0)
    ));
    $__allowedOps     = $__perm->allowedOperations($__userId);
    $__canManageUsers = in_array('users.manage', $__allowedOps, true);
    $__canManageDictionaries = in_array('dictionaries.manage', $__allowedOps, true);
    $__canViewPeople  = in_array('person.view', $__allowedOps, true);
    $__canExport      = $__perm->isAdmin($__userId);
    $__displayName    = $__headerInfo ? ($__headerInfo['full_name'] ?: $__headerInfo['email']) : null;
    // A user with no role (or a role granting no operations at all) can't meaningfully use any
    // page — show a single explanatory notice instead of pages silently rendering as empty.
    // The profile page stays reachable so they can still manage their own account while waiting.
    $__noPermission   = empty($__allowedOps) && !str_starts_with(currentPath(), '/profile');
}

// Marks a nav link "active" (stays darkened, same as :hover) when it's the section the current
// page belongs to. '/' only matches the exact home page — every path starts with '/', so a
// prefix match there would light up the whole menu.
$__navActive = function (string $path): string {
    $current = currentPath();
    $isActive = $path === '/' ? $current === '/' : str_starts_with($current, $path);
    return $isActive ? ' active' : '';
};
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= \App\Core\View::e($title ?? 'Bad Event') ?> – Správa akcí</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <script src="<?= asset_url('/assets/js/main.js') ?>"></script>
    <script src="<?= asset_url('/assets/js/confirm-delete.js') ?>"></script>
</head>
<body>

<header class="site-header">
    <nav class="nav-main">
        <a href="<?= url('/') ?>" class="nav-logo">
            <!-- <span class="nav-logo-text">Bad<br>Event</span> -->
            <img src="<?= \App\Core\View::e(logo_url()) ?>" alt="Logo organizace" class="nav-logo-img">
        </a>
        <ul class="nav-links">
            <li><a href="<?= url('/') ?>" class="<?= ltrim($__navActive('/')) ?>">Můj přehled</a></li>
            <li><a href="<?= url('/actions') ?>" class="<?= ltrim($__navActive('/actions')) ?>">Kalendář akcí</a></li>
            <li><a href="<?= url('/calendar/places') ?>" class="<?= ltrim($__navActive('/calendar/places')) ?>">Kalendář místností</a></li>
            <li><a href="<?= url('/events') ?>" class="<?= ltrim($__navActive('/events')) ?>">Události</a></li>
            <?php if ($__canViewPeople): ?>
            <li><a href="<?= url('/people') ?>" class="<?= ltrim($__navActive('/people')) ?>">Osoby</a></li>
            <?php else: ?>
            <li><span class="nav-link-disabled" title="Nemáte oprávnění: Zobrazit osoby a kontakty">Osoby</span></li>
            <?php endif; ?>
            <?php if ($__canExport): ?>
            <li><a href="<?= url('/export') ?>" class="<?= ltrim($__navActive('/export')) ?>">Export</a></li>
            <?php else: ?>
            <li><span class="nav-link-disabled" title="Tato sekce je dostupná pouze administrátorovi.">Export</span></li>
            <?php endif; ?>
            <?php if ($__canManageUsers): ?>
            <li><a href="<?= url('/admin/roles') ?>" class="<?= ltrim($__navActive('/admin/roles')) ?>">Uživatelé, role a oprávnění</a></li>
            <?php else: ?>
            <li><span class="nav-link-disabled" title="Nemáte oprávnění: Spravovat uživatele">Uživatelé, role a oprávnění</span></li>
            <?php endif; ?>
            <?php if ($__canManageDictionaries): ?>
            <li><a href="<?= url('/admin/dictionaries') ?>" class="<?= ltrim($__navActive('/admin/dictionaries')) ?>">Číselníky a místnosti</a></li>
            <?php else: ?>
            <li><span class="nav-link-disabled" title="Nemáte oprávnění: Spravovat číselníky">Číselníky a místnosti</span></li>
            <?php endif; ?>
        </ul>
        <div class="nav-user">
            <?php if ($__authed): ?>
                <details class="user-menu">
                    <summary class="user-name"><?= \App\Core\View::e($__displayName ?? ('Uživatel #' . $__userId)) ?></summary>
                    <ul class="role-options">
                        <li><a href="<?= url('/profile') ?>" class="role-option-btn">Upravit profil</a></li>
                        <li><a href="<?= url('/logout') ?>" class="role-option-btn">Odhlásit</a></li>
                    </ul>
                </details>
                <?php if (!empty($__otherRoles)): ?>
                <details class="role-switcher">
                    <summary class="role-current"><?= \App\Core\View::e($__activeRole['name'] ?? '—') ?></summary>
                    <ul class="role-options">
                        <?php foreach ($__otherRoles as $r): ?>
                        <li>
                            <form method="POST" action="<?= url('/role/switch/' . (int) $r['id']) ?>">
                                <input type="hidden" name="return_to" value="<?= \App\Core\View::e(currentPath()) ?>">
                                <button type="submit" class="role-option-btn"><?= \App\Core\View::e($r['name']) ?></button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php elseif ($__activeRole !== null): ?>
                <span class="role-current role-current-solo"><?= \App\Core\View::e($__activeRole['name']) ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>
</header>

<main class="site-main">
    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert-error"><?= \App\Core\View::e($flash['error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert-success"><?= \App\Core\View::e($flash['success']) ?></div>
    <?php endif; ?>

    <?php if ($__noPermission): ?>
        <div class="alert alert-error">Nemáte požadované oprávnění pro zobrazení - požádejte o něj admina aplikace.</div>
    <?php else: ?>
        <?= $content ?>
    <?php endif; ?>
</main>

<?php // Rejected form: values to refill + places to highlight, read by initFormFeedback() in main.js. ?>
<script type="application/json" id="form-feedback"><?= json_encode(
    ['config' => (\App\Core\Config::load('app')['form_feedback'] ?? []) + ['scroll_delay_ms' => 1000, 'scroll_duration_ms' => 450]]
        + ($flash['form'] ?? []),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
) ?></script>

<footer class="site-footer">
    <p>🄯 Bad Event <?= date('Y') ?> — <a href="https://badit.cz">badit.cz</a></p>
</footer>

</body>
</html>
