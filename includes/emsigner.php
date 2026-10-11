<?php

require_once __DIR__ . '/env.php';
loadEnvironment(dirname(__DIR__));
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class EmSignerException extends RuntimeException {}

/**
 * Returns emSigner configuration from environment.
 * Secrets are loaded dynamically from .env and never hard-coded.
 */
function emsignerGetConfig(): array
{
    return [
        'base_url'       => rtrim(trim((string)env('EMSIGNER_BASE_URL', '')), '/'),
        'api_prefix'     => trim((string)env('EMSIGNER_API_PREFIX', '/api')),
        'app_name'       => trim((string)env('EMSIGNER_APP_NAME', '')),
        'secret_key'     => trim((string)env('EMSIGNER_SECRET_KEY', '')),
        'auth_token'     => trim((string)env('EMSIGNER_AUTH_TOKEN', '')),
        'approver_name'  => trim((string)env('APPROVER_NAME', '')),
        'approver_email' => trim((string)env('APPROVER_EMAIL', '')),
        'callback_url'   => trim((string)env('EMSIGNER_CALLBACK_URL', '')),
        'timeout'        => max(5, (int)env('EMSIGNER_REQUEST_TIMEOUT', '30')),
        'ssl_verify'     => (string)env('EMSIGNER_SSL_VERIFY', '1') !== '0',
    ];
}

/**
 * Validates the REST configuration without exposing any credential values.
 */
function emsignerCheckRequirements(): array
{
    $config = emsignerGetConfig();
    $missing = [];

    if ($config['base_url'] === '') {
        $missing[] = 'EMSIGNER_BASE_URL is not configured in .env.';
    }
    if ($config['app_name'] === '') {
        $missing[] = 'EMSIGNER_APP_NAME is not configured in .env.';
    }
    if ($config['secret_key'] === '' && $config['auth_token'] === '') {
        $missing[] = 'Configure EMSIGNER_SECRET_KEY or EMSIGNER_AUTH_TOKEN in .env.';
    }
    if ($config['approver_name'] === '') {
        $missing[] = 'APPROVER_NAME is not configured in .env.';
    }
    if ($config['approver_email'] === '' || !filter_var($config['approver_email'], FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'APPROVER_EMAIL is not configured with a valid email address in .env.';
    }
    if ($config['callback_url'] === '') {
        $missing[] = 'EMSIGNER_CALLBACK_URL is not configured in .env.';
    }

    return $missing;
}

/** @return array{status:int,body:array} */
function emsignerRequest(string $path, array $payload, ?string $authToken = null, string $method = 'POST'): array
{
    $config = emsignerGetConfig();
    $url = $config['base_url'] . '/' . trim($config['api_prefix'], '/') . '/' . ltrim($path, '/');
    $method = strtoupper($method);
    $headers = ['Accept: application/json'];
    if ($authToken !== null && $authToken !== '') {
        $headers[] = 'Authorization: basic ' . $authToken;
    }

    $json = null;
    if ($method === 'GET') {
        $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') {
            $url .= '?' . $query;
        }
    } else {
        $headers[] = 'Content-Type: application/json';
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $ch = curl_init($url);

    if ($ch === false) {
        throw new EmSignerException('Could not initialize the emSigner connection.');
    }
    $curlOptions = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CAINFO => 'C:\xampp\htdocs\abha_hospital_system\.venv\Lib\site-packages\pip\_vendor\certifi\cacert.pem',
        CURLOPT_SSL_VERIFYPEER => $config['ssl_verify'],
        CURLOPT_SSL_VERIFYHOST => $config['ssl_verify'] ? 2 : 0,
    ];
    if ($method !== 'GET') {
        $curlOptions[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $curlOptions);
    $body = curl_exec($ch);
    if ($body === false) {
        curl_close($ch);
        throw new EmSignerException('Could not reach emSigner.');
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $bodyLength = strlen((string)$body);
    $bodyPrefix = substr((string)$body, 0, 200);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        $secrets = [
            $config['secret_key'],
            $config['auth_token'],
        ];
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $bodyPrefix = str_replace($secret, '[redacted]', $bodyPrefix);
                $curlError = str_replace($secret, '[redacted]', $curlError);
            }
        }
        $bodyPrefix = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $bodyPrefix);
        $bodyPrefix = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '[redacted-token]', $bodyPrefix);
        $bodyPrefix = preg_replace('/[A-Za-z0-9+\/]{80,}={0,2}/', '[redacted-data]', $bodyPrefix);
        throw new EmSignerException(
            'emSigner non-JSON response; HTTP_STATUS=' . $status
            . '; CURL_ERRNO=' . $curlErrno
            . '; CURL_ERROR=' . $curlError
            . '; CONTENT_TYPE=' . $contentType
            . '; BODY_LENGTH=' . $bodyLength
            . '; BODY_PREFIX=' . $bodyPrefix
        );
    }
    if ($status < 200 || $status >= 300) {
        throw new EmSignerException('emSigner request failed with HTTP status ' . $status . '.');
    }
    return ['status' => $status, 'body' => $decoded];
}

