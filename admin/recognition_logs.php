<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$totalRows = (int) db()->query('SELECT COUNT(*) FROM ai_recognition_logs')->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$stmt = db()->query(
    "SELECT * FROM ai_recognition_logs ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$rows = $stmt->fetchAll();

$pageTitle = 'AI Recognition Logs';
$activeNav = 'ai_logs';
require __DIR__ . '/_layout_header.php';
?>
<h1 class="h3 mb-3">AI Recognition Logs</h1>
<p class="text-secondary">API keys and secrets are never stored in these logs.</p>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Time</th>
          <th>Request ID</th>
          <th>Provider</th>
          <th>Model</th>
          <th>Fallback</th>
          <th>HTTP</th>
          <th>Plate</th>
          <th>Confidence</th>
          <th>Error</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-secondary p-3">No recognition logs yet.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td class="text-nowrap"><?= e($row['created_at']) ?></td>
          <td><code class="small"><?= e($row['request_id']) ?></code></td>
          <td><?= e($row['provider']) ?></td>
          <td><?= e($row['model'] ?? '—') ?></td>
          <td><?= !empty($row['fallback_used']) ? 'Yes' : 'No' ?></td>
          <td><?= e((string) ($row['http_status'] ?? '—')) ?></td>
          <td><?= e($row['recognized_plate'] ?? '—') ?></td>
          <td><?= e($row['confidence'] ?? '—') ?></td>
          <td class="small text-danger"><?= e($row['error_message'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination">
  <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
    </li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php require __DIR__ . '/_layout_footer.php'; ?>
