/**
 * Shared JSON API helper for the hotelpos AJAX frontend.
 *
 * Responsibilities:
 * - Prefix requests with the configured API front controller path.
 * - Attach JSON headers and the current CSRF token.
 * - Parse the standard API response envelope.
 * - Send users back to login when the server returns 401.
 * - Apply a request timeout so the UI does not wait forever.
 */
(function () {
  // Read server-generated configuration from <meta> tags in public/index.php.
  const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
  const apiBase = meta('api-base');

  /**
   * Low-level request wrapper used by get() and post().
   * All endpoint-specific code should call this helper rather than fetch()
   * directly so CSRF, errors, and auth expiry behave consistently.
   */
  async function request(path, options = {}) {
    const headers = Object.assign({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': meta('csrf-token')
    }, options.headers || {});

    // AbortController gives every request a timeout without extra libraries.
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeout || 15000);
    try {
      const res = await fetch(apiBase + path, {
        method: options.method || 'GET',
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal
      });

      // The backend should always return JSON, but this fallback makes broken
      // PHP/errors easier to surface during development.
      const payload = await res.json().catch(() => ({ success: false, message: 'Invalid JSON response.', errors: {} }));

      // Authentication expiry is a global UI concern, so handle it here once.
      if (res.status === 401) {
        document.getElementById('appView')?.classList.add('d-none');
        document.getElementById('loginView')?.classList.remove('d-none');
      }

      // Convert the API envelope into normal JS exceptions for callers.
      if (!res.ok || !payload.success) {
        const err = new Error(payload.message || 'Request failed.');
        err.payload = payload;
        err.status = res.status;
        throw err;
      }
      return payload.data;
    } finally {
      clearTimeout(timeout);
    }
  }

  // Expose a tiny API surface; keep endpoint details inside app.js.
  window.HotelPOSApi = {
    get: (path) => request(path),
    post: (path, body) => request(path, { method: 'POST', body }),
    setCsrf(token) {
      document.querySelector('meta[name="csrf-token"]').setAttribute('content', token);
    }
  };
})();
