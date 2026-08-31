<?php

final class ReportSubscriptionTest extends TestCase
{
    public function testNeverSentIsAlwaysDue(): void
    {
        $this->assertTrue(reportSubscriptionIsDue(['cadence' => 'weekly', 'last_sent_at' => null]));
    }

    public function testDailyIsDueOnceTheCalendarDateHasChanged(): void
    {
        $sub = ['cadence' => 'daily', 'last_sent_at' => date('Y-m-d H:i:s', strtotime('yesterday'))];
        $this->assertTrue(reportSubscriptionIsDue($sub));

        $sub['last_sent_at'] = date('Y-m-d H:i:s');
        $this->assertFalse(reportSubscriptionIsDue($sub));
    }

    public function testWeeklyIsDueAfterSevenDays(): void
    {
        $sub = ['cadence' => 'weekly', 'last_sent_at' => date('Y-m-d H:i:s', strtotime('-8 days'))];
        $this->assertTrue(reportSubscriptionIsDue($sub));

        $sub['last_sent_at'] = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->assertFalse(reportSubscriptionIsDue($sub));
    }

    public function testMonthlyIsDueOnceTheCalendarMonthHasChanged(): void
    {
        $sub = ['cadence' => 'monthly', 'last_sent_at' => date('Y-m-d H:i:s', strtotime('-40 days'))];
        $this->assertTrue(reportSubscriptionIsDue($sub));

        $sub['last_sent_at'] = date('Y-m-d H:i:s');
        $this->assertFalse(reportSubscriptionIsDue($sub));
    }

    public function testWindowDaysMatchEachCadence(): void
    {
        $this->assertSame(1, reportSubscriptionWindowDays('daily'));
        $this->assertSame(7, reportSubscriptionWindowDays('weekly'));
        $this->assertSame(30, reportSubscriptionWindowDays('monthly'));
    }
}