function emsignerAuthorize(): string
{
    $config = emsignerGetConfig();
    if ($config['secret_key'] === '') {
        if ($config['auth_token'] !== '') {
            return $config['auth_token'];
        }
        throw new EmSignerException('emSigner application credentials are not configured.');
    }
    $response = emsignerRequest('AuthorizeApp', [
        'AppName' => $config['app_name'],
        'SecretKey' => $config['secret_key'],
    ]);
    $body = $response['body'];
    $token = $body['Response']['AuthToken'] ?? $body['AuthToken'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new EmSignerException('emSigner authorization did not return an AuthToken.');
    }
    return $token;
}

/** @return array{reference:string,workflow_id:?string,document_id:?string,status:?string,signing_url:?string,response:array} */
function emsignerInitiateSigning(
    string $pdfFilePath,
    string $referenceNumber,
    string $doctorName,
    string $doctorEmail
): array {
    if (!is_file($pdfFilePath)) {
        throw new EmSignerException('The prescription PDF could not be found.');
    }
    if ($doctorName === '' || !filter_var($doctorEmail, FILTER_VALIDATE_EMAIL)) {
        throw new EmSignerException('The doctor profile does not contain a valid signing email.');
    }
    $pdfBytes = file_get_contents($pdfFilePath);
    if ($pdfBytes === false || $pdfBytes === '') {
        throw new EmSignerException('The prescription PDF could not be read.');
    }

    $config = emsignerGetConfig();
    if ($config['approver_name'] === '' || !filter_var($config['approver_email'], FILTER_VALIDATE_EMAIL)) {
        throw new EmSignerException('The emSigner approver configuration is incomplete.');
    }
    $result = emsignerRequest('InitiateEmbeddedSigning', [
        'WorkflowType' => 1,
        'ReferenceNo' => $referenceNumber,
        'RedirectURL' => $config['callback_url'],
        'Name' => $doctorName,
        'EmailId' => $doctorEmail,
        'DoNotNotifyCustomer' => false,
        'DonotSendCompletionMailToParticipants' => true,
        'SignatoryEmailIds' => [$doctorEmail, $config['approver_email']],
        'SignatureSettings' => [[
            'ModeOfSignature' => '3',
            'Name' => $config['approver_name'],
        ]],
        'lstDocumentDetails' => [[
            'DocumentName' => basename($pdfFilePath),
            'FileData' => base64_encode($pdfBytes),
            'ControlDetails' => [[
                'Width' => 160,
                'Top' => 127,
                'Page' => 'All',
                'Left' => 40,
                'Height' => 83,
                'ControlID' => 4,
                'AssignedTo' => 1,
            ]],
        ]],
    ], emsignerAuthorize());

    $body = $result['body'];
    $response = $body['Response'] ?? $body;
    $reference = $response['ReferenceNo'] ?? $referenceNumber;
    $workflowId = $response['WorkflowId'] ?? null;
    $workflowStatus = $response['Status'] ?? null;
    $signingUrl = $response['URL'] ?? null;
    $documentId = $response['DocumentIdList'][0] ?? null;
    return [
        'reference' => (string)$reference,
        'workflow_id' => is_scalar($workflowId) ? (string)$workflowId : null,
        'document_id' => is_scalar($documentId) ? (string)$documentId : null,
        'status' => is_scalar($workflowStatus) ? (string)$workflowStatus : null,
        'signing_url' => is_string($signingUrl) && $signingUrl !== '' ? $signingUrl : null,
        'response' => $body,
    ];
}

