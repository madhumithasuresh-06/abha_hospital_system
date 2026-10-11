<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('receptionist');

$hospitalId = currentHospitalId();
$hospital = $hospitalId ? $pdo->prepare("SELECT id, hospital_name FROM hospitals WHERE id = :id AND status = 'active' LIMIT 1") : null;
if ($hospital) { $hospital->execute([':id' => $hospitalId]); $hospital = $hospital->fetch(PDO::FETCH_ASSOC); }
$departments = $hospital ? $pdo->prepare('SELECT id, department_name FROM departments WHERE hospital_id = :hospital_id ORDER BY department_name') : null;
if ($departments) { $departments->execute([':hospital_id' => $hospital['id']]); $departments = $departments->fetchAll(PDO::FETCH_ASSOC); } else { $departments = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientLookup = trim($_POST['patient_lookup'] ?? '');
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired.');
    } elseif (!$hospital || !$departmentId || $patientLookup === '') {
        flash('danger', 'Configure the hospital and enter a Patient ID or Health Identity and department.');
    } else {
        $find = $pdo->prepare(
            "SELECT p.id, COALESCE(p.patient_id, p.hospital_patient_id) AS patient_id, COALESCE(p.full_name, d.full_name) AS full_name, d.demo_health_id
             FROM patients p
             JOIN demo_health_identities d ON d.id = p.health_identity_id
             WHERE d.verification_status = 'verified'
               AND (p.patient_id = :lookup OR p.hospital_patient_id = :lookup OR d.demo_health_id = :lookup)
             LIMIT 1"
        );
        $find->execute([':lookup' => $patientLookup]);
        $patient = $find->fetch(PDO::FETCH_ASSOC);
        $departmentCheck = $pdo->prepare('SELECT id FROM departments WHERE id = :department_id AND hospital_id = :hospital_id LIMIT 1');
        $departmentCheck->execute([':department_id' => $departmentId, ':hospital_id' => $hospital['id']]);
        if (!$patient || !$departmentCheck->fetchColumn()) {
            flash('danger', 'Enter a verified Patient ID or Health Identity and valid department.');
        } else {
            $duplicate = $pdo->prepare("SELECT id FROM hospital_patient_registrations WHERE patient_id = :patient_id AND hospital_id = :hospital_id AND registration_status IN ('pending','approved') LIMIT 1");
            $duplicate->execute([':patient_id' => $patient['id'], ':hospital_id' => $hospital['id']]);
            if ($duplicate->fetchColumn()) {
                flash('warning', 'This patient is already registered at the configured hospital.');
            } else {
                $insert = $pdo->prepare('INSERT INTO hospital_patient_registrations (patient_id, hospital_id, department_id, registration_status, registered_by) VALUES (:patient_id, :hospital_id, :department_id, \'approved\', :registered_by)');
                $insert->execute([':patient_id' => $patient['id'], ':hospital_id' => $hospital['id'], ':department_id' => $departmentId, ':registered_by' => $_SESSION['user_id']]);
                logAudit('HOSPITAL_PATIENT_REGISTERED', 'registration', (int) $pdo->lastInsertId(), 'Patient registered at configured hospital by receptionist.');
                flash('success', 'Patient registered successfully. Patient ID: ' . $patient['patient_id']);
                redirect('register_patient.php');
            }
        }
    }
}
$pageTitle = 'Register Patient';
require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell"><div class="setup-card"><p class="eyebrow">Front desk</p><h1 class="h3">Register Patient</h1><?php if ($hospital): ?><p class="text-muted">Configured hospital: <strong><?= e($hospital['hospital_name']) ?></strong></p><?php else: ?><div class="alert alert-warning">The administrator must configure the hospital first.</div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label" for="patient_lookup">Patient ID or Health Identity</label><input class="form-control mb-3" id="patient_lookup" name="patient_lookup" placeholder="HMS-2026-000001 or DHI-2026-000001" required><label class="form-label" for="department_id">Department</label><select class="form-select mb-3" id="department_id" name="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['department_name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary" <?= $hospital ? '' : 'disabled' ?>>Register at configured hospital</button></form></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
