<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$settings = get_settings([
    'pricing_mode', 'flat_rate', 'hourly_rate', 'grace_period', 'round_up_hours', 'currency_symbol',
]);
$csrf = csrf_token();
$apiBase = url('api');

$pageTitle = 'Billing Settings';
$activeNav = 'billing';
require __DIR__ . '/_layout_header.php';
?>
<h1 class="h3 mb-3">Billing Settings</h1>
<p class="text-secondary">All payment amounts are recalculated on the server during checkout.</p>

<div id="alertBox" class="alert d-none"></div>

<form id="billingForm" class="card border-0 shadow-sm">
  <div class="card-body row g-3">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="section" value="billing">

    <div class="col-md-4">
      <label class="form-label">Pricing Mode</label>
      <select class="form-select" name="pricing_mode" required>
        <option value="hourly" <?= ($settings['pricing_mode'] ?? '') === 'hourly' ? 'selected' : '' ?>>Hourly Rate</option>
        <option value="flat" <?= ($settings['pricing_mode'] ?? '') === 'flat' ? 'selected' : '' ?>>Flat Rate</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Flat Rate</label>
      <input type="number" step="0.01" min="0" class="form-control" name="flat_rate" value="<?= e($settings['flat_rate'] ?? '5.00') ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Hourly Rate</label>
      <input type="number" step="0.01" min="0" class="form-control" name="hourly_rate" value="<?= e($settings['hourly_rate'] ?? '2.00') ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Grace Period (minutes)</label>
      <input type="number" min="0" class="form-control" name="grace_period" value="<?= e($settings['grace_period'] ?? '15') ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Round Up Hours</label>
      <select class="form-select" name="round_up_hours">
        <option value="1" <?= ($settings['round_up_hours'] ?? '1') === '1' ? 'selected' : '' ?>>Yes</option>
        <option value="0" <?= ($settings['round_up_hours'] ?? '1') === '0' ? 'selected' : '' ?>>No</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Currency Symbol</label>
      <input class="form-control" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? 'RM') ?>" maxlength="10" required>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary">Save Billing Settings</button>
    </div>
  </div>
</form>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h6">Hourly billing example</h2>
    <p class="mb-0 small text-secondary">
      Duration 2h 10m, grace 15m, round up → billable 1h 55m → 2 hours × hourly rate.
    </p>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('billingForm');
  const alertBox = document.getElementById('alertBox');
  const apiBase = <?= json_encode($apiBase) ?>;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(form).entries());
    const res = await fetch(apiBase + '/settings.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf_token, Accept: 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    alertBox.className = 'alert alert-' + (data.success ? 'success' : 'danger');
    alertBox.textContent = data.message || 'Done';
    alertBox.classList.remove('d-none');
  });
})();
</script>
<?php require __DIR__ . '/_layout_footer.php'; ?>
