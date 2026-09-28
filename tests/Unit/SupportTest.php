<?php

/** Customer Support module: interaction logging, follow-ups, agent status. */
final class SupportTest extends TestCase
{
    private function wrapCodeId(): string
    {
        return dbFetch("SELECT id FROM cs_wrap_codes WHERE active = 1 ORDER BY sort_order LIMIT 1")['id'];
    }

    private function makeCustomer(): array
    {
        $id = newUuid();
        dbRun("INSERT INTO customers (id,name,phone,account_number,status) VALUES (?,?,?,?, 'active')",
            [$id, 'Ada Test', '+234 803 555 0101', 'ACC-' . substr($id, 0, 6)]);
        $this->track('customers', $id);
        return dbFetch("SELECT * FROM customers WHERE id = ?", [$id]);
    }

    private function log(array $in, array $agent): array
    {
        $res = csLogInteraction($in, $agent);
        if ($res['ok']) {
            $this->track('cs_interactions', $res['id']);
            foreach (dbFetchAll("SELECT id FROM cs_followups WHERE interaction_id = ?", [$res['id']]) as $f) $this->track('cs_followups', $f['id']);
        }
        return $res;
    }

    public function testMigrationSeedsWrapCodesAndPermissions(): void
    {
        $this->assertGreaterThan(10, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_wrap_codes")['n']);
        $perms = array_column(dbFetchAll("SELECT permission FROM role_permissions WHERE role = 'cx'"), 'permission');
        $this->assertContains('support.view', $perms);
        $this->assertNotContains('support.manage', $perms);
        $sup = array_column(dbFetchAll("SELECT permission FROM role_permissions WHERE role = 'cx_supervisor'"), 'permission');
        $this->assertContains('support.view_all', $sup);
        $this->assertContains('support.manage', $sup);
    }

    public function testLogsAnInteractionForACustomerWithHandleTime(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $cust  = $this->makeCustomer();
        $res = $this->log([
            'customer_id' => $cust['id'], 'channel' => 'call', 'direction' => 'inbound',
            'wrap_code_id' => $this->wrapCodeId(), 'summary' => 'Router reset, service back', 'outcome' => 'resolved',
            'started_ts' => time() - 125,
        ], $agent);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $row = dbFetch("SELECT * FROM cs_interactions WHERE id = ?", [$res['id']]);
        $this->assertSame('Ada Test', $row['contact_name'], 'name comes from the customer record');
        $this->assertSame('+234 803 555 0101', $row['contact_phone']);
        $this->assertSame($agent['id'], $row['agent_id']);
        $this->assertEqualsWithDelta(125, (int)$row['duration_sec'], 3);
    }

    public function testRejectsIncompleteOrInvalidInput(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $base  = ['contact_phone' => '0803', 'channel' => 'call', 'wrap_code_id' => $this->wrapCodeId(), 'summary' => 'x', 'outcome' => 'resolved'];
        $this->assertFalse(csLogInteraction(['channel' => 'pigeon'] + $base, $agent)['ok']);
        $this->assertFalse(csLogInteraction(['outcome' => 'teleported'] + $base, $agent)['ok']);
        $this->assertFalse(csLogInteraction(['wrap_code_id' => ''] + $base, $agent)['ok']);
        $this->assertFalse(csLogInteraction(['summary' => '  '] + $base, $agent)['ok']);
        $this->assertFalse(csLogInteraction(['contact_phone' => ''] + $base, $agent)['ok'], 'unknown caller needs a name or phone');
        $this->assertFalse(csLogInteraction(['customer_id' => newUuid()] + $base, $agent)['ok'], 'unknown customer id');
        $this->assertFalse(csLogInteraction(['outcome' => 'ticket_created'] + $base, $agent)['ok'], 'ticket outcome needs a ticket');
        $this->assertFalse(csLogInteraction(['outcome' => 'follow_up'] + $base, $agent)['ok'], 'follow-up outcome needs a due time');
        $this->assertSame(0, (int)dbFetch("SELECT COUNT(*) AS n FROM cs_interactions WHERE agent_id = ?", [$agent['id']])['n']);
    }

    public function testFollowUpIsCreatedAndAssignedToTheAgent(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $res = $this->log([
            'contact_name' => 'Walk-in visitor', 'contact_phone' => '08035550199', 'channel' => 'walk_in',
            'wrap_code_id' => $this->wrapCodeId(), 'summary' => 'Wants a quote', 'outcome' => 'follow_up',
            'followup_due' => date('Y-m-d\TH:i', time() + 3600), 'followup_note' => 'Send quote',
        ], $agent);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $f = dbFetch("SELECT * FROM cs_followups WHERE interaction_id = ?", [$res['id']]);
        $this->assertSame($agent['id'], $f['assigned_to']);
        $this->assertSame('open', $f['status']);
        $this->assertSame('Send quote', $f['note']);
        $this->assertNull(dbFetch("SELECT customer_id FROM cs_interactions WHERE id = ?", [$res['id']])['customer_id']);
    }

    public function testImplausibleHandleTimeIsDropped(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $res = $this->log(['contact_phone' => '0803', 'channel' => 'sms', 'wrap_code_id' => $this->wrapCodeId(),
            'summary' => 'x', 'outcome' => 'info_only', 'started_ts' => time() - 86400], $agent);
        $this->assertNull(dbFetch("SELECT duration_sec FROM cs_interactions WHERE id = ?", [$res['id']])['duration_sec']);
    }

    public function testPhoneKeyMatchesLocalAndInternationalFormats(): void
    {
        $this->assertSame(csPhoneKey('08035550101'), csPhoneKey('+234 803 555 0101'));
        $this->assertSame(csPhoneKey('08035550101'), csPhoneKey('234-803-555-0101'));
        $this->assertNotSame(csPhoneKey('08035550101'), csPhoneKey('08035550102'));
    }

    public function testAgentStatus(): void
    {
        $agent = $this->makeUser(['role' => 'cx']);
        $this->assertSame('offline', csAgentStatus($agent['id']));
        $this->assertTrue(csSetAgentStatus($agent['id'], 'available'));
        $this->assertTrue(csSetAgentStatus($agent['id'], 'on_call'));
        $this->assertSame('on_call', csAgentStatus($agent['id']));
        $this->assertFalse(csSetAgentStatus($agent['id'], 'napping'));
        dbRun("DELETE FROM cs_agent_status WHERE user_id = ?", [$agent['id']]);
    }

    public function testSupportTablesUseTheCustomersCollation(): void
    {
        if (DB_TYPE !== 'mysql') $this->markTestSkipped('MySQL only');
        foreach (['cs_wrap_codes', 'cs_interactions', 'cs_followups', 'cs_agent_status'] as $t) {
            $this->assertSame(tableCollation('customers'), tableCollation($t), $t);
        }
        dbFetchAll("SELECT i.id FROM cs_interactions i LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN tickets t ON t.id = i.ticket_id
                    LEFT JOIN cs_wrap_codes w ON w.id = i.wrap_code_id LIMIT 1");
        $this->addToAssertionCount(1);
    }
}
