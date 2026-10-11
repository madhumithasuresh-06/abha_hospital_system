<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/aadhaar.php';
require_once __DIR__ . '/includes/ekyc.php';

$genderOptions = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$governmentIdTypes = ['Aadhaar', 'PAN Card', 'Passport', 'Driving Licence', 'Other'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $dateOfBirth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $governmentIdType = $_POST['government_id_type'] ?? '';
    $governmentIdNumber = trim($_POST['government_id_number'] ?? '');
    $aadhaarDocument = $_FILES['aadhaar_document'] ?? null;
    $aadhaarUploadResult = null;

    if ($governmentIdType === 'Aadhaar' && !empty($aadhaarDocument['name'])) {
        $documentFileName = strtolower((string) ($aadhaarDocument['name'] ?? ''));
        $isOfflineEkycFile = preg_match('/\.(xml|zip)$/i', $documentFileName) === 1
            || in_array(strtolower((string) ($aadhaarDocument['type'] ?? '')), ['application/xml', 'text/xml', 'application/zip', 'application/x-zip-compressed'], true);

        $aadhaarUploadResult = $isOfflineEkycFile
            ? processOfflineEkycUpload($aadhaarDocument, $_POST)
            : processAadhaarUpload($aadhaarDocument, $_POST);

        if ($aadhaarUploadResult['success']) {
            $governmentIdNumber = trim((string) ($aadhaarUploadResult['document']['extracted_fields']['aadhaar_number'] ?? $governmentIdNumber));
        }
    }
    $emergencyContactName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyContactRelationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergencyContactNumber = trim($_POST['emergency_contact_number'] ?? '');
    $bloodGroup = $_POST['blood_group'] ?? '';
    $allergies = trim($_POST['allergies'] ?? '');
    $existingConditions = trim($_POST['existing_conditions'] ?? '');

    $validDate = $dateOfBirth !== '' && strtotime($dateOfBirth) !== false && $dateOfBirth <= date('Y-m-d');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif (
        $fullName === ''
        || !$validDate
        || !array_key_exists($gender, $genderOptions)
        || !preg_match('/^[0-9]{10}$/', $mobile)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $address === ''
        || $city === ''
        || $state === ''
        || !preg_match('/^[0-9]{6}$/', $pincode)
        || !in_array($governmentIdType, $governmentIdTypes, true)
        || ($governmentIdType !== 'Aadhaar' && $governmentIdNumber === '')
        || ($governmentIdType === 'Aadhaar' && $governmentIdNumber === '' && !($aadhaarUploadResult['success'] ?? false))
        || $emergencyContactName === ''
        || $emergencyContactRelationship === ''
        || !preg_match('/^[0-9]{10}$/', $emergencyContactNumber)
        || !in_array($bloodGroup, $bloodGroups, true)
    ) {
        flash('danger', 'Complete all required patient details with valid mobile, email, and pincode values. For Aadhaar, either enter a number or upload an Aadhaar document.');
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO demo_health_identities
                (demo_health_id, full_name, date_of_birth, gender, mobile, email, address, city, state, pincode,
                 government_id_type, government_id_number, emergency_contact_name, emergency_contact_relationship,
                 emergency_contact_number, blood_group, allergies, existing_conditions, verification_status)
             VALUES
                (:health_id, :full_name, :date_of_birth, :gender, :mobile, :email, :address, :city, :state, :pincode,
                 :government_id_type, :government_id_number, :emergency_contact_name, :emergency_contact_relationship,
                 :emergency_contact_number, :blood_group, :allergies, :existing_conditions, \'pending\')'
        );
        $healthId = generateDemoHealthId($pdo);
        $insert->execute([
            ':health_id' => $healthId,
            ':full_name' => $fullName,
            ':date_of_birth' => $dateOfBirth,
            ':gender' => $gender,
            ':mobile' => $mobile,
            ':email' => $email,
            ':address' => $address,
            ':city' => $city,
            ':state' => $state,
            ':pincode' => $pincode,
            ':government_id_type' => $governmentIdType,
            ':government_id_number' => $governmentIdNumber,
            ':emergency_contact_name' => $emergencyContactName,
            ':emergency_contact_relationship' => $emergencyContactRelationship,
            ':emergency_contact_number' => $emergencyContactNumber,
            ':blood_group' => $bloodGroup,
            ':allergies' => $allergies !== '' ? $allergies : null,
            ':existing_conditions' => $existingConditions !== '' ? $existingConditions : null,
        ]);
        $identityId = (int) $pdo->lastInsertId();
        $_SESSION['phase2_identity_id'] = $identityId;
        unset($_SESSION['ekyc_mobile_hash_debug']);
        if (($aadhaarUploadResult['document']['provider'] ?? '') === 'offline_ekyc_file') {
            $_SESSION['offline_ekyc_share_codes'][$identityId] = trim((string) ($_POST['share_code'] ?? ''));
        }

        $documentData = ['document_type' => 'Aadhaar document'];
        if ($aadhaarUploadResult['success'] ?? false) {
            $documentData = array_replace($documentData, $aadhaarUploadResult['document']);
            if (isset($aadhaarUploadResult['document']['extracted_fields'])) {
                $documentData['extracted_fields'] = $aadhaarUploadResult['document']['extracted_fields'];
            }
        } else {
            $documentData['provider'] = 'demo_sandbox';
        }

        MockEKYCService::ensureVerificationRecord($pdo, $identityId, $documentData);
        logAudit('DEMO_HEALTH_ID_CREATED', 'health_identity', $identityId, 'Health identity created for identity verification.');
        redirect('ekyc.php?id=' . $identityId);
    }
}

