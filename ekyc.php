<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/aadhaar.php';
require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/ekyc.php';

$identityId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'identity_id', FILTER_VALIDATE_INT);
if (!$identityId || (int) ($_SESSION['phase2_identity_id'] ?? 0) !== (int) $identityId) {
    http_response_code(403);
    exit('Identity verification session not found.');
}

$identityQuery = $pdo->prepare('SELECT * FROM demo_health_identities WHERE id = :id LIMIT 1');
$identityQuery->execute([':id' => $identityId]);
$identity = $identityQuery->fetch(PDO::FETCH_ASSOC);

if (!$identity) {
    http_response_code(404);
    exit('Health identity not found.');
}

$verificationQuery = $pdo->prepare('SELECT * FROM mock_ekyc_verifications WHERE health_identity_id = :identity_id ORDER BY id DESC LIMIT 1');
$verificationQuery->execute([':identity_id' => $identity['id']]);
$verification = $verificationQuery->fetch(PDO::FETCH_ASSOC);

if (!$verification) {
    $start = $pdo->prepare('INSERT INTO mock_ekyc_verifications (health_identity_id, document_type, document_reference, verification_status, parsed_details) VALUES (:identity_id, :document_type, :document_reference, \'pending\', :parsed_details)');
    $start->execute([
        ':identity_id' => $identity['id'],
        ':document_type' => 'Aadhaar document',
        ':document_reference' => 'IDENTITY-' . str_pad((string) $identity['id'], 6, '0', STR_PAD_LEFT),
        ':parsed_details' => json_encode(['state' => 'extracted', 'consent_required' => true]),
    ]);
    $verificationId = (int) $pdo->lastInsertId();
    logAudit('EKYC_STARTED', 'health_identity', (int) $identity['id'], 'Identity verification started.');
    $verification = [
        'id' => $verificationId,
        'verification_status' => 'pending',
        'document_type' => 'Aadhaar document',
        'document_reference' => 'IDENTITY-' . str_pad((string) $identity['id'], 6, '0', STR_PAD_LEFT),
        'parsed_details' => json_encode(['state' => 'extracted', 'consent_required' => true]),
    ];
}

$parsedDetails = $verification['parsed_details'] ? json_decode($verification['parsed_details'], true) : [];
if (!is_array($parsedDetails)) {
    $parsedDetails = [];
}

