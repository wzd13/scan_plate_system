<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

logout_admin();
header('Location: ' . url('admin/login.php'));
exit;
