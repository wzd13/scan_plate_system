/**
 * Entry Gate — auto scan + auto check-in by default.
 */
(function () {
  'use strict';

  const video = document.getElementById('camera');
  const canvas = document.getElementById('snapshot');
  const plateInput = document.getElementById('plateInput');
  const statusEl = document.getElementById('status');
  const plateDisplay = document.getElementById('plateDisplay');
  const autoToggle = document.getElementById('autoScan');
  const btnStart = document.getElementById('btnStart');
  const btnStop = document.getElementById('btnStop');
  const btnScan = document.getElementById('btnScan');
  const btnCheckIn = document.getElementById('btnCheckIn');

  const camera = new ParkingApp.CameraController(video, canvas);
  const guard = new ParkingApp.ScanGuard(12000);
  let scanInterval = 4000;
  let timer = null;
  let cameraOn = false;

  function showPlate(plate) {
    plateDisplay.textContent = plate || '—';
    if (plate) plateInput.value = plate;
  }

  async function loadConfig() {
    try {
      const res = await ParkingApp.api('public_config.php');
      if (res.success && res.data) {
        scanInterval = Math.max(5000, (res.data.scan_interval_seconds || 6) * 1000);
        if (res.data.https_required_for_camera) {
          ParkingApp.setStatus(
            statusEl,
            'Tip: Camera access requires HTTPS when not on localhost. Manual entry always works.',
            'info'
          );
        }
        return true;
      }
      ParkingApp.setStatus(
        statusEl,
        (res && res.message) || 'Unable to load config.',
        'danger'
      );
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
      if (autoToggle.checked) {
        stopAuto();
        timer = setTimeout(() => {
          scanAndMaybeCheckIn(true).finally(() => {
            if (autoToggle.checked && cameraOn) {
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
      if (!autoToggle.checked || !cameraOn) return;
      await scanAndMaybeCheckIn(true);
      if (autoToggle.checked && cameraOn) {
        timer = setTimeout(tick, scanInterval);
      }
    };

    if (runNow) {
      tick();
    } else {
      timer = setTimeout(tick, scanInterval);
    }
  }

  function stopAuto() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  async function recognize() {
    const dataUrl = camera.captureDataUrl(0.55, 640);
    ParkingApp.setStatus(statusEl, 'AI recognizing plate (may take a few seconds)...', 'info');
    return ParkingApp.api('recognize_plate.php', {
      method: 'POST',
      body: { image: dataUrl, mime_type: 'image/jpeg' },
    });
  }

  async function scanAndMaybeCheckIn(autoMode) {
    if (guard.busy) return;
    if (!cameraOn) {
      ParkingApp.setStatus(statusEl, 'Start the camera first.', 'warning');
      return;
    }

    guard.setBusy(true);
    try {
      const res = await recognize();
      if (!res.success) {
        ParkingApp.setStatus(statusEl, res.message || 'AI recognition failed. Will retry...', 'danger');
        return;
      }

      const data = res.data || {};
      const plate = data.plate_number || data.plate || '';
      const action = data.action || 'reject';
      showPlate(plate);

      if (action === 'reject' || !data.valid_plate) {
        ParkingApp.setStatus(
          statusEl,
          res.message || 'No clear plate. Scanning again...',
          'warning'
        );
        return;
      }

      // Auto mode: check in on HIGH and MEDIUM (verify) without waiting for button
      const shouldAutoCheckIn = autoMode && (action === 'auto' || action === 'verify');

      if (!shouldAutoCheckIn) {
        ParkingApp.setStatus(statusEl, 'Plate detected: ' + plate + '. Press Check-In to continue.', 'success');
        return;
      }

      if (!guard.canProcess(plate)) {
        ParkingApp.setStatus(
          statusEl,
          'Plate ' + plate + ' already processed recently. Waiting for next vehicle...',
          'info'
        );
        return;
      }

      ParkingApp.setStatus(statusEl, 'Plate detected: ' + plate + ' — checking in...', 'success');
      await checkIn(plate, true);
    } catch (err) {
      ParkingApp.setStatus(statusEl, err.message || 'Scan failed.', 'danger');
    } finally {
      guard.setBusy(false);
    }
  }

  async function refreshRecentRecords() {
    const body = document.getElementById('recentRecordsBody');
    const badge = document.getElementById('activeCountBadge');
    if (!body) return;
    try {
      const res = await ParkingApp.api('recent_records.php?limit=8');
      if (!res.success || !res.data) return;
      if (badge) badge.textContent = (res.data.active_count || 0) + ' active';
      const rows = res.data.records || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="3" class="text-secondary">No check-in records yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.map((r) => {
        const statusClass = r.status === 'ACTIVE' ? 'primary' : 'success';
        return '<tr>' +
          '<td class="fw-semibold">' + (r.plate_number || '') + '</td>' +
          '<td>' + (r.entry_time || '') + '</td>' +
          '<td><span class="badge text-bg-' + statusClass + '">' + (r.status || '') + '</span></td>' +
          '</tr>';
      }).join('');
    } catch (_) {
      /* ignore panel refresh errors */
    }
  }

  async function checkIn(plate, fromAuto) {
    plate = (plate || plateInput.value || '').trim().toUpperCase().replace(/[\s\-]/g, '');
    if (!plate) {
      ParkingApp.setStatus(statusEl, 'Please enter or scan a plate number.', 'warning');
      return;
    }
    if (fromAuto && !guard.canProcess(plate)) {
      return;
    }

    ParkingApp.setStatus(statusEl, 'Checking in ' + plate + '...', 'info');
    const res = await ParkingApp.api('check_in.php', {
      method: 'POST',
      body: { plate_number: plate },
    });

    if (res.success) {
      guard.mark(plate);
      const p = (res.data && res.data.plate_number) || plate;
      ParkingApp.setStatus(statusEl, '✓ Vehicle ' + p + ' checked in successfully', 'success');
      showPlate(p);
      refreshRecentRecords();
    } else if (res._http === 409 || (res.data && res.data.already_active)) {
      guard.mark(plate);
      ParkingApp.setStatus(
        statusEl,
        res.message || ('Vehicle ' + plate + ' is already parked.'),
        'warning'
      );
      refreshRecentRecords();
    } else {
      ParkingApp.setStatus(statusEl, res.message || 'Check-in failed.', 'danger');
    }
  }

  btnStart.addEventListener('click', startCamera);
  btnStop.addEventListener('click', stopCamera);
  btnScan.addEventListener('click', () => scanAndMaybeCheckIn(false));
  btnCheckIn.addEventListener('click', () => checkIn(plateInput.value, false));
  autoToggle.addEventListener('change', () => {
    if (autoToggle.checked) {
      if (cameraOn) startAuto(true);
      else ParkingApp.setStatus(statusEl, 'Auto mode on — start the camera to begin scanning.', 'info');
    } else {
      stopAuto();
      ParkingApp.setStatus(statusEl, 'Auto mode off. Use Scan / Check-In manually.', 'info');
    }
  });
  plateInput.addEventListener('input', () => {
    showPlate(plateInput.value.toUpperCase().replace(/[\s\-]/g, ''));
  });

  // Default: auto mode ON + start camera on load
  autoToggle.checked = true;
  loadConfig().then((ok) => {
    if (ok === false) return;
    ParkingApp.setStatus(statusEl, 'Starting camera for auto entry...', 'info');
    startCamera();
  });
  refreshRecentRecords();
  setInterval(refreshRecentRecords, 8000);
})();