$verificationState = $parsedDetails['state'] ?? 'extracted';
$extractedFields = is_array($parsedDetails['extracted_fields'] ?? null) ? $parsedDetails['extracted_fields'] : [];
$comparisonSummary = buildEkycComparisonSummary($identity, $parsedDetails);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $identity['verification_status'] !== 'verified') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? 'consent';

        if ($action === 'consent') {
            $consented = ($_POST['consent'] ?? '') === '1';
            if (!$consented) {
                flash('danger', 'You must explicitly consent to Aadhaar-based eKYC before continuing.');
            } else {
                $parsedDetails['state'] = 'consent_pending';
                $parsedDetails['consent_required'] = true;
                $parsedDetails['consent_given_at'] = date('c');
                MockEKYCService::updateParsedDetails($pdo, $identity['id'], [
                    'state' => 'consent_pending',
                    'consent_required' => true,
                    'consent_given_at' => $parsedDetails['consent_given_at'],
                ]);

                $parsedDetailsFields = is_array($parsedDetails['extracted_fields'] ?? null) ? $parsedDetails['extracted_fields'] : [];
                $offlineEkycFlow = ($parsedDetails['provider'] ?? '') === 'offline_ekyc_file';
                $otpDestination = '';
                $otpDeliveryType = 'mobile';

                if ($offlineEkycFlow) {
                    $enteredMobile = trim((string) ($_POST['aadhaar_mobile'] ?? ''));
                    $shareCode = trim((string) ($_SESSION['offline_ekyc_share_codes'][$identity['id']] ?? ''));
                    $mobileHash = trim((string) ($parsedDetailsFields['mobile_hash'] ?? ''));
                    $iterations = deriveOfflineEkycMobileHashIterations($parsedDetailsFields['reference_id'] ?? null) ?? 0;

                    if ($shareCode === '' || $mobileHash === '' || $iterations < 1) {
                        flash('danger', 'The Offline e-KYC XML does not contain the mobile hash information required for Aadhaar-linked mobile validation.');
                    } elseif (!validateOfflineEkycMobile($enteredMobile, $shareCode, $mobileHash, $iterations)) {
                        flash('danger', 'The entered mobile number does not match the Aadhaar-linked mobile hash in the Offline e-KYC XML. OTP was not sent.');
                    } else {
                        $otpDestination = trim((string) ($identity['email'] ?? ''));
                        $otpDeliveryType = 'email';
                    }
                } else {
                    $extractedMobile = trim((string) ($parsedDetailsFields['mobile_number'] ?? ''));
                    $otpDestination = $extractedMobile !== '' ? $extractedMobile : trim((string) ($identity['email'] ?? ''));
                    $otpDeliveryType = $extractedMobile !== '' ? 'mobile' : 'email';
                }

                if ($otpDestination === '') {
                    flash('danger', $offlineEkycFlow
                        ? 'The Aadhaar-linked mobile number was verified, but no registered email address is available for OTP delivery.'
                        : 'No mobile number or email address is available for OTP delivery.');
                } else {
                    $otpResult = beginEkycOtpChallenge(
                        (int) $identity['id'],
                        $otpDestination,
                        (string) ($verification['document_reference'] ?? 'IDENTITY-' . str_pad((string) $identity['id'], 6, '0', STR_PAD_LEFT)),
                        $otpDeliveryType
                    );

                    if ($otpResult['success']) {
                        $parsedDetails['state'] = 'otp_pending';
                        MockEKYCService::updateParsedDetails($pdo, $identity['id'], ['state' => 'otp_pending']);
                        $verificationState = 'otp_pending';
                        $targetLabel = $otpDeliveryType === 'mobile' ? 'mobile number' : 'email';
                        $message = $offlineEkycFlow
                            ? 'Aadhaar-linked mobile number verified successfully. A one-time code has been sent to your registered email address.'
                            : 'Consent recorded. A one-time code has been sent to your ' . $targetLabel . ' (' . e($otpResult['masked_destination']) . ').';
                        flash('success', $message);
                    } else {
                        flash('danger', 'The eKYC OTP could not be sent. Please try again.');
                    }
                }
            }
        } elseif ($action === 'verify_otp') {
            $otp = trim((string) ($_POST['otp'] ?? ''));
            if ($otp === '') {
                $otpChallenge = getEkycChallenge();
                flash('danger', ($otpChallenge['delivery_type'] ?? '') === 'email'
                    ? 'Enter the one-time code sent to your registered email address.'
                    : 'Enter the one-time code sent to your mobile number.');
            } elseif (verifyEkycOtpChallenge($otp)) {
                $comparisonSummary = buildEkycComparisonSummary($identity, $parsedDetails);
                $offlineEkycVerified = (($parsedDetails['provider'] ?? '') === 'offline_ekyc_file')
                    || (($parsedDetails['processing_status'] ?? '') === 'offline_ekyc_file_processed');
                $verificationPassed = $offlineEkycVerified;

                $parsedDetails['comparison_summary'] = $comparisonSummary;
                $parsedDetails['state'] = $verificationPassed ? 'verified' : 'failed';
                $parsedDetails['verified_at'] = date('c');
                MockEKYCService::updateParsedDetails($pdo, $identity['id'], [
                    'state' => $verificationPassed ? 'verified' : 'failed',
                    'comparison_summary' => $comparisonSummary,
                    'verified_at' => $parsedDetails['verified_at'],
                ]);

                $updateVerification = $pdo->prepare('UPDATE mock_ekyc_verifications SET verification_status = :status, verified_at = CASE WHEN :status = \'verified\' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = :id');
                $updateVerification->execute([
                    ':status' => $verificationPassed ? 'verified' : 'failed',
                    ':id' => (int) $verification['id'],
                ]);

                $updateIdentity = $pdo->prepare('UPDATE demo_health_identities SET verification_status = :status WHERE id = :id');
                $updateIdentity->execute([
                    ':status' => $verificationPassed ? 'verified' : 'failed',
                    ':id' => $identity['id'],
                ]);

                if ($verificationPassed) {
                    unset($_SESSION['offline_ekyc_share_codes'][$identity['id']]);
                    $_SESSION['flash'] = array_values(array_filter(
                        $_SESSION['flash'] ?? [],
                        static fn (array $message): bool => !(($message['type'] ?? '') === 'danger'
                            && ($message['message'] ?? '') === 'eKYC verification failed. The extracted data did not fully match the health identity details.')
                    ));

                    logAudit('EKYC_VERIFIED', 'health_identity', (int) $identity['id'], 'Aadhaar eKYC verification completed successfully.');
                    flash('success', 'eKYC Verified');
                    redirect('create_patient_account.php?id=' . $identity['id']);
                }

                logAudit('EKYC_FAILED', 'health_identity', (int) $identity['id'], 'Aadhaar eKYC verification failed due to mismatched identity details.');
                $verificationState = 'failed';
                flash('danger', 'eKYC verification failed. The extracted data did not fully match the health identity details.');
            } else {
                $challenge = getEkycChallenge();
                $attemptsLeft = (int) (($challenge['otp_attempts_left'] ?? 0));
                if ($attemptsLeft > 0) {
                    flash('danger', 'Incorrect one-time code. ' . $attemptsLeft . ' attempt(s) left.');
                } else {
                    flash('danger', 'Too many incorrect attempts. Please request a new one-time code.');
                }
            }
        } elseif ($action === 'resend_otp') {
            $challenge = getEkycChallenge();
            $otpDestination = trim((string) ($challenge['destination'] ?? ''));
            $otpDeliveryType = ($challenge['delivery_type'] ?? '') === 'mobile' ? 'mobile' : 'email';

            if ($otpDestination === '') {
                flash('danger', 'The OTP challenge has expired. Please start the eKYC mobile verification again.');
            } else {
                $otpResult = beginEkycOtpChallenge(
                    (int) $identity['id'],
                    $otpDestination,
                    (string) ($verification['document_reference'] ?? 'IDENTITY-' . str_pad((string) $identity['id'], 6, '0', STR_PAD_LEFT)),
                    $otpDeliveryType
                );
                if ($otpResult['success']) {
                    $parsedDetails['state'] = 'otp_pending';
                    MockEKYCService::updateParsedDetails($pdo, $identity['id'], ['state' => 'otp_pending']);
                    $verificationState = 'otp_pending';
                    $targetLabel = $otpDeliveryType === 'mobile' ? 'mobile number' : 'email';
                    flash('success', 'A new one-time code has been sent to your ' . $targetLabel . ' (' . e($otpResult['masked_destination']) . ').');
                } else {
                    flash('danger', 'The eKYC OTP could not be resent. Please try again.');
                }
            }
        }
    }
}

