<?php
/**
 * Minimal RouterOS API client (RFC-style binary protocol, port 8728).
 * No external dependencies. Suitable for PHP CLI cron jobs.
 *
 * Usage:
 *   $sock = fsockopen($ip, $port, $errno, $errstr, 5);
 *   $api  = new MikrotikApiClient($sock);
 *   if ($api->login('admin', 'pass')) {
 *       $rows = $api->command('/ppp/active/print');
 *   }
 *   fclose($sock);
 */
class MikrotikApiClient
{
    private $sock;

    public function __construct($sock) {
        $this->sock = $sock;
        stream_set_timeout($sock, 5);
    }

    public function login(string $user, string $pass): bool {
        // RouterOS 6.x uses MD5 challenge; RouterOS 7.x uses plaintext login
        $this->write(['/login', '=name=' . $user, '=password=' . $pass]);
        $resp = $this->read();
        if (isset($resp[0]) && $resp[0] === '!done') return true;
        // ROS 6 challenge-response
        if (isset($resp[0]) && str_starts_with($resp[0], '!re')) {
            foreach ($resp as $word) {
                if (str_starts_with($word, '=ret=')) {
                    $challenge = pack('H*', substr($word, 5));
                    $hash = md5("\x00" . $pass . $challenge);
                    $this->write(['/login', '=name=' . $user, '=response=00' . $hash]);
                    $resp2 = $this->read();
                    return isset($resp2[0]) && $resp2[0] === '!done';
                }
            }
        }
        return false;
    }

    /** Run a command and return array of result rows (each row = assoc array). */
    public function command(string $cmd, array $params = []): array {
        $words = [$cmd];
        foreach ($params as $k => $v) $words[] = "=$k=$v";
        $this->write($words);
        $rows = []; $current = [];
        foreach ($this->read() as $word) {
            if ($word === '!done') {
                if ($current) $rows[] = $current;
                break;
            }
            if ($word === '!re') {
                if ($current) { $rows[] = $current; $current = []; }
            } elseif (str_starts_with($word, '=')) {
                [$k, $v] = explode('=', substr($word, 1), 2) + ['', ''];
                $current[$k] = $v;
            }
        }
        return $rows;
    }

    // ── Protocol internals ────────────────────────────────────────────────

    private function write(array $words): void {
        $sentence = '';
        foreach ($words as $w) {
            $len = strlen($w);
            if ($len < 0x80)        $sentence .= chr($len);
            elseif ($len < 0x4000)  $sentence .= chr(($len >> 8) | 0x80) . chr($len & 0xFF);
            else                    $sentence .= chr(($len >> 24) | 0xC0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
            $sentence .= $w;
        }
        $sentence .= "\x00"; // end of sentence
        fwrite($this->sock, $sentence);
    }

    private function read(): array {
        $words = [];
        while (true) {
            $len = $this->readLen();
            if ($len === 0) break;
            $word = '';
            $remaining = $len;
            while ($remaining > 0) {
                $chunk = fread($this->sock, $remaining);
                if ($chunk === false || $chunk === '') break;
                $word .= $chunk;
                $remaining -= strlen($chunk);
            }
            $words[] = $word;
            if ($word === '!done' || $word === '!trap' || $word === '!fatal') {
                // Read remainder until end-of-sentence (len=0)
                while ($this->readLen() !== 0);
                break;
            }
        }
        return $words;
    }

    private function readLen(): int {
        $b = fread($this->sock, 1);
        if ($b === false || $b === '') return 0;
        $byte = ord($b);
        if ($byte < 0x80)   return $byte;
        if ($byte < 0xC0)   { $b2 = ord(fread($this->sock, 1)); return (($byte & 0x3F) << 8) | $b2; }
        if ($byte < 0xE0)   { $r = fread($this->sock, 2); return (($byte & 0x1F) << 16) | (ord($r[0]) << 8) | ord($r[1]); }
        $r = fread($this->sock, 3);
        return (($byte & 0x0F) << 24) | (ord($r[0]) << 16) | (ord($r[1]) << 8) | ord($r[2]);
    }
}
