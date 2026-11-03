<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Přihlášení – Správa akcí</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
</head>
<body class="login-page">

<div class="login-container">
    <h1 class="login-title">Správa akcí SVKKL</h1>

    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert-error"><?= \App\Core\View::e($flash['error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert-success"><?= \App\Core\View::e($flash['success']) ?></div>
    <?php endif; ?>

    <div class="login-tabs">
        <button class="tab-btn active" data-tab="password">Heslo</button>
        <button class="tab-btn" data-tab="link">Odkaz na email</button>
    </div>

    <div id="tab-password" class="tab-panel active">
        <form method="POST" action="<?= url('/login') ?>">
            <input type="hidden" name="method" value="password">
            <div class="form-group">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Přihlásit se</button>
        </form>
    </div>

    <div id="tab-link" class="tab-panel">
        <form method="POST" action="<?= url('/login') ?>">
            <input type="hidden" name="method" value="link">
            <div class="form-group">
                <label for="email-link">E-mail</label>
                <input type="email" id="email-link" name="email" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Odeslat přihlašovací odkaz</button>
        </form>
        <p class="hint">Na váš e-mail bude odeslán jednorázový přihlašovací odkaz.</p>
    </div>
    <?php /* Veřejná registrace byla zrušena — nové účty zakládá administrátor v sekci Uživatelé, role a oprávnění.
    <p class="login-alt-link">Nemáte účet? <a href="<?= url('/register') ?>">Vytvořit účet</a></p>
    */ ?>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

// Keep the email in sync between the two login methods' separate fields, so switching
// tabs after typing it once doesn't lose it.
const emailPassword = document.getElementById('email');
const emailLink = document.getElementById('email-link');
[[emailPassword, emailLink], [emailLink, emailPassword]].forEach(([source, target]) => {
    source.addEventListener('input', () => { target.value = source.value; });
});
</script>
</body>
</html>