$verificationState = $parsedDetails['state'] ?? $verificationState;
$extractedFields = is_array($parsedDetails['extracted_fields'] ?? null) ? $parsedDetails['extracted_fields'] : [];
$comparisonSummary = buildEkycComparisonSummary($identity, $parsedDetails);
$mobileHashDebug = is_array($_SESSION['ekyc_mobile_hash_debug'] ?? null) ? $_SESSION['ekyc_mobile_hash_debug'] : null;

$pageTitle = 'Identity Verification';
$backUrl = 'create_health_identity.php';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Aadhaar eKYC</p>
        <h1 class="h3">Identity verification</h1>
        <p class="text-muted">Offline e-KYC package verified using UIDAI certificate-based XML signature verification. Uploaded XML/ZIP files are locally authenticated, and the extracted identity fields are compared against the existing health identity data for informational validation only. This comparison does not change the authoritative Offline e-KYC verification status.</p>

        <dl class="row small border-top pt-3">
            <dt class="col-5">Health identity</dt>
            <dd class="col-7"><?= e($identity['full_name']) ?></dd>
            <dt class="col-5">Document type</dt>
            <dd class="col-7"><?= e($verification['document_type'] ?? 'Aadhaar document') ?></dd>
            <dt class="col-5">Reference</dt>
            <dd class="col-7"><code><?= e($verification['document_reference'] ?? 'IDENTITY-' . str_pad((string) $identity['id'], 6, '0', STR_PAD_LEFT)) ?></code></dd>
        </dl>

        <?php if (($parsedDetails['provider'] ?? '') === 'offline_ekyc_file' && $mobileHashDebug !== null): ?>
            <div class="alert alert-secondary small">
                <strong>Temporary mobile-hash diagnostics</strong>
                <div>Mobile hash present: <?= e($mobileHashDebug['mobile_hash_present'] ?? 'NO') ?>; XML hash length: <?= (int) ($mobileHashDebug['xml_hash_length'] ?? 0) ?>; iterations: <?= (int) ($mobileHashDebug['hash_iterations'] ?? 0) ?>.</div>
                <div>Entered mobile: <?= e($mobileHashDebug['mobile_masked'] ?? '') ?>; digits: <?= (int) ($mobileHashDebug['mobile_digit_count'] ?? 0) ?>; Share Code length: <?= (int) ($mobileHashDebug['share_code_length'] ?? 0) ?>.</div>
                <div>XML hash prefix: <?= e($mobileHashDebug['xml_hash_prefix'] ?? '') ?>; calculated prefix: <?= e($mobileHashDebug['calculated_hash_prefix'] ?? '') ?>; match: <?= e($mobileHashDebug['match'] ?? 'NO') ?>.</div>
            </div>
        <?php endif; ?>

        <?php if ($verificationState === 'otp_pending'): ?>
            <?php if (isset($_SESSION['otp_debug_code']) && env('EMAIL_PROVIDER', 'log') === 'log'): ?>
                <div class="alert alert-info">Consent recorded. Debug OTP for this sandbox flow: <strong><?= e($_SESSION['otp_debug_code']) ?></strong></div>
            <?php endif; ?>
            <?php $otpChallenge = getEkycChallenge(); ?>
            <?php $otpIsEmail = ($otpChallenge['delivery_type'] ?? '') === 'email'; ?>
            <div class="alert alert-info">
                <?php if (($parsedDetails['provider'] ?? '') === 'offline_ekyc_file'): ?>
                    <div><strong>Aadhaar-linked mobile hash: VERIFIED</strong></div>
                    <div>OTP delivery: <strong>Registered email</strong></div>
                <?php endif; ?>
                Enter the one-time code sent to <strong><?= e($otpIsEmail ? maskEmail((string) ($otpChallenge['destination'] ?? '')) : maskMobileNumber((string) ($otpChallenge['destination'] ?? 'mobile number'))) ?></strong>.
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                <input type="hidden" name="action" value="verify_otp">
                <label class="form-label" for="otp">One-time code</label>
                <input class="form-control mb-3" id="otp" name="otp" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required>
                <button class="btn btn-primary">Verify OTP</button>
                <button type="submit" class="btn btn-outline-secondary ms-2" name="action" value="resend_otp">Resend code</button>
            </form>
        <?php else: ?>
            <?php if (!empty($extractedFields)): ?>
                <div class="card border-0 bg-light mt-3">
                    <div class="card-body">
                        <h2 class="h5">Extracted Offline e-KYC information</h2>
                        <p class="small text-muted"><?= e($parsedDetails['ocr_note'] ?? 'This demo extracted available document metadata for review.') ?></p>
                        <dl class="row small mb-0">
                            <?php if (!empty($extractedFields['name'])): ?>
                                <dt class="col-4">Full name</dt>
                                <dd class="col-8"><?= e($extractedFields['name']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($extractedFields['date_of_birth'])): ?>
                                <dt class="col-4">Date of birth</dt>
                                <dd class="col-8"><?= e($extractedFields['date_of_birth']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($extractedFields['gender'])): ?>
                                <dt class="col-4">Gender</dt>
                                <dd class="col-8"><?= e($extractedFields['gender']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($extractedFields['address'])): ?>
                                <dt class="col-4">Address</dt>
                                <dd class="col-8"><?= e($extractedFields['address']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($extractedFields['mobile_number'])): ?>
                                <dt class="col-4">Mobile number</dt>
                                <dd class="col-8"><?= e($extractedFields['mobile_number']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($extractedFields['aadhaar_number'])): ?>
                                <dt class="col-4">Masked Aadhaar</dt>
                                <dd class="col-8"><?= e($extractedFields['aadhaar_number']) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($verificationState === 'verified'): ?>
                <div class="alert alert-success mt-3">
                    <strong>Offline e-KYC Verification (Authoritative):</strong> VERIFIED
                </div>
            <?php endif; ?>

            <?php if (is_array($comparisonSummary)): ?>
                <div class="alert <?= ($comparisonSummary['all_required_fields_matched'] ?? false) ? 'alert-info' : 'alert-warning' ?> mt-3">
                    <strong>Health Identity Comparison (Informational)</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach (($comparisonSummary['fields'] ?? []) as $field): ?>
                            <li><?= e($field['label']) ?>: <?= $field['matched'] ? '<span class="text-success">MATCH</span>' : '<span class="text-danger">DOES NOT MATCH</span>' ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                <input type="hidden" name="action" value="consent">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" required>
                    <label class="form-check-label" for="consent">
                        I consent to Aadhaar-based identity verification/eKYC for creating my health identity.
                    </label>
                </div>
                <?php if (($parsedDetails['provider'] ?? '') === 'offline_ekyc_file'): ?>
                    <label class="form-label" for="aadhaar_mobile">Aadhaar-linked mobile number</label>
                    <input class="form-control mb-3" id="aadhaar_mobile" name="aadhaar_mobile" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required>
                    <p class="small text-muted">Enter the mobile number registered with Aadhaar. It will be validated against the mobile hash in the verified Offline e-KYC XML.</p>
                <?php endif; ?>
                <button class="btn btn-primary">Continue to eKYC OTP</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
