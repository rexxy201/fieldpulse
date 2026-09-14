<?php

/**
 * The Date Range presets are shared by the Tickets and Installations lists and
 * their CSV exports, so the boundary maths is worth pinning down — especially
 * the quarter rollover and the "previous week" Monday–Sunday window.
 */
final class DateRangeTest extends TestCase
{
    /** Wednesday, 2026-05-13 — mid-Q2, mid-month, mid-week. */
    private const REF = '2026-05-13';

    public function testMonthAndYearToDateEndOnTheReferenceDay(): void
    {
        $this->assertSame(['from' => '2026-05-01', 'to' => '2026-05-13'], dateRangeBounds('month_to_date', self::REF));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-05-13'], dateRangeBounds('year_to_date', self::REF));
        $this->assertSame(['from' => '2026-04-01', 'to' => '2026-05-13'], dateRangeBounds('quarter_to_date', self::REF));
    }

    public function testWholePeriodPresetsCoverTheFullPeriod(): void
    {
        $this->assertSame(['from' => '2026-04-01', 'to' => '2026-06-30'], dateRangeBounds('this_quarter', self::REF));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], dateRangeBounds('this_year', self::REF));
        $this->assertSame(['from' => '2026-04-01', 'to' => '2026-04-30'], dateRangeBounds('previous_month', self::REF));
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-03-31'], dateRangeBounds('previous_quarter', self::REF));
        $this->assertSame(['from' => '2025-01-01', 'to' => '2025-12-31'], dateRangeBounds('previous_year', self::REF));
        $this->assertSame(['from' => '2026-05-12', 'to' => '2026-05-12'], dateRangeBounds('previous_day', self::REF));
    }

    public function testPreviousWeekIsTheMondayToSundayBeforeTheCurrentOne(): void
    {
        // Wednesday 2026-05-13 sits in the week of Mon 2026-05-11.
        $this->assertSame(['from' => '2026-05-04', 'to' => '2026-05-10'], dateRangeBounds('previous_week', self::REF));
        // A Sunday belongs to the ISO week that is ending, not the one starting.
        $this->assertSame(['from' => '2026-05-04', 'to' => '2026-05-10'], dateRangeBounds('previous_week', '2026-05-17'));
        // And a Monday looks back a full week.
        $this->assertSame(['from' => '2026-05-04', 'to' => '2026-05-10'], dateRangeBounds('previous_week', '2026-05-11'));
    }

    public function testPeriodsRollOverTheYearBoundary(): void
    {
        $this->assertSame(['from' => '2025-10-01', 'to' => '2025-12-31'], dateRangeBounds('previous_quarter', '2026-02-10'));
        $this->assertSame(['from' => '2025-12-01', 'to' => '2025-12-31'], dateRangeBounds('previous_month', '2026-01-15'));
        $this->assertSame(['from' => '2025-12-31', 'to' => '2025-12-31'], dateRangeBounds('previous_day', '2026-01-01'));
    }

    public function testCustomAndUnknownPresetsHaveNoBounds(): void
    {
        $this->assertNull(dateRangeBounds('custom', self::REF));
        $this->assertNull(dateRangeBounds('', self::REF));
        $this->assertNull(dateRangeBounds('last_fortnight', self::REF));
    }

    public function testResolveDateRangeLetsAPresetOverrideExplicitDates(): void
    {
        $r = resolveDateRange(['dateRange' => 'previous_year', 'dateFrom' => '2001-01-01', 'dateTo' => '2001-01-02']);
        $y = (int)date('Y') - 1;
        $this->assertSame(['range' => 'previous_year', 'from' => "$y-01-01", 'to' => "$y-12-31"], $r);
    }

    public function testResolveDateRangeTreatsBareDatesAsCustom(): void
    {
        $this->assertSame(
            ['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            resolveDateRange(['dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31'])
        );
        $this->assertSame(['range' => '', 'from' => '', 'to' => ''], resolveDateRange([]));
    }

    public function testResolveDateRangeDropsAnUnknownPresetRatherThanFilteringNothing(): void
    {
        $this->assertSame(
            ['range' => '', 'from' => '', 'to' => ''],
            resolveDateRange(['dateRange' => 'nonsense', 'dateFrom' => '2026-01-01'])
        );
    }
}
