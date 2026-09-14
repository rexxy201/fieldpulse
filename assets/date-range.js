/**
 * Date Range filter — navigation only. The presets themselves are resolved
 * server-side (includes/date-range.php), so the dropdown just puts the chosen
 * preset in the URL rather than duplicating the calendar maths here.
 *
 * Expects the markup rendered by renderDateRangeFilter(): a wrapper carrying
 * data-date-range-base, a #dateRangeSelect, and #dateFromInput/#dateToInput.
 */
function dateRangeBasePath() {
  const wrap = document.querySelector('[data-date-range-base]');
  return (wrap && wrap.getAttribute('data-date-range-base')) || window.location.pathname;
}

function dateRangeNavigate(params) {
  const qs = params.toString();
  window.location.href = dateRangeBasePath() + (qs ? '?' + qs : '');
}

function applyDateRangePreset(preset) {
  const params = new URLSearchParams(window.location.search);
  if (preset === 'custom') {
    // Reveal the pickers and let the user pick dates — don't navigate yet.
    document.getElementById('customRangeWrap').classList.remove('d-none');
    return;
  }
  params.delete('dateFrom');
  params.delete('dateTo');
  preset ? params.set('dateRange', preset) : params.delete('dateRange');
  dateRangeNavigate(params);
}

function applyDateRange() {
  const from = document.getElementById('dateFromInput').value;
  const to   = document.getElementById('dateToInput').value;
  const params = new URLSearchParams(window.location.search);
  params.set('dateRange', 'custom');
  from ? params.set('dateFrom', from) : params.delete('dateFrom');
  to ? params.set('dateTo', to) : params.delete('dateTo');
  dateRangeNavigate(params);
}
