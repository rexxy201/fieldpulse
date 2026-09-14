<?php
/**
 * Shared "Date Range" filter — the preset list, the server-side resolution of a
 * preset into concrete from/to dates, and the filter-bar markup.
 *
 * The presets are resolved here rather than in the browser so that every
 * consumer of a filtered URL agrees on the same window: the page listing, the
 * CSV export link, and any hand-typed or bookmarked link like
 * `?dateRange=previous_month` (which carries no explicit dates at all).
 */

/** Preset key => label, in the order they appear in the dropdown. */
const DATE_RANGE_PRESETS = [
    'month_to_date'    => 'Month To Date',
    'this_quarter'     => 'This Quarter',
    'quarter_to_date'  => 'Quarter To Date',
    'this_year'        => 'This Year',
    'year_to_date'     => 'Year To Date',
    'previous_day'     => 'Previous Day',
    'previous_week'    => 'Previous Week',
    'previous_month'   => 'Previous Month',
    'previous_quarter' => 'Previous Quarter',
    'previous_year'    => 'Previous Year',
    'custom'           => 'Custom',
];

/**
 * Concrete ['from' => 'Y-m-d', 'to' => 'Y-m-d'] bounds for a preset, or null
 * for 'custom'/'' /an unknown key (the caller's own dateFrom/dateTo apply then).
 *
 * $today is injectable so the boundary maths is testable without waiting for
 * the calendar to cooperate.
 */
function dateRangeBounds(string $preset, ?string $today = null): ?array
{
    $now = new DateTimeImmutable($today ?: 'today');
    $now = $now->setTime(0, 0);
    $y   = (int)$now->format('Y');
    $m   = (int)$now->format('n');
    // Quarter index 0-3, and the first month of that quarter.
    $q         = intdiv($m - 1, 3);
    $qStart    = fn(int $year, int $quarter) => (new DateTimeImmutable())->setDate($year, $quarter * 3 + 1, 1)->setTime(0, 0);
    $fmt       = fn(DateTimeInterface $d) => $d->format('Y-m-d');

    switch ($preset) {
        case 'month_to_date':
            return ['from' => $fmt($now->modify('first day of this month')), 'to' => $fmt($now)];
        case 'this_quarter':
            $s = $qStart($y, $q);
            return ['from' => $fmt($s), 'to' => $fmt($s->modify('+2 months')->modify('last day of this month'))];
        case 'quarter_to_date':
            return ['from' => $fmt($qStart($y, $q)), 'to' => $fmt($now)];
        case 'this_year':
            return ['from' => sprintf('%04d-01-01', $y), 'to' => sprintf('%04d-12-31', $y)];
        case 'year_to_date':
            return ['from' => sprintf('%04d-01-01', $y), 'to' => $fmt($now)];
        case 'previous_day':
            $d = $now->modify('-1 day');
            return ['from' => $fmt($d), 'to' => $fmt($d)];
        case 'previous_week':
            // Monday–Sunday of the week before the current one.
            $thisMonday = $now->modify('monday this week');
            $from       = $thisMonday->modify('-7 days');
            return ['from' => $fmt($from), 'to' => $fmt($from->modify('+6 days'))];
        case 'previous_month':
            $s = $now->modify('first day of last month');
            return ['from' => $fmt($s), 'to' => $fmt($s->modify('last day of this month'))];
        case 'previous_quarter':
            // Quarter 0 of this year rolls back to quarter 3 of last year.
            $pq = $q - 1;
            $py = $y;
            if ($pq < 0) { $pq = 3; $py = $y - 1; }
            $s = $qStart($py, $pq);
            return ['from' => $fmt($s), 'to' => $fmt($s->modify('+2 months')->modify('last day of this month'))];
        case 'previous_year':
            return ['from' => sprintf('%04d-01-01', $y - 1), 'to' => sprintf('%04d-12-31', $y - 1)];
        default:
            return null;
    }
}

