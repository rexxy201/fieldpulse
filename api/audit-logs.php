<?php
require_once __DIR__ . '/../config.php';
requireAuth();
if (!isAdmin()) jsonResponse(['error'=>'Forbidden'],403);
jsonResponse(dbFetchAll("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 200"));
