<?php

declare(strict_types=1);

// Test database — it is DROPPED AND RECREATED on every test run (tests/bootstrap.php refuses to
// touch a database whose name doesn't end with "_test"). Host/port can be overridden by env
// variables: Docker Compose sets TEST_DB_HOST=db, the "only PostgreSQL in Docker" variant uses port 54329.
return [
    'driver' => 'pgsql',
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('TEST_DB_PORT') ?: 5432),
    'database' => 'badevent_test',
    'username' => 'badevent_test',
    'password' => 'badevent_test',
];
