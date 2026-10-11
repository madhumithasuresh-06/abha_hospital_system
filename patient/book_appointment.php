<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('patient');

$patientQuery = $pdo->prepare('SELECT id FROM patients WHERE user_id = :user_id LIMIT 1');
$patientQuery->execute([':user_id' => $_SESSION['user_id']]);
$patientId = (int) $patientQuery->fetchColumn();
$hospitals = $pdo->query("SELECT id, hospital_name FROM hospitals WHERE status = 'active' ORDER BY hospital_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospitalId = filter_input(INPUT_POST, 'hospital_id', FILTER_VALIDATE_INT);
    $date = $_POST['appointment_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $symptoms = trim($_POST['symptoms'] ?? '');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || !$patientId || !$hospitalId || $date === '' || strtotime($date) <= time() || $reason === '') {
        flash('danger', 'Select a hospital, future preferred time, and reason for the visit.');
    } else {
        $hospitalQuery = $pdo->prepare("SELECT id, hospital_name FROM hospitals WHERE id = :id AND status = 'active' LIMIT 1");
        $hospitalQuery->execute([':id' => $hospitalId]);
        $hospital = $hospitalQuery->fetch(PDO::FETCH_ASSOC);
        if (!$hospital) {
            flash('danger', 'Selected hospital is not available.');
        } else {
            $registration = $pdo->prepare("SELECT id FROM hospital_patient_registrations WHERE patient_id = :patient_id AND hospital_id = :hospital_id AND registration_status = 'approved' LIMIT 1");
            $registration->execute([':patient_id' => $patientId, ':hospital_id' => $hospitalId]);
            $status = $registration->fetchColumn() ? 'requested' : 'requested';
            $insert = $pdo->prepare('INSERT INTO appointments (patient_id, hospital_id, doctor_id, requested_by, appointment_date, reason, doctor_response, status) VALUES (:patient_id, :hospital_id, NULL, :requested_by, :appointment_date, :reason, :doctor_response, :status)');
            $insert->execute([':patient_id' => $patientId, ':hospital_id' => $hospitalId, ':requested_by' => $_SESSION['user_id'], ':appointment_date' => date('Y-m-d H:i:s', strtotime($date)), ':reason' => $reason . ($symptoms !== '' ? "\nSymptoms: " . $symptoms : ''), ':doctor_response' => null, ':status' => $status]);
            $appointmentId = (int) $pdo->lastInsertId();
            $officers = $pdo->prepare("SELECT id FROM users WHERE hospital_id = :hospital_id AND role_id = (SELECT id FROM roles WHERE role_name = 'receptionist' LIMIT 1) AND status = 'active'");
            $officers->execute([':hospital_id' => $hospitalId]);
            foreach ($officers as $officer) { createNotification($pdo, (int) $officer['id'], 'Appointment request received', 'A patient submitted an appointment request for ' . $hospital['hospital_name'] . '.'); }
            logAudit('APPOINTMENT_REQUESTED', 'appointment', $appointmentId, 'Patient submitted a hospital appointment request.');
            flash('success', 'Appointment request submitted to ' . $hospital['hospital_name'] . '.');
            redirect('appointments.php');
        }
    }
}
$pageTitle = 'Book Appointment'; require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell"><div class="setup-card"><p class="eyebrow">Patient space</p><h1 class="h3">Request an Appointment</h1><p class="text-muted">Choose a hospital and preferred time. The Front Desk Officer will route your request to the appropriate department and doctor.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label">Hospital</label><select class="form-select mb-3" name="hospital_id" required><option value="">Select hospital</option><?php foreach ($hospitals as $hospital): ?><option value="<?= (int) $hospital['id'] ?>"><?= e($hospital['hospital_name']) ?></option><?php endforeach; ?></select><label class="form-label">Preferred date and time</label><input class="form-control mb-3" type="datetime-local" name="appointment_date" required><label class="form-label">Reason for visit</label><input class="form-control mb-3" name="reason" required><label class="form-label">Symptoms <span class="text-muted">(optional)</span></label><textarea class="form-control mb-3" name="symptoms" rows="3"></textarea><button class="btn btn-primary">Submit appointment request</button></form></div></div><?php require __DIR__ . '/../includes/footer.php'; ?>
