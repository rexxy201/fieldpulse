<?php

/**
 * Walks a real payment request through the actual HTTP endpoints — Authorize
 * -> Approve -> Finance Check -> Disburse — the way a browser would, because
 * that state-transition logic lives inside pages/payment-requests.php's POST
 * handler, not in standalone functions this suite could call directly. This
 * is the "smoke test on the payment approval chain" flagged as a priority
 * when the test suite was scoped.
 *
 * Spins up PHP's built-in server against this same codebase (index.php
 * already doubles as its router — see the is_file()/return false check at
 * its top) pointed at the disposable test database, drives it with cURL,
 * and asserts on the database afterward.
 */
final class PaymentRequestApprovalTest extends TestCase
{
    private static $serverProcess;
    private static string $baseUrl;
    private static string $cookieJar;

    public static function setUpBeforeClass(): void
    {
        $port = 8971;
        self::$baseUrl = "http://127.0.0.1:{$port}";
        self::$cookieJar = sys_get_temp_dir() . '/fieldpulse_test_cookies_' . getmypid() . '.txt';

        $docroot = dirname(__DIR__, 2);
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s %s',
            $port,
            escapeshellarg($docroot),
            escapeshellarg($docroot . DIRECTORY_SEPARATOR . 'index.php')
        );
        // Inherit the whole current environment (env=null) rather than a
        // hand-picked subset — on Windows in particular, proc_open needs
        // several other vars (SystemRoot, TEMP, ...) present or the child
        // process fails to start correctly. The FIELDPULSE_DB_* vars are
        // already in this process's environment via phpunit.xml's <php><env>
        // block / tests/bootstrap.php's putenv(), so they carry over as-is.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open($cmd, $descriptors, $pipes, $docroot, null);
        if (!is_resource(self::$serverProcess)) {
            self::fail('Could not start the built-in PHP server for feature tests.');
        }
        // Give it a moment to bind the port before the first request.
        for ($i = 0; $i < 20; $i++) {
            $ch = curl_init(self::$baseUrl . '/login');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            $ok = curl_exec($ch) !== false;
            curl_close($ch);
            if ($ok) break;
            usleep(150000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        @unlink(self::$cookieJar);
    }

    /** @return array{0:int,1:string} [http status, body] */
    private function request(string $method, string $path, array $post = [], array $files = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => self::$cookieJar,
            CURLOPT_COOKIEFILE     => self::$cookieJar,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $files ? array_merge($post, $files) : $post);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string)$body];
    }

    private function csrfTokenFromPage(string $path): string
    {
        [, $body] = $this->request('GET', $path);
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    public function testFullApprovalChainEndsInDisbursedWithCorrectAmountPaid(): void
    {
        // 'admin' bypasses permission checks entirely (see hasPermission()) —
        // the simplest way to drive every stage of the chain with one user
        // without needing to seed role_permissions rows for this test.
        $user = $this->makeUser(['role' => 'admin']);

        [$loginStatus] = $this->request('POST', '/login', [
            'username' => $user['username'],
            'password' => 'TestPassw0rd!',
        ]);
        $this->assertSame(302, $loginStatus, 'Login should redirect on success.');

        $csrf = $this->csrfTokenFromPage('/payment-requests');
        $this->assertNotEmpty($csrf, 'Expected a csrf-token meta tag on the authenticated page.');

        // ── Create (Operational/no-customer path keeps this test focused on
        // the approval chain, not the customer-linking feature) ────────────
        $tmpDoc = tempnam(sys_get_temp_dir(), 'fp_test_doc_') . '.png';
        file_put_contents($tmpDoc, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        [$createStatus] = $this->request('POST', '/payment-requests', [
            '_action' => 'create',
            '_csrf' => $csrf,
            'request_type' => 'admin',
            'category' => 'Operational',
            'capex_opex' => 'Opex',
            'priority' => 'Medium',
            'description' => 'Feature test payment request',
            // Multipart POSTs (files present, as here) don't reliably expand a
            // PHP array value the way application/x-www-form-urlencoded does —
            // curl needs the [] suffix baked into the key itself, one scalar
            // per key, to produce a real PHP array server-side.
            'item_description[]' => 'Test line item',
            'item_qty[]' => '1',
            'item_unit_price[]' => '5000',
        ], [
            'documents[]' => new CURLFile($tmpDoc, 'image/png', 'evidence.png'),
        ]);
        @unlink($tmpDoc);
        $this->assertSame(302, $createStatus, 'Create should redirect back to the list on success.');

        $pr = dbFetch("SELECT * FROM payment_requests WHERE description = 'Feature test payment request' ORDER BY created_at DESC LIMIT 1");
        $this->assertNotNull($pr, 'The payment request should have been created.');
        $this->track('payment_requests', $pr['id']);
        $this->assertSame('pending', $pr['status']);
        $this->assertSame(5000.0, (float)$pr['amount']);

        $docCount = (int)dbFetch("SELECT COUNT(*) c FROM payment_request_documents WHERE payment_request_id = ?", [$pr['id']])['c'];
        $this->assertSame(1, $docCount, 'The compulsory backing document should have been saved.');

        // ── Authorize ────────────────────────────────────────────────────
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'authorize', 'req_id' => $pr['id'],
        ]);
        $this->assertJsonOk($body);
        $this->assertSame('authorized', $this->reload($pr['id'])['status']);

        // ── Approve ──────────────────────────────────────────────────────
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'approve', 'req_id' => $pr['id'],
        ]);
        $this->assertJsonOk($body);
        $this->assertSame('approved', $this->reload($pr['id'])['status']);

        // ── Finance Review: accountant starts review ──────────────────────
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'start_finance_review', 'req_id' => $pr['id'],
        ]);
        $this->assertJsonOk($body);
        $this->assertSame('finance_review', $this->reload($pr['id'])['status']);

        // ── Finance Review: accountant submits with adjusted line items ───
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'submit_finance_review', 'req_id' => $pr['id'],
            'item_description[]' => 'Adjusted Item',
            'item_qty[]'         => '1',
            'item_unit_price[]'  => '4500',
            'review_notes'       => 'Reduced by 500 after audit',
        ]);
        $this->assertJsonOk($body);
        $reviewed = $this->reload($pr['id']);
        $this->assertSame('authorized', $reviewed['status'], 'After finance review, status must revert to authorized for re-approval.');
        $this->assertSame(4500.0, (float)$reviewed['amount'], 'Amount must reflect adjusted line items.');

        // ── Re-Approve after finance review ───────────────────────────────
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'approve', 'req_id' => $pr['id'],
        ]);
        $this->assertJsonOk($body);
        $this->assertSame('approved', $this->reload($pr['id'])['status']);

        // ── Finance Check: a partial payment first (bill-style, like Zoho Books) ──
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'disburse', 'req_id' => $pr['id'],
            'pay_amount' => '2000', 'payment_reference' => 'TEST-PARTIAL',
        ]);
        $this->assertJsonOk($body);
        $partial = $this->reload($pr['id']);
        $this->assertSame('partially_disbursed', $partial['status']);
        $this->assertSame(2000.0, (float)$partial['amount_paid']);

        // A payment can't exceed the remaining balance (2500, not 4500).
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'disburse', 'req_id' => $pr['id'],
            'pay_amount' => '5000', 'payment_reference' => 'TEST-OVER',
        ]);
        $decoded = json_decode($body, true);
        $this->assertFalse($decoded['ok'] ?? true, 'A payment larger than the outstanding balance must be rejected.');
        $this->assertSame(2000.0, (float)$this->reload($pr['id'])['amount_paid'], 'amount_paid must be unchanged after a rejected overpayment.');

        // ── Finish it off ────────────────────────────────────────────────
        [, $body] = $this->request('POST', '/payment-requests', [
            'ajax' => '1', '_csrf' => $csrf, 'action' => 'disburse', 'req_id' => $pr['id'],
            'pay_amount' => '2500', 'payment_reference' => 'TEST-FINAL',
        ]);
        $this->assertJsonOk($body);
        $final = $this->reload($pr['id']);
        $this->assertSame('disbursed', $final['status']);
        $this->assertSame(4500.0, (float)$final['amount_paid']);

        $paymentCount = (int)dbFetch("SELECT COUNT(*) c FROM payment_request_payments WHERE payment_request_id = ?", [$pr['id']])['c'];
        $this->assertSame(2, $paymentCount, 'Two accepted payments (partial + final) should be on record.');
    }

    private function reload(string $id): array
    {
        return dbFetch("SELECT * FROM payment_requests WHERE id = ?", [$id]);
    }

    private function assertJsonOk(string $body): void
    {
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, "Expected a JSON response, got: {$body}");
        $this->assertTrue($decoded['ok'] ?? false, 'Expected ok:true in: ' . $body);
    }
}
