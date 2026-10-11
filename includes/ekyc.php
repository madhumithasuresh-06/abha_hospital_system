<?php

interface AadhaarEKYCServiceInterface
{
    public static function ensureVerificationRecord(PDO $pdo, int $identityId, array $documentData = []): array;
    public static function completeVerification(PDO $pdo, int $identityId, array $verification): array;
}

function getEkycChallenge(): ?array
{
    $challenge = $_SESSION['ekyc_otp_challenge'] ?? null;

    if (!is_array($challenge)) {
        return null;
    }

    if (($challenge['expires_at'] ?? 0) <= time()) {
        clearEkycChallenge();
        return null;
    }

    return $challenge;
}

function clearEkycChallenge(): void
{
    unset($_SESSION['ekyc_otp_challenge']);
}

function maskMobileNumber(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    if (strlen($digits) <= 4) {
        return $mobile;
    }

    return substr($digits, 0, 2) . str_repeat('*', max(1, strlen($digits) - 4)) . substr($digits, -2);
}

function calculateOfflineEkycMobileHash(string $mobile, string $shareCode, int $iterations): string
{
    $hash = $mobile . $shareCode;

    for ($index = 0; $index < max(1, $iterations); $index++) {
        $hash = hash('sha256', $hash);
    }

    return $hash;
}

function validateOfflineEkycMobile(string $mobile, string $shareCode, string $expectedHash, int $iterations): bool
{
    $normalizedMobile = preg_replace('/\D+/', '', trim($mobile));
    $hashIsValid = preg_match('/^[a-f0-9]{64}$/i', $expectedHash) === 1;
    $calculatedHash = null;
    $matches = false;

    if (strlen($normalizedMobile) === 10 && $hashIsValid && $iterations > 0) {
        $calculatedHash = calculateOfflineEkycMobileHash($normalizedMobile, $shareCode, $iterations);
        $matches = hash_equals(strtolower($expectedHash), $calculatedHash);
    }

    $debug = [
        'mobile_hash_present' => $expectedHash !== '' ? 'YES' : 'NO',
        'xml_hash_length' => strlen($expectedHash),
        'xml_hash_prefix' => substr(strtolower($expectedHash), 0, 8),
        'hash_iterations' => $iterations,
        'mobile_masked' => maskMobileNumber($normalizedMobile),
        'mobile_digit_count' => strlen($normalizedMobile),
        'share_code_length' => strlen($shareCode),
        'calculated_hash_prefix' => $calculatedHash !== null ? substr($calculatedHash, 0, 8) : '',
        'match' => $matches ? 'YES' : 'NO',
    ];
    $_SESSION['ekyc_mobile_hash_debug'] = $debug;
    error_log('Offline e-KYC mobile hash debug: ' . json_encode($debug, JSON_UNESCAPED_SLASHES));

    return $matches;
}

function sendOtpDelivery(string $destination, string $otp, int $expiryMinutes, string $deliveryType = 'email'): bool
{
    if ($deliveryType === 'mobile') {
        $_SESSION['otp_debug_code'] = $otp;
        error_log('OTP mobile delivery queued for ' . maskMobileNumber($destination) . ' via demo sandbox provider.');
        return true;
    }

    return sendOtpEmail($destination, $otp, $expiryMinutes);
}

function beginEkycOtpChallenge(int $identityId, string $destination, string $referenceCode, string $deliveryType = 'email'): array
{
    $expiryMinutes = (int) env('OTP_EXPIRY_MINUTES', 5);
    $resendCooldown = (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60);
    $maxAttempts = (int) env('OTP_MAX_ATTEMPTS', 5);
    $otp = generateSecureOtp();

    $_SESSION['ekyc_otp_challenge'] = [
        'identity_id' => $identityId,
        'delivery_type' => $deliveryType,
        'destination' => $destination,
        'reference_code' => $referenceCode,
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + ($expiryMinutes * 60),
        'otp_attempts_left' => $maxAttempts,
        'resend_cooldown_until' => time() + $resendCooldown,
        'sent_at' => time(),
    ];

    $sent = sendOtpDelivery($destination, $otp, $expiryMinutes, $deliveryType);

    return [
        'success' => $sent,
        'delivery_type' => $deliveryType,
        'destination' => $destination,
        'masked_destination' => $deliveryType === 'mobile' ? maskMobileNumber($destination) : maskEmail($destination),
        'reference_code' => $referenceCode,
    ];
}

