<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$pageTitle = 'Scan Plate / Check-in';
$activeNav = 'entry';
require __DIR__ . '/_layout_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-0">Scan Plate / Check-in</h1>
    <div class="text-secondary small">Auto scan + auto check-in (camera starts automatically)</div>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/history.php')) ?>">Check-in Records</a>
</div>

<div class="gate-embed">
  <div class="camera-wrap mb-3">
    <video id="camera" playsinline muted></video>
    <canvas id="snapshot"></canvas>
    <div class="scan-frame"></div>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <button type="button" id="btnStart" class="btn btn-primary">Start Camera</button>
    <button type="button" id="btnStop" class="btn btn-outline-secondary">Stop</button>
    <button type="button" id="btnScan" class="btn btn-info">Scan Plate</button>
    <button type="button" id="btnCheckIn" class="btn btn-success">Check-In</button>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" id="autoScan" checked>
    <label class="form-check-label" for="autoScan">Automatic entry mode — scan &amp; check-in continuously</label>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="mb-2 text-secondary small">Detected Plate</div>
      <div id="plateDisplay" class="plate-display text-dark mb-3">—</div>
      <label class="form-label" for="plateInput">Manual Plate Input</label>
      <input type="text" id="plateInput" class="form-control form-control-lg mb-3" placeholder="e.g. ABC1234" maxlength="15" autocomplete="off">
      <div id="status" class="status-box info">Ready</div>
    </div>
    <div class="col-lg-7">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">Recent Check-ins <span id="activeCountBadge" class="badge text-bg-primary ms-1">0 active</span></div>
        <a class="small" href="<?= e(url('admin/history.php')) ?>">View all</a>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr><th>Plate</th><th>Check-in</th><th>Status</th></tr>
            </thead>
            <tbody id="recentRecordsBody">
              <tr><td colspan="3" class="text-secondary">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<script src="<?= e(url('assets/js/entry.js')) ?>"></script>
<?php require __DIR__ . '/_layout_footer.php'; ?>
