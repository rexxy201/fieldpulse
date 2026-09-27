<?php
/**
 * Webhook Retry Queue — call via cron every 5-10 minutes:
 *   curl -s "https://fieldpulse.mangonetonline.com/api/webhook-retry?token=YOUR_TOKEN"
 *
 * Picks up webhook_deliveries left 'pending' after fireWebhooks()'s initial
 * synchronous attempt failed, and retries on a backoff schedule (see
 * WEBHOOK_RETRY_BACKOFF_MIN in config.php) until delivered or it gives up
 * after WEBHOOK_MAX_ATTEMPTS. Same optional token-guard pattern as the
 * other cron-called endpoints.
 */
require_once __DIR__ . '/../config.php';

// Token guard — required. No slaCheckToken configured means this endpoint
// refuses every request; set it via Admin -> Automation & Cron Tokens.
requireCronToken('slaCheckToken');

$results = retryFailedWebhooks();
jsonResponse(['ok' => true] + $results);
