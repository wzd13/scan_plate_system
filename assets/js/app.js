/**
 * Shared camera + API helpers for Entry/Exit gates.
 */
(function (global) {
  'use strict';

  const API_BASE = document.body.dataset.apiBase || 'api';

  async function api(path, options = {}) {
    const opts = {
      method: options.method || 'GET',
      headers: Object.assign({ Accept: 'application/json' }, options.headers || {}),
      credentials: 'same-origin',
    };
    if (options.body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(options.body);
    }
    if (options.csrf) {
      opts.headers['X-CSRF-Token'] = options.csrf;
    }
    const res = await fetch(`${API_BASE}/${path}`, opts);
    let data;
    try {
      data = await res.json();
    } catch (_) {
      data = { success: false, message: 'Invalid server response.' };
    }
    data._http = res.status;
    return data;
  }

  function setStatus(el, message, type) {
    if (!el) return;
    el.textContent = message;
    el.className = 'status-box ' + (type || 'info');
  }

  class CameraController {
    constructor(videoEl, canvasEl) {
      this.video = videoEl;
      this.canvas = canvasEl;
      this.stream = null;
      this.busy = false;
    }

    async start() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('Camera is not supported in this browser.');
      }
      const secure = window.isSecureContext;
      if (!secure && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        throw new Error('Camera access requires a secure HTTPS context. Please use HTTPS or enter the plate manually.');
      }
      this.stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 854 },
          height: { ideal: 480 },
        },
      });
      this.video.srcObject = this.stream;
      await this.video.play();
    }

    stop() {
      if (this.stream) {
        this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
      }
      if (this.video) {
        this.video.srcObject = null;
      }
    }

    captureDataUrl(quality, maxWidth) {
      if (!this.video || this.video.readyState < 2) {
        throw new Error('Camera is not ready.');
      }
      const srcW = this.video.videoWidth || 640;
      const srcH = this.video.videoHeight || 480;
      const limit = maxWidth || 720;
      const scale = srcW > limit ? limit / srcW : 1;
      const w = Math.max(1, Math.round(srcW * scale));
      const h = Math.max(1, Math.round(srcH * scale));
      this.canvas.width = w;
      this.canvas.height = h;
      const ctx = this.canvas.getContext('2d');
      ctx.drawImage(this.video, 0, 0, w, h);
      return this.canvas.toDataURL('image/jpeg', quality == null ? 0.6 : quality);
    }
  }

  /** Prevent overlapping scans and short-term duplicate plate processing. */
  class ScanGuard {
    constructor(cooldownMs) {
      this.cooldownMs = cooldownMs || 8000;
      this.busy = false;
      this.recent = new Map();
    }

    canProcess(plate) {
      // Plate cooldown only — overlapping requests use `busy` separately.
      if (!plate) return true;
      const last = this.recent.get(plate);
      if (last && Date.now() - last < this.cooldownMs) return false;
      return true;
    }

    mark(plate) {
      if (plate) this.recent.set(plate, Date.now());
    }

    setBusy(v) {
      this.busy = !!v;
    }
  }

  global.ParkingApp = { api, setStatus, CameraController, ScanGuard };
})(window);
