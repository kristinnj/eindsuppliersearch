<?php

declare(strict_types=1);
require dirname(__DIR__) . '/Database/bootstrap.php';
require_once __DIR__ . '/Guard.php';
M1DisposableGuard::assertSafe($GLOBALS['offer_binding_test_pdo']);
