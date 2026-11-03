<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrace – Bad Event</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
</head>
<body class="login-page">

<div class="login-container">
    <h1 class="login-title">Bad Event</h1>
    <p class="login-subtitle">Vytvoření účtu</p>

    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert-error"><?= \App\Core\View::e($flash['error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert-success"><?= \App\Core\View::e($flash['success']) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/register') ?>">
        <div class="form-group">
            <label for="full_name">Celé jméno</label>
            <input type="text" id="full_name" name="full_name" required autofocus>
        </div>
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="password">Heslo <span class="hint-inline">(nepovinné – bez hesla lze přihlásit odkazem)</span></label>
            <input type="password" id="password" name="password">
        </div>
        <div class="form-group">
            <label for="password_confirm">Heslo znovu</label>
            <input type="password" id="password_confirm" name="password_confirm">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Vytvořit účet</button>
    </form>

    <p class="login-alt-link">Již máte účet? <a href="<?= url('/login') ?>">Přihlásit se</a></p>
</div>

</body>
</html>
