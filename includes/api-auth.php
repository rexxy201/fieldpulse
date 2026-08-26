<?php
/**
 * Auth for the external Integration API (api/v1/*). Completely separate from
 * requireAuth()/requirePermission() — those are for the session-based staff
 * app. External callers authenticate with a per-integration API key instead:
 *
 *   Authorization: Bearer fp_live_<random>
 *
 * Call requireApiScope('customers.read') at the top of each api/v1/*.php
 * endpoint before touching any data. It exits with a JSON 401/403 on failure,
 * and returns the api_keys row (as array) on success.
 */

function currentApiKey(): ?array {
    static $resolved = false;
    static $key = null;
    if ($resolved) return $key;
    $resolved = true;

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $h => $v) {
            if (strtolower($h) === 'authorization') { $header = $v; break; }
        }
    }
    if (!preg_match('/^Bearer\s+(fp_live_[a-f0-9]{48})$/', trim($header), $m)) return null;

    $hash = hash('sha256', $m[1]);
    $row = dbFetch("SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL", [$hash]);
    if (!$row) return null;

    try { dbRun("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?", [$row['id']]); } catch (\Throwable $e) {}
    $key = $row;
    return $key;
}

function apiKeyHasScope(array $key, string $scope): bool {
    $scopes = array_map('trim', explode(',', $key['scopes'] ?? ''));
    return in_array($scope, $scopes, true);
}

function logApiRequest(?string $apiKeyId, int $statusCode): void {
    if (!$apiKeyId) return;
    try {
        dbRun("INSERT INTO api_request_log (id,api_key_id,method,path,status_code,ip) VALUES (?,?,?,?,?,?)", [
            newUuid(), $apiKeyId, method(),
            parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            $statusCode, $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {}
}

function requireApiScope(string $scope): array {
    header('Content-Type: application/json; charset=utf-8');
    $key = currentApiKey();
    if (!$key) {
        logApiRequest(null, 401);
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid API key. Send it as: Authorization: Bearer <key>']);
        exit;
    }
    if (!apiKeyHasScope($key, $scope)) {
        logApiRequest($key['id'], 403);
        http_response_code(403);
        echo json_encode(['error' => "This API key does not have the '{$scope}' scope."]);
        exit;
    }
    // Approximate — the endpoint's own outcome (e.g. a 404 or 400 further in)
    // isn't known yet here, but recording the successful auth+scope check is
    // enough for basic usage visibility without every endpoint having to
    // remember to log itself.
    logApiRequest($key['id'], 200);
    return $key;
}