/** @return array{status:array,response:array,workflow_id:?string,signatories:array,http_status:int} */
function emsignerGetWorkflowInfo(string $workflowId, string $referenceNumber): array
{
    $parameters = [];
    if ((int)$workflowId > 0) {
        $parameters['WorkFlowId'] = (int)$workflowId;
    }
    $result = emsignerRequest('GetWorkflowInfo', $parameters, emsignerAuthorize(), 'GET');
    $response = $result['body']['Response'] ?? [];
    if (isset($response[0]) && is_array($response[0])) {
        $response = $response[0];
    }
    $status = is_array($response) ? $response : [];
    $returnedWorkflowId = $status['WorkflowId'] ?? $status['WorkFlowId'] ?? null;
    $signatories = is_array($status['Signatories'] ?? null) ? $status['Signatories'] : [];
    return [
        'status' => $status,
        'response' => $result['body'],
        'workflow_id' => is_scalar($returnedWorkflowId) && (int)$returnedWorkflowId > 0 ? (string)$returnedWorkflowId : null,
        'signatories' => $signatories,
        'http_status' => $result['status'],
    ];
}

function emsignerWorkflowIsCompleted(array $workflowInfo): bool
{
    return strcasecmp((string)($workflowInfo['WorkflowStatus'] ?? ''), 'Completed') === 0;
}

function emsignerWorkflowIsFailed(array $workflowInfo): bool
{
    $status = strtolower((string)($workflowInfo['WorkflowStatus'] ?? ''));
    return in_array($status, ['failed', 'rejected', 'declined', 'cancelled', 'canceled'], true);
}

function emsignerSignatoriesCompleted(array $signatories): bool
{
    if ($signatories === []) {
        return false;
    }
    foreach ($signatories as $signatory) {
        $status = strtolower(trim((string)($signatory['Status'] ?? '')));
        if (!in_array($status, ['completed', 'complete', 'signed', 'approved'], true)) {
            return false;
        }
    }
    return true;
}

function emsignerWorkflowDiagnostic(array $workflow): string
{
    $response = $workflow['response'];
    $info = $workflow['status'];
    $messages = $response['Messages'] ?? [];
    $message = is_array($messages) ? implode(' | ', array_map('strval', $messages)) : (string)$messages;
    $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message);
    $documentInfo = $info['DocumentInfo'] ?? [];
    if (is_array($documentInfo) && isset($documentInfo['DocumentId'])) {
        $documentInfo = [$documentInfo];
    }
    $documentIds = [];
    foreach (is_array($documentInfo) ? $documentInfo : [] as $document) {
        if (is_array($document) && isset($document['DocumentId']) && is_scalar($document['DocumentId'])) {
            $documentIds[] = (string)$document['DocumentId'];
        }
    }
    $signatoryStatuses = [];
    foreach ($workflow['signatories'] as $signatory) {
        $signatoryStatuses[] = is_array($signatory) ? (string)($signatory['Status'] ?? 'missing') : 'invalid';
    }
    return 'GetWorkflowInfo: HTTP_STATUS=' . (int)$workflow['http_status']
        . '; API_SUCCESS=' . var_export($response['IsSuccess'] ?? null, true)
        . '; API_ERROR_CODE=' . (string)($response['ErrorCode'] ?? 'null')
        . '; API_MESSAGE=' . $message
        . '; WORKFLOW_STATUS=' . json_encode((string)($info['WorkflowStatus'] ?? ''), JSON_UNESCAPED_SLASHES)
        . '; WORKFLOW_ID=' . ($workflow['workflow_id'] ?? 'missing')
        . '; REFERENCE_NO_PRESENT=' . (isset($info['ReferenceNo']) || isset($response['ReferenceNo']) ? 'YES' : 'NO')
        . '; DOCUMENT_INFO_COUNT=' . count(is_array($documentInfo) ? $documentInfo : [])
        . '; DOCUMENT_IDS=' . ($documentIds === [] ? 'none' : implode(',', $documentIds))
        . '; SIGNATORIES_COUNT=' . count($workflow['signatories'])
        . '; SIGNATORY_STATUSES=' . ($signatoryStatuses === [] ? 'none' : implode(',', $signatoryStatuses))
        . '; ALL_SIGNATORIES_COMPLETED=' . (emsignerSignatoriesCompleted($workflow['signatories']) ? 'YES' : 'NO');
}

