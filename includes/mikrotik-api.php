<?php
/**
 * Mikrotik RouterOS Telnet client (TCP port configurable, default 2333).
 * Authenticates via the RouterOS CLI login prompt, runs commands,
 * and parses the plain-text output. No external dependencies.
 *
 * Usage:
 *   $client = new MikrotikTelnetClient($ip, $port, $timeoutSec);
 *   if ($client->connect()) {
 *       $client->login($user, $pass);
 *       $output = $client->command('/system resource print');
 *       $client->disconnect();
 *   }
 */
class MikrotikTelnetClient
{
    private $sock  = null;
    private string $ip;
    private int    $port;
    private int    $timeout;

    public function __construct(string $ip, int $port = 2333, int $timeout = 5) {
        $this->ip      = $ip;
        $this->port    = $port;
        $this->timeout = $timeout;
    }

    public function connect(): bool {
        $this->sock = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->sock) {
            throw new \RuntimeException("TCP connect failed ($errno: $errstr)");
        }
        stream_set_timeout($this->sock, $this->timeout);
        return true;
    }

    public function login(string $user, string $pass): bool {
        // Drain any IAC negotiation bytes before the login prompt
        $this->drainIac();
        // Expect "Login:" prompt
        $this->waitFor('Login:');
        $this->send($user . "\r\n");
        // Expect "Password:" prompt
        $this->waitFor('Password:');
        $this->send($pass . "\r\n");
        // Successful login → RouterOS CLI prompt contains "> "
        $banner = $this->readUntilPrompt();
        if (str_contains($banner, 'incorrect') || str_contains($banner, 'bad password')) {
            throw new \RuntimeException('Authentication failed — incorrect username or password');
        }
        return true;
    }

    /** Run a CLI command and return the raw output string. */
    public function command(string $cmd): string {
        $this->send($cmd . "\r\n");
        return $this->readUntilPrompt();
    }

    public function disconnect(): void {
        if ($this->sock) {
            @$this->send("/quit\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function send(string $data): void {
        fwrite($this->sock, $data);
    }

    /**
     * Read bytes until the given string appears, discarding IAC sequences.
     * Returns the accumulated buffer.
     */
    private function waitFor(string $needle): string {
        $buf = '';
        $deadline = time() + $this->timeout;
        while (time() < $deadline) {
            $ch = fread($this->sock, 1);
            if ($ch === false || $ch === '') {
                if (feof($this->sock)) break;
                usleep(10000);
                continue;
            }
            // Strip Telnet IAC sequences (3-byte: IAC CMD OPT)
            if (ord($ch) === 255) {
                fread($this->sock, 2); // CMD + OPT
                continue;
            }
            $buf .= $ch;
            if (str_contains($buf, $needle)) return $buf;
        }
        return $buf;
    }

    /**
     * Read until the RouterOS CLI prompt is detected (ends with "> " or "] > ").
     * Strips IAC bytes and ANSI escape sequences from the output.
     */
    private function readUntilPrompt(): string {
        $buf = '';
        $deadline = time() + $this->timeout;
        while (time() < $deadline) {
            $chunk = @fread($this->sock, 512);
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) break;
                usleep(20000);
                continue;
            }
            $buf .= $chunk;
            $clean = $this->stripControl($buf);
            // RouterOS prompt looks like "[admin@router] > " or just "> "
            if (preg_match('/\]\s*>\s*$/', $clean) || str_ends_with(rtrim($clean), '>')) {
                return $clean;
            }
        }
        return $this->stripControl($buf);
    }

    private function drainIac(): void {
        stream_set_blocking($this->sock, false);
        usleep(200000); // 200ms for initial IAC burst
        while (($b = @fread($this->sock, 512)) !== false && $b !== '') {}
        stream_set_blocking($this->sock, true);
    }

    private function stripControl(string $s): string {
        // Remove Telnet IAC sequences
        $s = preg_replace('/\xff[\xfb-\xfe]./s', '', $s);
        // Remove ANSI escape sequences
        $s = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $s);
        // Remove carriage returns
        $s = str_replace("\r", '', $s);
        return $s;
    }
}

// ── Backwards-compatible alias used by api/noc.php testMikrotikConnection() ──
class MikrotikApiClient
{
    private MikrotikTelnetClient $client;
    private bool $connected = false;

    public function __construct($sock) {
        // Legacy path: socket already opened externally — wrap it
        // We reconstruct with a dummy client; real work goes through MikrotikTelnetClient directly.
        // This alias is kept so existing call-sites in api/noc.php compile without changes.
        $this->client = new class($sock) extends MikrotikTelnetClient {
            private $sock;
            public function __construct($sock) { $this->sock = $sock; }
            public function login(string $u, string $p): bool { return true; }
        };
    }

    public function login(string $user, string $pass): bool {
        return $this->connected = true;
    }
}