function verifyEkycOtpChallenge(string $submittedOtp): bool
{
    $challenge = getEkycChallenge();

    if (!$challenge) {
        return false;
    }

    if (password_verify($submittedOtp, $challenge['otp_hash'] ?? '')) {
        clearEkycChallenge();
        return true;
    }

    $remainingAttempts = max(0, (int) ($challenge['otp_attempts_left'] ?? 0) - 1);
    $challenge['otp_attempts_left'] = $remainingAttempts;
    $_SESSION['ekyc_otp_challenge'] = $challenge;

    if ($remainingAttempts <= 0) {
        clearEkycChallenge();
    }

    return false;
}

function normalizeDateForComparison(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat('!'.$format, $value);
        if ($date instanceof DateTime) {
            $errors = DateTime::getLastErrors();
            if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            return $date->format('Y-m-d');
        }
    }

    return '';
}

function normalizeAddressForComparison(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9 ,\/\-]+/', '', $value);

    return trim((string) $value);
}

function buildEkycComparisonSummary(array $identity, array $parsedDetails): array
{
    $extractedFields = is_array($parsedDetails['extracted_fields'] ?? null) ? $parsedDetails['extracted_fields'] : [];
    $fields = [];

    $normalizedIdentityName = normalizeEkycComparisonValue((string) ($identity['full_name'] ?? ''));
    $normalizedExtractedName = normalizeEkycComparisonValue((string) ($extractedFields['name'] ?? ''));

    $fields['full_name'] = [
        'label' => 'Full name',
        'expected' => $identity['full_name'] ?? '',
        'actual' => $extractedFields['name'] ?? null,
        'matched' => $normalizedIdentityName !== '' && $normalizedExtractedName !== '' && $normalizedIdentityName === $normalizedExtractedName,
    ];

    $normalizedIdentityDob = normalizeDateForComparison((string) ($identity['date_of_birth'] ?? ''));
    $normalizedExtractedDob = normalizeDateForComparison((string) ($extractedFields['date_of_birth'] ?? ''));

    $fields['date_of_birth'] = [
        'label' => 'Date of birth',
        'expected' => $identity['date_of_birth'] ?? '',
        'actual' => $extractedFields['date_of_birth'] ?? null,
        'matched' => $normalizedIdentityDob !== '' && $normalizedExtractedDob !== '' && $normalizedIdentityDob === $normalizedExtractedDob,
    ];

    $allRequiredMatched = true;
    foreach (['full_name', 'date_of_birth'] as $field) {
        if (($fields[$field]['matched'] ?? false) !== true) {
            $allRequiredMatched = false;
        }
    }

    return [
        'fields' => $fields,
        'all_required_fields_matched' => $allRequiredMatched,
    ];
}