function emsignerWorkflowDocumentId(array $workflowInfo): ?string
{
    $documentInfo = $workflowInfo['DocumentInfo'] ?? null;
    if (is_array($documentInfo) && isset($documentInfo[0]) && is_array($documentInfo[0])) {
        $documentInfo = $documentInfo[0];
    }
    $documentId = is_array($documentInfo) ? ($documentInfo['DocumentId'] ?? null) : null;
    return is_scalar($documentId) && (string)$documentId !== '' ? (string)$documentId : null;
}

/** @return string signed PDF bytes */
function emsignerDownloadSigned(string $workflowId, string $documentId, string $referenceNumber): string
{
    $result = emsignerRequest('DownloadWorkflowDocuments', [
        'WorkFlowId' => (int)$workflowId,
        'DocumentId' => (int)$documentId,
        'ReferenceNo' => $referenceNumber,
        'IsMerged' => true,
    ], emsignerAuthorize());

    $body = $result['body'];
    $fileList = $body['Response']['FileList'] ?? null;
    $encoded = is_array($fileList) ? ($fileList[0]['Base64FileData'] ?? null) : null;
    if (!is_string($encoded) || $encoded === '') {
        throw new EmSignerException('emSigner did not return a signed PDF.');
    }
    $signedBytes = base64_decode($encoded, true);
    if ($signedBytes === false || !str_starts_with($signedBytes, '%PDF')) {
        throw new EmSignerException('emSigner returned an invalid signed PDF.');
    }
    return $signedBytes;
}

/** @return array{status:string,document_id:?string,workflow_id:?string,diagnostic:string} */
function emsignerFinalizePrescription(
    PDO $pdo,
    int $prescriptionId,
    string $workflowId,
    string $referenceNumber,
    ?string $transactionId = null,
    ?string $storedDocumentId = null
): array {
    $workflow = emsignerGetWorkflowInfo($workflowId, $referenceNumber);
    $info = $workflow['status'];
    $effectiveWorkflowId = $workflow['workflow_id'] ?? ((int)$workflowId > 0 ? $workflowId : null);
    $diagnostic = emsignerWorkflowDiagnostic($workflow);
    if (emsignerWorkflowIsFailed($info)) {
        return ['status' => 'failed', 'document_id' => null, 'workflow_id' => $effectiveWorkflowId, 'diagnostic' => $diagnostic];
    }
    if (!emsignerWorkflowIsCompleted($info)) {
        return [
            'status' => strtolower((string)($info['WorkflowStatus'] ?? 'awaiting_signature')),
            'document_id' => null,
            'workflow_id' => $effectiveWorkflowId,
            'diagnostic' => $diagnostic,
        ];
    }
    if (!emsignerSignatoriesCompleted($workflow['signatories'] ?? [])) {
        return [
            'status' => 'awaiting_signature',
            'document_id' => null,
            'workflow_id' => $effectiveWorkflowId,
            'diagnostic' => $diagnostic,
        ];
    }
    if ($effectiveWorkflowId === null) {
        throw new EmSignerException('Cannot download signed PDF: completed workflow has no valid workflow ID. ' . $diagnostic);
    }
    $documentId = emsignerWorkflowDocumentId($info) ?? ((is_string($storedDocumentId) && (int)$storedDocumentId > 0) ? $storedDocumentId : null);
    if ($documentId === null) {
        throw new EmSignerException('Cannot download signed PDF: completed workflow has no document ID. ' . $diagnostic);
    }
    $signedBytes = emsignerDownloadSigned($effectiveWorkflowId, $documentId, $referenceNumber);
    if (!emsignerSaveSignedPrescription($pdo, $prescriptionId, $signedBytes, $transactionId)) {
        throw new EmSignerException('The signed PDF could not be stored.');
    }
    return ['status' => 'signed', 'document_id' => $documentId, 'workflow_id' => $effectiveWorkflowId, 'diagnostic' => $diagnostic];
}

