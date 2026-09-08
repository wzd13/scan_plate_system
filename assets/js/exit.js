/**
 * Exit Gate — auto scan + auto show bill/amount + checkout records.
 */
(function () {
  'use strict';

  const video = document.getElementById('camera');
  const canvas = document.getElementById('snapshot');
  const plateInput = document.getElementById('plateInput');
  const statusEl = document.getElementById('status');
  const plateDisplay = document.getElementById('plateDisplay');
  const billPanel = document.getElementById('billPanel');
  const autoToggle = document.getElementById('autoScan');
  const btnStart = document.getElementById('btnStart');
  const btnStop = document.getElementById('btnStop');
  const btnScan = document.getElementById('btnScan');
  const btnGetBill = document.getElementById('btnGetBill');
  const btnPay = document.getElementById('btnPay');
  const amountHero = document.getElementById('amountHero');

  const camera = new ParkingApp.CameraController(video, canvas);
  const guard = new ParkingApp.ScanGuard(20000);
  let scanInterval = 6000;
  let timer = null;
  let cameraOn = false;
  let csrf = '';
  let currentBill = null;
  let lastBilledPlate = '';
  let awaitingPayment = false;

  function showPlate(plate) {
    plateDisplay.textContent = plate || '—';
    if (plate) plateInput.value = plate;
  }

  function hideBill() {
    currentBill = null;
    lastBilledPlate = '';
    awaitingPayment = false;
    billPanel.classList.add('d-none');
    btnPay.disabled = true;
    if (amountHero) {
      amountHero.textContent = '—';
      amountHero.classList.add('d-none');
    }
  }

  function renderBill(data, paid) {
    currentBill = paid ? null : data;
    lastBilledPlate = (data.plate_number || '').toUpperCase();
    awaitingPayment = !paid;
    document.getElementById('billEntry').textContent = data.entry_time || '—';
    document.getElementById('billDuration').textContent = data.duration_label || '—';
    document.getElementById('billRate').textContent =
      (data.currency || 'RM') + ' ' + Number(data.rate_applied).toFixed(2) +
      (data.pricing_type === 'hourly' ? '/hour' : ' flat');
    document.getElementById('billTotal').textContent = data.amount_label || '—';
    billPanel.classList.remove('d-none');
    btnPay.disabled = !!paid;
    if (amountHero) {
      amountHero.textContent = data.amount_label || '—';
      amountHero.classList.remove('d-none');
    }
  }

  async function refreshCheckoutRecords() {
    const body = document.getElementById('checkoutRecordsBody');
    const badge = document.getElementById('checkoutCountBadge');
    if (!body) return;
    try {
      const res = await ParkingApp.api('recent_records.php?status=COMPLETED&limit=10');
      if (!res.success || !res.data) return;
      if (badge) {
        badge.textContent = (res.data.completed_today || 0) + ' today';
      }
      const rows = res.data.records || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4" class="text-secondary">No checkout records yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.map((r) => (
        '<tr>' +
          '<td class="fw-semibold">' + (r.plate_number || '') + '</td>' +
          '<td>' + (r.exit_time || '—') + '</td>' +
          '<td>' + (r.amount_label || '—') + '</td>' +
          '<td><span class="badge text-bg-success">' + (r.status || '') + '</span></td>' +
        '</tr>'
      )).join('');
    } catch (_) {
      /* ignore */
    }
  }

  async function loadConfig() {
    try {
      const res = await ParkingApp.api('public_config.php');
      if (res.success && res.data) {
        csrf = res.data.csrf_token || '';
        scanInterval = Math.max(5000, (res.data.scan_interval_seconds || 6) * 1000);
        return true;
      }
      ParkingApp.setStatus(statusEl, (res && res.message) || 'Unable to load config.', 'danger');
      return false;
    } catch (err) {
      ParkingApp.setStatus(statusEl, 'Unable to reach the server API.', 'danger');
      return false;
    }
  }

  async function startCamera() {
    try {
      await camera.start();
      cameraOn = true;
      ParkingApp.setStatus(statusEl, 'Camera ready. Auto scan starts shortly...', 'success');
      if (!autoToggle || autoToggle.checked) {
        stopAuto();
        timer = setTimeout(() => {
          scanAndShowBill(true).finally(() => {
            if ((!autoToggle || autoToggle.checked) && cameraOn) {
              startAuto(false);
            }
          });
        }, 1500);
      }
    } catch (err) {
      cameraOn = false;
      ParkingApp.setStatus(
        statusEl,
        err.message || 'Unable to access camera. Please allow camera permission or enter the plate manually.',
        'danger'
      );
    }
  }

  function stopCamera() {
    stopAuto();
    camera.stop();
    cameraOn = false;
    ParkingApp.setStatus(statusEl, 'Camera stopped', 'info');
  }

  function startAuto(runNow) {
    stopAuto();
    if (!cameraOn) return;

    const tick = async () => {
      if (autoToggle && !autoToggle.checked) return;
      if (!cameraOn) return;
      // Pause AI while bill is waiting for payment confirmation
      if (awaitingPayment && currentBill) {
        timer = setTimeout(tick, scanInterval);
        return;
      }
      await scanAndShowBill(true);
      if ((!autoToggle || autoToggle.checked) && cameraOn) {
        timer = setTimeout(tick, scanInterval);
      }
    };

    if (runNow) tick();
    else timer = setTimeout(tick, scanInterval);
  }

  function stopAuto() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  async function scanAndShowBill(autoMode) {
    if (guard.busy) return;
    if (!cameraOn) {
      ParkingApp.setStatus(statusEl, 'Start the camera first.', 'warning');
      return;
    }
    if (awaitingPayment && currentBill && autoMode) {
      return;
    }

    guard.setBusy(true);
    try {
      // Smaller JPEG = much faster upload to Agnes AI
      const dataUrl = camera.captureDataUrl(0.55, 640);
      ParkingApp.setStatus(statusEl, 'AI recognizing plate (may take a few seconds)...', 'info');
      const res = await ParkingApp.api('recognize_plate.php', {
        method: 'POST',
        body: { image: dataUrl, mime_type: 'image/jpeg' },
      });

      if (!res.success) {
        ParkingApp.setStatus(statusEl, res.message || 'AI recognition failed. Will retry...', 'danger');
        return;
      }

      const data = res.data || {};
      const plate = (data.plate_number || data.plate || '').toUpperCase();
      const action = data.action || 'reject';
      showPlate(plate);

      if (action === 'reject' || !data.valid_plate) {
        ParkingApp.setStatus(statusEl, res.message || 'No clear plate. Scanning again...', 'warning');
        return;
      }

      if (currentBill && lastBilledPlate === plate) {
        ParkingApp.setStatus(
          statusEl,
          'Plate ' + plate + ' — amount ' + (currentBill.amount_label || '') + '. Confirm payment.',
          'success'
        );
        return;
      }

      if (autoMode && !guard.canProcess(plate)) {
        ParkingApp.setStatus(statusEl, 'Plate ' + plate + ' processed recently. Waiting...', 'info');
        return;
      }

      ParkingApp.setStatus(statusEl, 'Plate detected: ' + plate + ' — loading bill...', 'success');
      await getBill(plate, autoMode);
    } catch (err) {
      ParkingApp.setStatus(statusEl, err.message || 'Scan failed.', 'danger');
    } finally {
      guard.setBusy(false);
    }
  }

  async function getBill(plate, fromAuto) {
    plate = (plate || plateInput.value || '').trim().toUpperCase().replace(/[\s\-]/g, '');
    if (!plate) {
      ParkingApp.setStatus(statusEl, 'Please enter or scan a plate number.', 'warning');
      return;
    }

    ParkingApp.setStatus(statusEl, 'Calculating bill for ' + plate + '...', 'info');
    const res = await ParkingApp.api('check_out.php', {
      method: 'POST',
      body: { plate_number: plate },
    });

    if (!res.success) {
      hideBill();
      ParkingApp.setStatus(
        statusEl,
        res.message || 'No active parking session found for this vehicle.',
        'danger'
      );
      if (fromAuto) guard.mark(plate);
      return;
    }

    showPlate(res.data.plate_number);
    renderBill(res.data, false);
    if (fromAuto) guard.mark(plate);
    ParkingApp.setStatus(
      statusEl,
      'Amount due: ' + (res.data.amount_label || '') + ' — confirm payment to check out.',
      'success'
    );
  }

  async function confirmPayment() {
    if (!currentBill) {
      ParkingApp.setStatus(statusEl, 'Get the bill first.', 'warning');
      return;
    }
    const paidPlate = currentBill.plate_number;
    btnPay.disabled = true;
    ParkingApp.setStatus(statusEl, 'Confirming payment...', 'info');
    const res = await ParkingApp.api('confirm_payment.php', {
      method: 'POST',
      csrf: csrf,
      body: {
        csrf_token: csrf,
        session_id: currentBill.id,
        plate_number: currentBill.plate_number,
      },
    });
    if (res.success) {
      guard.mark(paidPlate);
      ParkingApp.setStatus(
        statusEl,
        '✓ Paid ' + (res.data.amount_label || '') + '. Vehicle checked out.',
        'success'
      );
      renderBill(res.data, true);
      currentBill = null;
      lastBilledPlate = '';
      awaitingPayment = false;
      refreshCheckoutRecords();
      loadConfig();
      // Resume auto scan for next vehicle after short pause
      if ((!autoToggle || autoToggle.checked) && cameraOn) {
        stopAuto();
        timer = setTimeout(() => startAuto(true), 2500);
      }
    } else {
      ParkingApp.setStatus(statusEl, res.message || 'Payment confirmation failed.', 'danger');
      btnPay.disabled = false;
      if (res._http === 403) loadConfig();
    }
  }

  btnStart.addEventListener('click', startCamera);
  btnStop.addEventListener('click', stopCamera);
  btnScan.addEventListener('click', () => scanAndShowBill(false));
  btnGetBill.addEventListener('click', () => getBill(plateInput.value, false));
  btnPay.addEventListener('click', confirmPayment);
  plateInput.addEventListener('input', () => {
    hideBill();
    showPlate(plateInput.value.toUpperCase().replace(/[\s\-]/g, ''));
  });

  if (autoToggle) {
    autoToggle.addEventListener('change', () => {
      if (autoToggle.checked) {
        if (cameraOn) startAuto(true);
        else ParkingApp.setStatus(statusEl, 'Auto mode on — start the camera to begin.', 'info');
      } else {
        stopAuto();
        ParkingApp.setStatus(statusEl, 'Auto mode off. Use Scan / Get Bill manually.', 'info');
      }
    });
    autoToggle.checked = true;
  }

  loadConfig().then((ok) => {
    if (ok === false) return;
    ParkingApp.setStatus(statusEl, 'Starting camera for auto exit...', 'info');
    startCamera();
  });
  refreshCheckoutRecords();
  setInterval(refreshCheckoutRecords, 10000);
})();
