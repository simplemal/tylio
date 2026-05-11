<?php
declare(strict_types=1);

/**
 * tylio — single front controller.
 * All HTTP traffic enters here. Routing is handled by Slim.
 */

require __DIR__ . '/vendor/autoload.php';

$app = (require __DIR__ . '/app/bootstrap.php')();
$app->run();