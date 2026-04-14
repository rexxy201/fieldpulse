<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) jsonResponse(['error' => 'Forbidden'], 403);
if (method() !== 'POST') jsonResponse(['error' => 'POST required'], 405);

$user = currentUser();
$toEmail = $user['email'] ?? '';
if (!$toEmail) {
    // Fall back to the SMTP username as recipient if admin has no email set
    $cfg     = getAppConfig();
    $toEmail = $cfg['smtpUser'] ?? '';
}
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'No valid recipient — please set your email address in Team settings, or ensure the SMTP Username is a valid email.'], 400);
}

$appName = getAppConfig()['companyName'] ?? 'FieldPulse';
$subject = "[$appName] SMTP Test Email";
$html    = "
<div style='font-family:Inter,Arial,sans-serif;max-width:520px;margin:auto;padding:32px 24px;background:#f8fafc;border-radius:8px'>
  <h2 style='color:#0ea5e9;margin:0 0 8px'>✅ SMTP is working!</h2>
  <p style='color:#374151;margin:0 0 16px'>This test email confirms that your <strong>{$appName}</strong> email settings are configured correctly.</p>
  <table style='width:100%;border-collapse:collapse;font-size:14px;color:#374151'>
    <tr><td style='padding:6px 0;color:#6b7280'>Sent to</td><td style='padding:6px 0'>" . htmlspecialchars($toEmail) . "</td></tr>
    <tr><td style='padding:6px 0;color:#6b7280'>Sent at</td><td style='padding:6px 0'>" . date('D, d M Y H:i:s T') . "</td></tr>
    <tr><td style='padding:6px 0;color:#6b7280'>Sent by</td><td style='padding:6px 0'>" . htmlspecialchars($user['name'] ?? 'Admin') . "</td></tr>
  </table>
  <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0'>
  <p style='color:#9ca3af;font-size:12px;margin:0'>You can safely ignore this email — it was triggered from the Admin panel in {$appName}.</p>
</div>
";

try {
    sendEmail($toEmail, $user['name'] ?? 'Admin', $subject, $html);
    jsonResponse(['ok' => true, 'sent_to' => $toEmail]);
} catch (\Exception $e) {
    // Return the PHPMailer error message so the admin can diagnose the problem
    jsonResponse(['error' => $e->getMessage()], 500);
}
