<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$pdo = db();
$currency = get_setting('currency_symbol', 'RM') ?? 'RM';

$total = (int) $pdo->query('SELECT COUNT(*) FROM parking_logs')->fetchColumn();
$active = (int) $pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'ACTIVE'")->fetchColumn();
$completed = (int) $pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'COMPLETED'")->fetchColumn();

if (db_is_sqlite($pdo)) {
    $todayCheckIns = (int) $pdo->query(
        "SELECT COUNT(*) FROM parking_logs WHERE date(entry_time) = date('now', 'localtime')"
    )->fetchColumn();
    $todayCheckOuts = (int) $pdo->query(
        "SELECT COUNT(*) FROM parking_logs WHERE status = 'COMPLETED' AND date(exit_time) = date('now', 'localtime')"
    )->fetchColumn();
    $todayRevenue = (float) $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) FROM parking_logs
         WHERE status = 'COMPLETED' AND date(exit_time) = date('now', 'localtime')"
    )->fetchColumn();
} else {
    $todayCheckIns = (int) $pdo->query(
        "SELECT COUNT(*) FROM parking_logs WHERE DATE(entry_time) = CURDATE()"
    )->fetchColumn();
    $todayCheckOuts = (int) $pdo->query(
        "SELECT COUNT(*) FROM parking_logs WHERE status = 'COMPLETED' AND DATE(exit_time) = CURDATE()"
    )->fetchColumn();
    $todayRevenue = (float) $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) FROM parking_logs
         WHERE status = 'COMPLETED' AND DATE(exit_time) = CURDATE()"
    )->fetchColumn();
}

$activeList = $pdo->query(
    "SELECT id, plate_number, entry_time FROM parking_logs
     WHERE status = 'ACTIVE' ORDER BY entry_time DESC LIMIT 30"
)->fetchAll();

$recent = $pdo->query(
    "SELECT id, plate_number, entry_time, exit_time, duration_minutes, total_amount, status
     FROM parking_logs ORDER BY id DESC LIMIT 15"
)->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/_layout_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="h3 mb-0">Dashboard</h1>
    <div class="text-secondary small">Overview of parking activity</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary" href="<?= e(url('admin/entry.php')) ?>">Scan Plate / Check-in</a>
    <a class="btn btn-outline-primary" href="<?= e(url('admin/exit.php')) ?>">Exit / Payment</a>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/history.php')) ?>">Check-in Records</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">Active Now</div>
      <div class="stat-value text-primary"><?= $active ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">Today Check-ins</div>
      <div class="stat-value"><?= $todayCheckIns ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">Today Check-outs</div>
      <div class="stat-value"><?= $todayCheckOuts ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">Completed</div>
      <div class="stat-value"><?= $completed ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">All Sessions</div>
      <div class="stat-value"><?= $total ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-2">
    <div class="card stat-card"><div class="card-body">
      <div class="text-secondary small">Today Revenue</div>
      <div class="stat-value text-success" style="font-size:1.2rem"><?= e(format_money($todayRevenue, $currency)) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold">Currently Parked (<?= $active ?>)</div>
      <div class="table-responsive" style="max-height:420px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Plate</th><th>Check-in Time</th></tr></thead>
          <tbody>
          <?php if (!$activeList): ?>
            <tr><td colspan="2" class="text-secondary p-3">No vehicles parked.</td></tr>
          <?php else: foreach ($activeList as $row): ?>
            <tr>
              <td class="fw-semibold"><?= e($row['plate_number']) ?></td>
              <td><?= e($row['entry_time']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between">
        <span class="fw-semibold">Recent Check-in Records</span>
        <a href="<?= e(url('admin/history.php')) ?>" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead>
            <tr>
              <th>Plate</th>
              <th>Check-in</th>
              <th>Check-out</th>
              <th>Duration</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="6" class="text-secondary p-3">No records yet.</td></tr>
          <?php else: foreach ($recent as $row): ?>
            <tr>
              <td class="fw-semibold"><?= e($row['plate_number']) ?></td>
              <td><?= e($row['entry_time']) ?></td>
              <td><?= e($row['exit_time'] ?? '—') ?></td>
              <td><?= $row['duration_minutes'] !== null ? e(format_duration((int) $row['duration_minutes'])) : '—' ?></td>
              <td><?= $row['total_amount'] !== null ? e(format_money((float) $row['total_amount'], $currency)) : '—' ?></td>
              <td>
                <span class="badge text-bg-<?= $row['status'] === 'ACTIVE' ? 'primary' : 'success' ?>">
                  <?= e($row['status']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_layout_footer.php'; ?>
