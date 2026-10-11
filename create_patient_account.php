<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

$identityId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'identity_id', FILTER_VALIDATE_INT);
if (!$identityId || (int) ($_SESSION['phase2_identity_id'] ?? 0) !== (int) $identityId) {
    http_response_code(403);
    exit('Patient account setup session not found.');
}
$identityQuery = $pdo->prepare("SELECT * FROM demo_health_identities WHERE id = :id AND verification_status = 'verified' LIMIT 1");
$identityQuery->execute([':id' => $identityId]);
$identity = $identityQuery->fetch(PDO::FETCH_ASSOC);
if (!$identity) { http_response_code(403); exit('Identity verification required.'); }

$existingPatient = $pdo->prepare('SELECT p.id FROM patients p WHERE p.health_identity_id = :identity_id LIMIT 1');
$existingPatient->execute([':identity_id' => $identity['id']]);
if ($existingPatient->fetchColumn()) { flash('info', 'A patient account already exists for this health identity.'); redirect('login.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        flash('danger', 'Enter a username, valid email, and password of at least 8 characters.');
    } else {
        try {
            $roleId = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'patient' LIMIT 1");
            $roleId->execute();
            $pdo->beginTransaction();
            $userInsert = $pdo->prepare('INSERT INTO users (role_id, username, email, full_name, password_hash, status) VALUES (:role_id, :username, :email, :full_name, :password_hash, \'active\')');
            $userInsert->execute([':role_id' => $roleId->fetchColumn(), ':username' => $username, ':email' => $email, ':full_name' => $identity['full_name'], ':password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $patientUniqueId = generatePatientId($pdo);
            $patientInsert = $pdo->prepare(
                'INSERT INTO patients
                    (user_id, health_identity_id, patient_id, hospital_patient_id, full_name, date_of_birth, gender, mobile, email,
                     address, city, state, pincode, government_id_type, government_id_number, emergency_contact_name,
                     emergency_contact_relationship, emergency_contact_number, emergency_contact, blood_group, allergies,
                     existing_conditions, registration_date)
                 VALUES
                    (:user_id, :identity_id, :patient_id, :hospital_patient_id, :full_name, :date_of_birth, :gender, :mobile, :email,
                     :address, :city, :state, :pincode, :government_id_type, :government_id_number, :emergency_contact_name,
                     :emergency_contact_relationship, :emergency_contact_number, :emergency_contact, :blood_group, :allergies,
                     :existing_conditions, NOW())'
            );
            $patientInsert->execute([
                ':user_id' => $userId,
                ':identity_id' => $identity['id'],
                ':patient_id' => $patientUniqueId,
                ':hospital_patient_id' => $patientUniqueId,
                ':full_name' => $identity['full_name'],
                ':date_of_birth' => $identity['date_of_birth'],
                ':gender' => $identity['gender'],
                ':mobile' => $identity['mobile'],
                ':email' => $identity['email'] ?: $email,
                ':address' => $identity['address'],
                ':city' => $identity['city'],
                ':state' => $identity['state'],
                ':pincode' => $identity['pincode'],
                ':government_id_type' => $identity['government_id_type'],
                ':government_id_number' => $identity['government_id_number'],
                ':emergency_contact_name' => $identity['emergency_contact_name'],
                ':emergency_contact_relationship' => $identity['emergency_contact_relationship'],
                ':emergency_contact_number' => $identity['emergency_contact_number'],
                ':emergency_contact' => $identity['emergency_contact_number'],
                ':blood_group' => $identity['blood_group'] ?: 'Unknown',
                ':allergies' => $identity['allergies'],
                ':existing_conditions' => $identity['existing_conditions'],
            ]);
            $patientId = (int) $pdo->lastInsertId();
            $pdo->commit();
            logAudit('PATIENT_CREATED', 'patient', $patientId, 'Patient account created after identity verification.', $userId, 'patient');
            flash('success', 'Patient account created. Patient ID: ' . $patientUniqueId . '. You can now sign in.');
            unset($_SESSION['phase2_identity_id']);
            redirect('login.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('danger', 'Could not create the account. Username or email may already exist.');
        }
    }
}

$pageTitle = 'Create Patient Account';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-shell"><div class="setup-card">
    <p class="eyebrow">Account registration</p>
    <h1 class="h3">Create Patient Account</h1>
    <p class="text-muted">Verified identity: <strong><?= e($identity['full_name']) ?></strong></p>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>"><label class="form-label" for="username">Username</label><input class="form-control mb-3" id="username" name="username" required><label class="form-label" for="email">Email</label><input class="form-control mb-3" id="email" type="email" name="email" value="<?= e($identity['email']) ?>" required><label class="form-label" for="password">Password</label><input class="form-control mb-3" id="password" type="password" name="password" minlength="8" required><button class="btn btn-primary">Create patient account</button></form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
