<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers.php';

loadEnv(dirname(__DIR__) . '/.env');

date_default_timezone_set('Africa/Lagos');

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('APP_NAME', (string) env('APP_NAME', 'VibrantHub'));
