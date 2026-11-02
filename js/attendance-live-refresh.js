/**
 * Live Scans auto-refresh.
 *
 * Polls a filter-aware cursor (latest event id + successful-scan count for the
 * current scope). A global maximum id would miss events outside the active
 * grade/section/student filter, so the cursor is scoped. When it advances, the
 * Live scans tab reconciles by re-fetching the current page; rows are keyed so
 * only genuinely new scans are highlighted and no duplicates appear.
 *
 * Polling (not SSE) is deliberate: it is the simplest reliable option for this
 * PHP/XAMPP deployment and it degrades gracefully when the scanner is offline.
 */
window.attendanceLiveRefresh = (function () {
  'use strict';
  let cursor = null;
  let controller = null;

  async function poll() {
    if (document.hidden) return;
    if (window.attendanceTabs?.tab !== 'live') return;
    controller?.abort();
    controller = new AbortController();
    try {
      const params = window.attendanceTabs?.liveParams?.() || new URLSearchParams();
      const response = await fetch(`get-scan-cursor.php?${params.toString()}`, {
        signal: controller.signal,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) return;
      const data = await response.json();
      if (!data.ok) return;
      const key = `${data.latest_id}:${data.success_total}`;
      if (cursor === null) {
        cursor = key;
        return;
      }
      if (key !== cursor) {
        cursor = key;
        window.attendanceTabs?.refreshLive?.();
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        // Stay quiet: a dropped poll must never disturb the operator.
        console.warn('Live scan poll did not complete.', error);
      }
    }
  }

  setInterval(poll, 4000);
  poll();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) poll();
  });

  return {
    prime() { cursor = null; },
    poll,
  };
})();
