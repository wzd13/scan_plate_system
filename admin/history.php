<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$q = trim((string) ($_GET['q'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = 'plate_number LIKE :q';
    $params['q'] = '%' . normalize_plate($q) . '%';
}
if (in_array($status, ['ACTIVE', 'COMPLETED'], true)) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'DATE(entry_time) >= :from';
    $params['from'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'DATE(entry_time) <= :to';
    $params['to'] = $to;
}

$sqlWhere = implode(' AND ', $where);
$countStmt = db()->prepare("SELECT COUNT(*) FROM parking_logs WHERE {$sqlWhere}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$stmt = db()->prepare(
    "SELECT * FROM parking_logs WHERE {$sqlWhere} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$currency = get_setting('currency_symbol', 'RM') ?? 'RM';

$pageTitle = 'Check-in Records';
$activeNav = 'history';
require __DIR__ . '/_layout_header.php';
?>
<h1 class="h3 mb-3">Check-in Records</h1>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-3">
    <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search plate">
  </div>
  <div class="col-md-2">
    <select name="status" class="form-select">
      <option value="">All statuses</option>
      <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
      <option value="COMPLETED" <?= $status === 'COMPLETED' ? 'selected' : '' ?>>COMPLETED</option>
    </select>
  </div>
  <div class="col-md-2"><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
  <div class="col-md-2"><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Plate</th>
          <th>Entry</th>
          <th>Exit</th>
          <th>Duration</th>
          <th>Pricing</th>
          <th>Rate</th>
          <th>Total</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-secondary p-3">No records found.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td class="fw-semibold"><?= e($row['plate_number']) ?></td>
          <td><?= e($row['entry_time']) ?></td>
          <td><?= e($row['exit_time'] ?? '—') ?></td>
          <td><?= $row['duration_minutes'] !== null ? e(format_duration((int)$row['duration_minutes'])) : '—' ?></td>
          <td><?= e($row['pricing_type'] ?? '—') ?></td>
          <td><?= $row['rate_applied'] !== null ? e(format_money((float)$row['rate_applied'], $currency)) : '—' ?></td>
          <td><?= $row['total_amount'] !== null ? e(format_money((float)$row['total_amount'], $currency)) : '—' ?></td>
          <td><span class="badge text-bg-<?= $row['status'] === 'ACTIVE' ? 'primary' : 'success' ?>"><?= e($row['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/_layout_footer.php'; ?>
