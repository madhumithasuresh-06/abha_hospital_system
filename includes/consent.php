<?php

require_once __DIR__ . '/../config/database.php';

function expireConsentRequests(PDO $pdo): void
{
    $statement = $pdo->prepare("UPDATE consent_requests SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP");
    $statement->execute();
}

function hasValidConsent(PDO $pdo, int $patientId, int $requesterId): bool
{
    expireConsentRequests($pdo);
    $statement = $pdo->prepare(
        "SELECT cr.id FROM consent_requests cr
         JOIN consent_records record ON record.consent_request_id = cr.id
         WHERE cr.patient_id = :patient_id AND cr.requested_by = :requester_id
           AND record.decision = 'approved' AND cr.status = 'approved'
           AND (cr.expires_at IS NULL OR cr.expires_at > CURRENT_TIMESTAMP)
         ORDER BY record.decision_at DESC LIMIT 1"
    );
    $statement->execute([':patient_id' => $patientId, ':requester_id' => $requesterId]);
    return (bool) $statement->fetchColumn();
}