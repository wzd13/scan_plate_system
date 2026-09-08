<?php
declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $activeNav */

if (!isset($pageTitle)) {
    $pageTitle = 'Admin';
}
if (!isset($activeNav)) {
    $activeNav = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> — Smart Parking</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="admin-body" data-api-base="<?= e(url('api')) ?>">
<div class="container-fluid">
  <div class="row">
    <aside class="col-md-3 col-lg-2 admin-sidebar p-3">
      <div class="fw-bold mb-1">Smart Parking</div>
      <div class="small text-secondary mb-3">Admin Panel</div>
      <nav class="d-grid gap-1">
        <div class="small text-uppercase text-secondary px-2 mt-1 mb-1">Operations</div>
        <a class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('admin/index.php')) ?>">Dashboard</a>
        <a class="<?= $activeNav === 'entry' ? 'active' : '' ?>" href="<?= e(url('admin/entry.php')) ?>">Scan Plate / Check-in</a>
        <a class="<?= $activeNav === 'exit' ? 'active' : '' ?>" href="<?= e(url('admin/exit.php')) ?>">Exit / Payment</a>
        <a class="<?= $activeNav === 'history' ? 'active' : '' ?>" href="<?= e(url('admin/history.php')) ?>">Check-in Records</a>

        <div class="small text-uppercase text-secondary px-2 mt-3 mb-1">Settings</div>
        <a class="<?= $activeNav === 'ai' ? 'active' : '' ?>" href="<?= e(url('admin/ai_settings.php')) ?>">AI Settings</a>
        <a class="<?= $activeNav === 'billing' ? 'active' : '' ?>" href="<?= e(url('admin/billing_settings.php')) ?>">Billing Settings</a>
        <a class="<?= $activeNav === 'ai_logs' ? 'active' : '' ?>" href="<?= e(url('admin/recognition_logs.php')) ?>">AI Logs</a>

        <hr class="border-secondary">
        <a href="<?= e(url('admin/logout.php')) ?>">Logout</a>
      </nav>
      <div class="small text-secondary mt-4">Signed in as <?= e(current_admin_username()) ?></div>
    </aside>
    <main class="col-md-9 col-lg-10 p-4">
