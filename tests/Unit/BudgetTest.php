<?php

final class BudgetTest extends TestCase
{
    private array $budgetIds = [];

    protected function tearDown(): void
    {
        foreach ($this->budgetIds as $id) {
            try { dbRun("DELETE FROM payment_budgets WHERE id = ?", [$id]); } catch (\Throwable $e) {}
        }
        $this->budgetIds = [];
        parent::tearDown();
    }

    private function makeBudget(?string $department, ?string $capexOpex, float $amount): string
    {
        $id = newUuid();
        dbRun("INSERT INTO payment_budgets (id,department,capex_opex,monthly_amount) VALUES (?,?,?,?)", [$id, $department, $capexOpex, $amount]);
        $this->budgetIds[] = $id;
        return $id;
    }

    public function testUncommittedSpendMeansZeroPercentUsed(): void
    {
        $id = $this->makeBudget('Operations_' . substr(newUuid(), 0, 6), 'Opex', 100000);
        $statuses = getBudgetStatuses();
        $row = current(array_filter($statuses, fn($s) => $s['id'] === $id));
        $this->assertNotFalse($row);
        $this->assertSame(0.0, $row['spent']);
        $this->assertEqualsWithDelta(0, $row['pct'], 0.01);
    }

    public function testCommittedPaymentRequestsCountTowardTheMatchingBudget(): void
    {
        $dept = 'Ops_' . substr(newUuid(), 0, 6);
        $id = $this->makeBudget($dept, 'Opex', 100000);

        $prId = newUuid();
        dbRun(
            "INSERT INTO payment_requests (id,requester_id,requester_name,amount,description,status,date_of_request,department,capex_opex,priority)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$prId, newUuid(), 'Test Requester', 60000, 'Test spend', 'approved', date('Y-m-d'), $dept, 'Opex', 'Medium']
        );
        $this->track('payment_requests', $prId);

        $statuses = getBudgetStatuses();
        $row = current(array_filter($statuses, fn($s) => $s['id'] === $id));
        $this->assertSame(60000.0, $row['spent']);
        $this->assertEqualsWithDelta(60, $row['pct'], 0.01);
    }

    public function testPendingRequestsDoNotCountAsCommittedSpend(): void
    {
        $dept = 'Ops_' . substr(newUuid(), 0, 6);
        $id = $this->makeBudget($dept, 'Opex', 100000);

        $prId = newUuid();
        dbRun(
            "INSERT INTO payment_requests (id,requester_id,requester_name,amount,description,status,date_of_request,department,capex_opex,priority)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$prId, newUuid(), 'Test Requester', 60000, 'Not yet reviewed', 'pending', date('Y-m-d'), $dept, 'Opex', 'Medium']
        );
        $this->track('payment_requests', $prId);

        $statuses = getBudgetStatuses();
        $row = current(array_filter($statuses, fn($s) => $s['id'] === $id));
        $this->assertSame(0.0, $row['spent'], 'A merely-pending request should not count as committed spend.');
    }
}
