<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_login();

$settings = get_settings([
    'ai_provider', 'ai_model', 'ai_fallback_models', 'ai_api_url', 'ai_api_key',
    'ai_confidence_auto', 'ai_confidence_verify', 'scan_interval_seconds',
]);
$masked = mask_api_key($settings['ai_api_key'] ?? '');
$keySet = ($settings['ai_api_key'] ?? '') !== '';
$csrf = csrf_token();
$apiBase = url('api');

$pageTitle = 'AI Settings';
$activeNav = 'ai';
require __DIR__ . '/_layout_header.php';
?>
<h1 class="h3 mb-3">AI Settings</h1>
<p class="text-secondary">
  Agnes AI (OpenAI-compatible) configuration.
  Keys are managed at <a href="https://platform.agnes-ai.com/settings/apiKeys" target="_blank" rel="noopener">platform.agnes-ai.com</a>.
  The API key stays server-side and is never shown in full after saving.
</p>

<div id="alertBox" class="alert d-none"></div>

<form id="aiForm" class="card border-0 shadow-sm">
  <div class="card-body row g-3">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="section" value="ai">

    <div class="col-md-6">
      <label class="form-label">AI Provider</label>
      <input class="form-control" name="ai_provider" value="<?= e($settings['ai_provider'] ?? 'Agnes AI') ?>" required>
      <div class="form-text">Use “Agnes AI” for OpenAI-compatible API, or “Google Gemini Vision” for Gemini.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Primary Model</label>
      <input class="form-control" name="ai_model" value="<?= e($settings['ai_model'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Fallback Models</label>
      <input class="form-control" name="ai_fallback_models" value="<?= e($settings['ai_fallback_models'] ?? '') ?>" placeholder="comma-separated">
      <div class="form-text">Tried in order if the primary model fails.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">API URL</label>
      <input class="form-control" name="ai_api_url" value="<?= e($settings['ai_api_url'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">API Key</label>
      <input class="form-control" name="ai_api_key" type="password" value="" placeholder="<?= $keySet ? e($masked ?: '************') : 'Enter API key' ?>" autocomplete="new-password">
      <div class="form-text"><?= $keySet ? 'Key is saved. Leave blank to keep the current key.' : 'No key saved yet.' ?></div>
    </div>
    <div class="col-md-3">
      <label class="form-label">Auto-continue confidence</label>
      <select class="form-select" name="ai_confidence_auto">
        <?php foreach (['HIGH', 'MEDIUM', 'LOW'] as $c): ?>
          <option value="<?= $c ?>" <?= ($settings['ai_confidence_auto'] ?? 'HIGH') === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Verify confidence</label>
      <select class="form-select" name="ai_confidence_verify">
        <?php foreach (['HIGH', 'MEDIUM', 'LOW'] as $c): ?>
          <option value="<?= $c ?>" <?= ($settings['ai_confidence_verify'] ?? 'MEDIUM') === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Scan interval (seconds)</label>
      <input type="number" min="2" max="60" class="form-control" name="scan_interval_seconds" value="<?= e($settings['scan_interval_seconds'] ?? '4') ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="button" id="btnTest" class="btn btn-outline-secondary">Test AI Connection</button>
    </div>
  </div>
</form>

<pre id="testResult" class="mt-3 p-3 bg-dark text-light rounded small d-none"></pre>

<script>
(function () {
  const form = document.getElementById('aiForm');
  const alertBox = document.getElementById('alertBox');
  const testResult = document.getElementById('testResult');
  const apiBase = <?= json_encode($apiBase) ?>;

  function showAlert(ok, msg) {
    alertBox.className = 'alert alert-' + (ok ? 'success' : 'danger');
    alertBox.textContent = msg;
    alertBox.classList.remove('d-none');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const body = Object.fromEntries(fd.entries());
    if (!body.ai_api_key) delete body.ai_api_key;
    const res = await fetch(apiBase + '/settings.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf_token, Accept: 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    showAlert(!!data.success, data.message || 'Done');
  });

  document.getElementById('btnTest').addEventListener('click', async () => {
    testResult.classList.remove('d-none');
    testResult.textContent = 'Testing...';
    const token = form.querySelector('[name=csrf_token]').value;
    const res = await fetch(apiBase + '/test_ai_connection.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, Accept: 'application/json' },
      body: JSON.stringify({ csrf_token: token }),
    });
    const data = await res.json();
    testResult.textContent = JSON.stringify(data, null, 2);
    showAlert(!!data.success, data.message || (data.success ? 'Connection successful' : 'Connection failed'));
  });
})();
</script>
<?php require __DIR__ . '/_layout_footer.php'; ?>