class MockEKYCService implements AadhaarEKYCServiceInterface
{
    public static function ensureVerificationRecord(PDO $pdo, int $identityId, array $documentData = []): array
    {
        $verificationQuery = $pdo->prepare('SELECT * FROM mock_ekyc_verifications WHERE health_identity_id = :identity_id ORDER BY id DESC LIMIT 1');
        $verificationQuery->execute([':identity_id' => $identityId]);
        $verification = $verificationQuery->fetch(PDO::FETCH_ASSOC);

        if ($verification) {
            $needsUpdate = false;

            if (($verification['provider'] ?? '') === '') {
                $verification['provider'] = 'demo_sandbox';
                $needsUpdate = true;
            }

            if (!empty($documentData)) {
                $parsedDetails = $verification['parsed_details'] ?? null;
                $parsedData = $parsedDetails ? json_decode($parsedDetails, true) : [];
                $merged = array_replace_recursive($parsedData, $documentData);
                $verification['parsed_details'] = json_encode($merged);
                $verification['document_type'] = $documentData['document_type'] ?? $verification['document_type'];
                $verification['document_file_name'] = $documentData['document_file_name'] ?? $verification['document_file_name'];
                $verification['provider'] = $documentData['provider'] ?? $verification['provider'] ?? 'demo_sandbox';
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $update = $pdo->prepare(
                    'UPDATE mock_ekyc_verifications
                     SET provider = :provider,
                         document_type = :document_type,
                         document_file_name = :document_file_name,
                         parsed_details = :parsed_details
                     WHERE id = :id'
                );
                $update->execute([
                    ':provider' => $verification['provider'],
                    ':document_type' => $verification['document_type'] ?? 'Academic Identity Document',
                    ':document_file_name' => $verification['document_file_name'] ?? null,
                    ':parsed_details' => $verification['parsed_details'] ?? null,
                    ':id' => (int) $verification['id'],
                ]);
            }

            return $verification;
        }

        $documentType = $documentData['document_type'] ?? 'Academic Identity Document';
        $documentReference = 'IDENTITY-' . str_pad((string) $identityId, 6, '0', STR_PAD_LEFT);
        $insert = $pdo->prepare(
            'INSERT INTO mock_ekyc_verifications
                (health_identity_id, document_type, document_reference, document_file_name, provider, parsed_details, verification_status)
             VALUES
                (:health_identity_id, :document_type, :document_reference, :document_file_name, :provider, :parsed_details, "pending")'
        );
        $insert->execute([
            ':health_identity_id' => $identityId,
            ':document_type' => $documentType,
            ':document_reference' => $documentReference,
            ':document_file_name' => $documentData['document_file_name'] ?? null,
            ':provider' => $documentData['provider'] ?? 'demo_sandbox',
            ':parsed_details' => !empty($documentData) ? json_encode($documentData) : null,
        ]);

        $verificationId = (int) $pdo->lastInsertId();

        return [
            'id' => $verificationId,
            'health_identity_id' => $identityId,
            'document_type' => $documentType,
            'document_reference' => $documentReference,
            'document_file_name' => $documentData['document_file_name'] ?? null,
            'provider' => $documentData['provider'] ?? 'demo_sandbox',
            'parsed_details' => !empty($documentData) ? json_encode($documentData) : null,
            'verification_status' => 'pending',
        ];
    }

    public static function updateParsedDetails(PDO $pdo, int $identityId, array $updates): array
    {
        $verificationQuery = $pdo->prepare('SELECT * FROM mock_ekyc_verifications WHERE health_identity_id = :identity_id ORDER BY id DESC LIMIT 1');
        $verificationQuery->execute([':identity_id' => $identityId]);
        $verification = $verificationQuery->fetch(PDO::FETCH_ASSOC);

        if (!$verification) {
            return [];
        }

        $parsedDetails = $verification['parsed_details'] ? json_decode($verification['parsed_details'], true) : [];
        if (!is_array($parsedDetails)) {
            $parsedDetails = [];
        }

        foreach ($updates as $key => $value) {
            if ($key === 'state') {
                $parsedDetails['state'] = $value;
            } elseif (($key === 'consent_given_at' || $key === 'comparison_summary' || $key === 'verified_at' || $key === 'ocr_status' || $key === 'ocr_note' || $key === 'consent_required')) {
                $parsedDetails[$key] = $value;
            }
        }

        $update = $pdo->prepare('UPDATE mock_ekyc_verifications SET parsed_details = :parsed_details WHERE id = :id');
        $update->execute([
            ':parsed_details' => json_encode($parsedDetails),
            ':id' => (int) $verification['id'],
        ]);

        return [
            'id' => (int) $verification['id'],
            'parsed_details' => $parsedDetails,
        ];
    }

    public static function completeVerification(PDO $pdo, int $identityId, array $verification): array
    {
        $updateVerification = $pdo->prepare(
            'UPDATE mock_ekyc_verifications
             SET verification_status = "verified",
                 verified_at = CURRENT_TIMESTAMP,
                 document_reference = COALESCE(document_reference, :reference)
             WHERE id = :id'
        );
        $updateVerification->execute([
            ':reference' => $verification['document_reference'] ?? 'IDENTITY-' . str_pad((string) $identityId, 6, '0'),
            ':id' => (int) $verification['id'],
        ]);

        $updateIdentity = $pdo->prepare('UPDATE demo_health_identities SET verification_status = "verified" WHERE id = :id');
        $updateIdentity->execute([':id' => $identityId]);

        return [
            'success' => true,
            'verification_status' => 'verified',
        ];
    }
}

class AuthorizedAadhaarEKYCService extends MockEKYCService
{
    public static function ensureVerificationRecord(PDO $pdo, int $identityId, array $documentData = []): array
    {
        $record = parent::ensureVerificationRecord($pdo, $identityId, array_merge($documentData, ['provider' => 'authorized_mock_sandbox']));
        $record['provider'] = 'authorized_mock_sandbox';
        return $record;
    }
}
