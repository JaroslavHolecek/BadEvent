<?php
/**
 * Stylesheets of every page — the app layout, login/registration and all print exports. Base styles
 * first, then the active theme (public/themes/<theme>/theme.css), which overrides main.css's CSS
 * variables. Every page with its own <head> must render this instead of linking main.css directly,
 * otherwise it would ignore the company theme.
 */
$__themeCss = theme_css_url();
?>
<link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
<?php if ($__themeCss !== null): ?>
<link rel="stylesheet" href="<?= \App\Core\View::e($__themeCss) ?>">
<?php endif; ?>
