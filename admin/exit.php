<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$pageTitle = 'Exit / Payment';
$activeNav = 'exit';
require __DIR__ . '/_layout_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-0">Exit / Payment</h1>
    <div class="text-secondary small">Auto scan + auto show amount (confirm payment to check out)</div>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/history.php')) ?>">Check-in Records</a>
</div>

<div class="gate-embed">
  <div class="camera-wrap mb-3" style="max-width:720px">
    <video id="camera" playsinline muted></video>
    <canvas id="snapshot"></canvas>
    <div class="scan-frame"></div>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <button type="button" id="btnStart" class="btn btn-primary">Start Camera</button>
    <button type="button" id="btnStop" class="btn btn-outline-secondary">Stop</button>
    <button type="button" id="btnScan" class="btn btn-info">Scan Plate</button>
    <button type="button" id="btnGetBill" class="btn btn-warning">Get Bill</button>
    <button type="button" id="btnPay" class="btn btn-success" disabled>Confirm Payment</button>
  </div>

  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" id="autoScan" checked>
    <label class="form-check-label" for="autoScan">Automatic exit mode — scan &amp; show amount continuously</label>
  </div>

  <div class="row g-3">
    <div class="col-md-5">
      <div class="mb-2 text-secondary small">Detected Plate</div>
      <div id="plateDisplay" class="plate-display text-dark mb-2">—</div>
      <div id="amountHero" class="d-none amount-hero amount-hero-light mb-3">—</div>
      <label class="form-label" for="plateInput">Manual Plate Input</label>
      <input type="text" id="plateInput" class="form-control form-control-lg mb-3" placeholder="e.g. ABC1234" maxlength="15" autocomplete="off">
      <div id="status" class="status-box info">Ready</div>
    </div>
    <div class="col-md-7">
      <div id="billPanel" class="d-none card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h2 class="h5 mb-3">Billing Details</h2>
          <dl class="row bill-grid mb-0">
            <dt class="col-5 text-secondary">Entry</dt><dd class="col-7" id="billEntry">—</dd>
            <dt class="col-5 text-secondary">Duration</dt><dd class="col-7" id="billDuration">—</dd>
            <dt class="col-5 text-secondary">Rate</dt><dd class="col-7" id="billRate">—</dd>
            <dt class="col-5 text-secondary">Total</dt><dd class="col-7 fs-3 fw-bold text-primary" id="billTotal">—</dd>
          </dl>
        </div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Checkout Records <span id="checkoutCountBadge" class="badge text-bg-success ms-1">0 today</span></span>
          <a class="small" href="<?= e(url('admin/history.php?status=COMPLETED')) ?>">View all</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr><th>Plate</th><th>Check-out</th><th>Amount</th><th>Status</th></tr>
            </thead>
            <tbody id="checkoutRecordsBody">
              <tr><td colspan="4" class="text-secondary">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<script src="<?= e(url('assets/js/exit.js')) ?>"></script>
<?php require __DIR__ . '/_layout_footer.php'; ?>