$pageTitle = 'Create Health Identity';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell">
    <div class="setup-card patient-form-card">
        <p class="eyebrow">Patient identity</p>
        <h1 class="h3">Create Health Identity</h1>
        <p class="text-muted">Enter patient details for this academic healthcare workflow.</p>
        <form method="post" enctype="multipart/form-data" class="patient-registration-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <h2 class="form-section-title">Personal Information</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" placeholder="Patient full name" required></div>
                <div class="col-md-6"><label class="form-label" for="date_of_birth">Date of Birth</label><input class="form-control" id="date_of_birth" type="date" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="gender">Gender</label><select class="form-select" id="gender" name="gender" required><option value="">Select gender</option><?php foreach ($genderOptions as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="mobile">Mobile Number</label><input class="form-control" id="mobile" name="mobile" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10 digit mobile" required></div>
                <div class="col-md-4"><label class="form-label" for="email">Email Address</label><input class="form-control" id="email" type="email" name="email" placeholder="patient@example.com" required></div>
            </div>

            <h2 class="form-section-title">Address Information</h2>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="address">Full Address</label><textarea class="form-control" id="address" name="address" rows="3" placeholder="House number, street, area" required></textarea></div>
                <div class="col-md-4"><label class="form-label" for="city">City</label><input class="form-control" id="city" name="city" required></div>
                <div class="col-md-4"><label class="form-label" for="state">State</label><input class="form-control" id="state" name="state" required></div>
                <div class="col-md-4"><label class="form-label" for="pincode">Pincode</label><input class="form-control" id="pincode" name="pincode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required></div>
            </div>

            <h2 class="form-section-title">Identity Information</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="government_id_type">Government ID Type</label><select class="form-select" id="government_id_type" name="government_id_type" required><option value="">Select ID type</option><?php foreach ($governmentIdTypes as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="government_id_number">Government ID Number</label><input class="form-control" id="government_id_number" name="government_id_number" placeholder="Demo ID number (or leave blank for Aadhaar upload)"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-12"><label class="form-label" for="aadhaar_document">Aadhaar document upload <span class="text-muted">(Offline e-KYC XML/ZIP, PDF, JPG, or PNG; optional when an Aadhaar number is already provided)</span></label><input class="form-control" id="aadhaar_document" name="aadhaar_document" type="file" accept=".xml,.zip,application/xml,text/xml,application/zip,application/x-zip-compressed,application/pdf,image/jpeg,image/png"></div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-12"><label class="form-label" for="share_code">Offline e-KYC Share Code <span class="text-muted">(required for XML/ZIP Offline e-KYC uploads)</span></label><input class="form-control" id="share_code" name="share_code" placeholder="Enter Offline e-KYC Share Code"></div>
            </div>

            <h2 class="form-section-title">Emergency Contact</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="emergency_contact_name">Emergency Contact Name</label><input class="form-control" id="emergency_contact_name" name="emergency_contact_name" required></div>
                <div class="col-md-4"><label class="form-label" for="emergency_contact_relationship">Relationship</label><input class="form-control" id="emergency_contact_relationship" name="emergency_contact_relationship" placeholder="Parent, spouse, friend" required></div>
                <div class="col-md-4"><label class="form-label" for="emergency_contact_number">Emergency Contact Number</label><input class="form-control" id="emergency_contact_number" name="emergency_contact_number" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required></div>
            </div>

            <h2 class="form-section-title">Medical Information</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="blood_group">Blood Group</label><select class="form-select" id="blood_group" name="blood_group" required><option value="">Select blood group</option><?php foreach ($bloodGroups as $group): ?><option value="<?= e($group) ?>"><?= e($group) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label" for="allergies">Allergies <span class="text-muted">(optional)</span></label><textarea class="form-control" id="allergies" name="allergies" rows="2" placeholder="Known allergies"></textarea></div>
                <div class="col-md-4"><label class="form-label" for="existing_conditions">Existing Medical Conditions <span class="text-muted">(optional)</span></label><textarea class="form-control" id="existing_conditions" name="existing_conditions" rows="2" placeholder="Existing conditions"></textarea></div>
            </div>

            <button class="btn btn-primary mt-4">Continue to identity verification</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
