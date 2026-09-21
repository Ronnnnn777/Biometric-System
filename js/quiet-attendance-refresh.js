window.createQuietAttendanceRefresh = ({ rows, controls, exportLink, endpoint, getParams, onRowsUpdated = null }) => {
  let lastHtml = null;
  let controller = null;
  let requestNumber = 0;

  const updateExportLink = () => {
    if (exportLink) exportLink.href = `export-attendance.php?${getParams()}`;
  };

  const showInitialError = () => {
    if (lastHtml !== null) return;
    rows.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-rose-600">Could not load records. Check your connection and try again.</td></tr>';
  };

  async function refresh() {
    if (document.hidden) return;
    updateExportLink();
    controller?.abort();
    controller = new AbortController();
    const thisRequest = ++requestNumber;
    rows.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(`${endpoint}?${getParams()}`, { signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error(`Attendance request failed (${response.status}).`);
      const html = (await response.text()).trim();
      if (thisRequest !== requestNumber || html === lastHtml) return;

      rows.classList.add('opacity-60', 'transition-opacity', 'duration-150');
      await new Promise(requestAnimationFrame);
      rows.innerHTML = html;
      lastHtml = html;
      onRowsUpdated?.();
      requestAnimationFrame(() => rows.classList.remove('opacity-60'));
    } catch (error) {
      if (error.name !== 'AbortError') {
        console.warn('Attendance refresh did not complete.', error);
        showInitialError();
      }
    } finally {
      if (thisRequest === requestNumber) rows.removeAttribute('aria-busy');
    }
  }

  controls.forEach(control => control.addEventListener('change', refresh));
  updateExportLink();
  refresh();
  setInterval(refresh, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
};