/**
 * Generates a unique transaction reference number.
 */
function emsignerGenerateReferenceNumber(int $prescriptionId): string
{
    return 'RX' . $prescriptionId . 'T' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Securely validates and saves a digitally signed PDF returned by the emSigner service.
 *
 * @param PDO    $pdo Database connection
 * @param int    $prescriptionId Prescription ID
 * @param string $signedPayload Raw binary or base64-encoded PDF data
 * @param string|null $transactionId Optional transaction ID
 * @return bool True if valid PDF was saved and database updated
 */
function emsignerSaveSignedPrescription(
    PDO $pdo,
    int $prescriptionId,
    string $signedPayload,
    ?string $transactionId = null
): bool {
    $signedBytes = null;

    // Check if raw binary PDF
    if (str_starts_with($signedPayload, '%PDF')) {
        $signedBytes = $signedPayload;
    } else {
        // Check if base64 encoded
        $cleaned = trim(preg_replace('/\s+/', '', $signedPayload));
        $decoded = base64_decode($cleaned, true);
        if ($decoded && str_starts_with($decoded, '%PDF')) {
            $signedBytes = $decoded;
        }
    }

    if ($signedBytes === null || strlen($signedBytes) < 50) {
        return false;
    }

    $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'prescriptions';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $signedFileName = 'prescription_' . $prescriptionId . '_signed.pdf';
    $destinationPath = $storageDir . DIRECTORY_SEPARATOR . $signedFileName;
    $relativePdfPath = 'storage/prescriptions/' . $signedFileName;

    $saved = file_put_contents($destinationPath, $signedBytes);
    if ($saved === false || $saved === 0) {
        return false;
    }

    // Update prescription_documents record
    $update = $pdo->prepare("
        UPDATE prescription_documents
        SET signing_status = 'signed',
            signed_pdf_path = :path,
            emsigner_transaction_id = :trans_id,
            emsigner_status = 'Completed',
            emsigner_response_code = 'SUCCESS',
            signed_at = CURRENT_TIMESTAMP
        WHERE prescription_id = :presc_id
    ");
    $update->execute([
        ':path'     => $relativePdfPath,
        ':trans_id' => $transactionId ?: ('EM-' . date('YmdHis')),
        ':presc_id' => $prescriptionId
    ]);

    // Retrieve patient user ID for notification
    $patQuery = $pdo->prepare("
        SELECT p.user_id 
        FROM prescriptions pr 
        JOIN patients p ON p.id = pr.patient_id 
        WHERE pr.id = :id 
        LIMIT 1
    ");
    $patQuery->execute([':id' => $prescriptionId]);
    $patientUserId = $patQuery->fetchColumn();

    if ($patientUserId) {
        createNotification(
            $pdo,
            (int)$patientUserId,
            'Digitally Signed Prescription Available',
            'Your attending doctor has digitally signed your prescription. You can view and download it from your patient portal.'
        );
    }

    logAudit(
        'PRESCRIPTION_SIGNED',
        'prescription',
        $prescriptionId,
        'Prescription digitally signed via emSigner REST API.'
    );

    return true;
}
