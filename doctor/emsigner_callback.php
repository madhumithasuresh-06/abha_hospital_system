<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/emsigner.php';

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
$jsonBody = is_array($jsonBody) ? $jsonBody : [];
$isServerCallback = $_SERVER['REQUEST_METHOD'] === 'POST' && $jsonBody !== [];

$value = static function (string $key) use ($jsonBody): ?string {
    $value = $jsonBody[$key] ?? $_POST[$key] ?? $_GET[$key] ?? null;
    return is_scalar($value) ? trim((string)$value) : null;
};

$status = strtoupper((string)($value('Status') ?? $value('EventType') ?? $_GET['status'] ?? ''));
$referenceNumber = $value('ReferenceNo')
    ?? $value('ReferenceNumber')
    ?? $value('ref')
    ?? $value('referenceno');
$workflowId = $value('WorkflowId')
    ?? $value('WorkFlowId')
    ?? $value('workflowid');
$transactionNumber = $value('TransactionNumber') ?? $value('Transactionnumber') ?? $value('TransactionId');

$respond = static function (int $statusCode, array $body) use ($isServerCallback): never {
    if ($isServerCallback) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }
    if ($statusCode >= 400) {
        flash('danger', (string)($body['error'] ?? 'emSigner callback failed.'));
    } else {
        flash('success', (string)($body['message'] ?? 'emSigner callback processed.'));
    }
    redirect(appUrl('doctor/appointments.php'));
};

if (($referenceNumber === null || $referenceNumber === '') && ($workflowId === null || $workflowId === '')) {
    $respond(400, ['ok' => false, 'error' => 'The emSigner callback did not identify a workflow.']);
}

$docQuery = $pdo->prepare('SELECT id, prescription_id, emsigner_reference_number, emsigner_workflow_id FROM prescription_documents WHERE emsigner_reference_number = :ref OR emsigner_workflow_id = :workflow_id LIMIT 1');
$docQuery->execute([':ref' => $referenceNumber, ':workflow_id' => $workflowId]);
$docRecord = $docQuery->fetch(PDO::FETCH_ASSOC);
if (!$docRecord) {
    $respond(404, ['ok' => false, 'error' => 'No prescription matches the emSigner reference.']);
}

$prescriptionId = (int)$docRecord['prescription_id'];
$referenceNumber = (string)$docRecord['emsigner_reference_number'];
$workflowId = (string)$docRecord['emsigner_workflow_id'];

try {
    if ($workflowId === '') {
        throw new EmSignerException('The emSigner callback did not identify a workflow.');
    }
    $completion = emsignerFinalizePrescription($pdo, $prescriptionId, $workflowId, $referenceNumber, $transactionNumber, null);
    if ($completion['workflow_id'] !== null && $completion['workflow_id'] !== $workflowId) {
        $updateWorkflowId = $pdo->prepare('UPDATE prescription_documents SET emsigner_workflow_id = :workflow_id WHERE id = :id');
        $updateWorkflowId->execute([':workflow_id' => $completion['workflow_id'], ':id' => $docRecord['id']]);
    }
    if ($completion['status'] !== 'signed') {
        if ($completion['status'] === 'failed') {
            $update = $pdo->prepare("UPDATE prescription_documents SET signing_status = 'failed', emsigner_status = 'Failed', emsigner_transaction_id = :transaction_id WHERE id = :id");
            $update->execute([':transaction_id' => $transactionNumber, ':id' => $docRecord['id']]);
        } else {
            $update = $pdo->prepare("UPDATE prescription_documents SET emsigner_status = 'Waiting for signature', emsigner_transaction_id = :transaction_id WHERE id = :id");
            $update->execute([':transaction_id' => $transactionNumber, ':id' => $docRecord['id']]);
        }
        $respond(200, ['ok' => true, 'status' => 'awaiting_signature', 'diagnostic' => $completion['diagnostic']]);
    }
    $updateDocumentId = $pdo->prepare("UPDATE prescription_documents SET emsigner_document_id = :document_id, emsigner_status = 'Completed' WHERE id = :id");
    $updateDocumentId->execute([':document_id' => $completion['document_id'], ':id' => $docRecord['id']]);
    logAudit('EMSIGNER_SIGNED_PDF_RETRIEVED', 'prescription', $prescriptionId, 'Signed PDF retrieved from emSigner REST API.');
    $respond(200, ['ok' => true, 'status' => 'signed', 'prescription_id' => $prescriptionId]);
} catch (Throwable $exception) {
    error_log('emSigner signed PDF retrieval failed for prescription ' . $prescriptionId . '.');
    $update = $pdo->prepare("UPDATE prescription_documents SET emsigner_response_code = 'SIGNED_DOWNLOAD_FAILED', emsigner_transaction_id = :transaction_id WHERE id = :id");
    $update->execute([
        ':transaction_id' => $transactionNumber,
        ':id' => $docRecord['id'],
    ]);
    $respond(502, ['ok' => false, 'error' => 'Signed document retrieval failed.']);
}