/**
 * A user-supplied date is only usable if it is a real calendar date in Y-m-d
 * form. Anything else ('abc', '2026-13-40', an array from ?dateFrom[]=x) is
 * discarded rather than concatenated into a timestamp comparison: PostgreSQL
 * rejects a malformed timestamp outright, so an unvalidated value here is a
 * broken page for anyone who edits the query string.
 */
function dateRangeSanitiseDate($value): string
{
    if (!is_string($value)) return '';
    $value = trim($value);
    if ($value === '') return '';
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    // createFromFormat is lenient (it rolls 2026-02-31 over into March), so
    // round-trip the result and require it to match what was asked for.
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

/**
 * Read dateRange/dateFrom/dateTo out of a query array (typically $_GET) and
 * return the normalised ['range','from','to'] the page should actually use.
 *
 * - A known preset wins over any from/to in the URL.
 * - An unrecognised preset is dropped rather than silently filtering nothing.
 * - Bare from/to (a drill-down link, an old bookmark) reads as 'custom' so the
 *   dropdown shows "Custom" instead of looking unselected.
 */
function resolveDateRange(array $query): array
{
    $rangeRaw = $query['dateRange'] ?? '';
    $range = is_string($rangeRaw) ? trim($rangeRaw) : '';
    $from  = dateRangeSanitiseDate($query['dateFrom'] ?? '');
    $to    = dateRangeSanitiseDate($query['dateTo'] ?? '');

    if ($range !== '' && $range !== 'custom' && !isset(DATE_RANGE_PRESETS[$range])) {
        return ['range' => '', 'from' => '', 'to' => ''];
    }
    $bounds = $range !== '' ? dateRangeBounds($range) : null;
    if ($bounds) {
        return ['range' => $range, 'from' => $bounds['from'], 'to' => $bounds['to']];
    }
    if ($range === '' && ($from !== '' || $to !== '')) {
        $range = 'custom';
    }
    return ['range' => $range, 'from' => $from, 'to' => $to];
}

/**
 * Render the Date Range row of a filter bar.
 *
 * $basePath   — where the dropdown navigates (e.g. '/installations').
 * $carryOver  — the page's other active filters, preserved in the Clear link.
 */
function renderDateRangeFilter(string $basePath, string $range, string $from, string $to, array $carryOver = []): void
{
    $keep = array_filter($carryOver, fn($k) => !in_array($k, ['dateRange', 'dateFrom', 'dateTo'], true), ARRAY_FILTER_USE_KEY);
    $keep = array_filter($keep, fn($v) => $v !== '' && $v !== null);
    ?>
    <div class="d-flex gap-2 flex-wrap align-items-center" data-date-range-base="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>">
      <span class="text-muted small fw-semibold text-uppercase me-1" style="font-size:.72rem;letter-spacing:.05em">Date Range</span>
      <select class="form-select form-select-sm" style="width:auto;min-width:170px" id="dateRangeSelect" onchange="applyDateRangePreset(this.value)">
        <option value="" <?= $range === '' ? 'selected' : '' ?>>All Time</option>
        <?php foreach (DATE_RANGE_PRESETS as $key => $label): ?>
        <option value="<?= $key ?>" <?= $range === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <span id="customRangeWrap" class="d-flex gap-2 align-items-center <?= $range === 'custom' ? '' : 'd-none' ?>">
        <input type="date" class="form-control form-control-sm" style="width:auto" id="dateFromInput" value="<?= htmlspecialchars($from) ?>" onchange="applyDateRange()">
        <span class="text-muted small">to</span>
        <input type="date" class="form-control form-control-sm" style="width:auto" id="dateToInput" value="<?= htmlspecialchars($to) ?>" onchange="applyDateRange()">
      </span>
      <?php if ($range !== ''): ?>
      <a href="<?= htmlspecialchars($basePath . ($keep ? '?' . http_build_query($keep) : ''), ENT_QUOTES) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Clear</a>
      <?php endif; ?>
      <?php if ($range !== '' && ($from !== '' || $to !== '')): ?>
      <span class="text-muted small"><?= htmlspecialchars($from ?: '…') ?> → <?= htmlspecialchars($to ?: '…') ?></span>
      <?php endif; ?>
    </div>
    <?php
}
