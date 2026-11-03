<?php
declare(strict_types=1);

$app_config = require __DIR__ . '/app.php'; // Some values are redundant, but we do not want to break BadLogin expected values here

$config = [
    'app_name'  => $app_config['app_name'],
    'base_url'  => $app_config['base_url'],
    'mail_from' => $app_config['mail_from'],

    'mail_subject_linktoken_login' => 'Login link – Bad Event Manager',
    'mail_body_linktoken_login'    => '<p>Hi,<br>for log-in to {app_name} use this link:<br><a href="{linktoken_login_url}">{linktoken_login_url}</a><br>Link expires in 15 minute.</p>',

    '_linktoken_login_filepath'           => '/login',
    '_table_login'                        => 'bl_login',
    '_session_key_user_id'                => 'user_id',
    '_linktoken_hash_algo'                => 'sha256',
    '_linktoken_bytes'                    => 32,
    '_linktoken_ttl_minutes'              => 15, // see mail_body_linktoken_login text too and synchronize
    '_linktoken_create_user_if_missing'   => false,
    '_linktoken_get_key'                  => 'token',
];

return $config;
