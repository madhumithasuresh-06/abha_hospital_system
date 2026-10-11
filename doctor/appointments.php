<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('doctor');

$doctorQuery = $pdo->prepare('SELECT d.id, d.doctor_name, u.hospital_id FROM doctors d JOIN users u ON u.id = d.user_id WHERE u.id = :user_id LIMIT 1');
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]);
$doctor = $doctorQuery->fetch(PDO::FETCH_ASSOC);
$appointments = [];

if ($doctor) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        $decision = $_POST['decision'] ?? '';
        if ($appointmentId && in_array($decision, ['approved', 'rejected', 'reschedule_requested'], true)) {
            $check = $pdo->prepare("SELECT a.id, a.patient_id, p.user_id AS patient_user_id FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.id = :id AND a.doctor_id = :doctor_id AND a.status = 'scheduled' LIMIT 1");
            $check->execute([':id' => $appointmentId, ':doctor_id' => $doctor['id']]);
            $appointment = $check->fetch(PDO::FETCH_ASSOC);
            if ($appointment) {
                $update = $pdo->prepare('UPDATE appointments SET status = :status, doctor_response = :response WHERE id = :id AND status = \'scheduled\'');
                $update->execute([':status' => $decision, ':response' => ucfirst(str_replace('_', ' ', $decision)) . ' by doctor.', ':id' => $appointmentId]);
                if (!empty($appointment['patient_user_id'])) {
                    createNotification($pdo, (int) $appointment['patient_user_id'], 'Appointment ' . str_replace('_', ' ', $decision), 'Your appointment request was ' . str_replace('_', ' ', $decision) . '.');
                }
                logAudit('APPOINTMENT_' . strtoupper($decision), 'appointment', $appointmentId, 'Appointment decision recorded by doctor.');
                flash('success', 'Appointment decision saved.');
            } else {
                flash('warning', 'Appointment is no longer awaiting your decision.');
            }
        } else {
            flash('danger', 'Invalid appointment decision.');
        }
        redirect('appointments.php');
    }

    $query = $pdo->prepare("
        SELECT 
            a.id, 
            a.appointment_date, 
            a.reason, 
            a.status, 
            a.doctor_response, 
            p.id AS patient_id, 
            i.full_name, 
            i.demo_health_id,
            c.id AS consultation_id,
            pr.id AS prescription_id,
            doc.signing_status
        FROM appointments a 
        JOIN patients p ON p.id = a.patient_id 
        JOIN demo_health_identities i ON i.id = p.health_identity_id 
        LEFT JOIN consultations c ON c.appointment_id = a.id
        LEFT JOIN prescriptions pr ON pr.consultation_id = c.id
        LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
        WHERE a.doctor_id = :doctor_id 
        ORDER BY a.appointment_date DESC
    ");
    $query->execute([':doctor_id' => $doctor['id']]);
    $appointments = $query->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Doctor Appointments';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="eyebrow">Clinical workspace</p>
        <h1 class="h2">Appointment Requests</h1>
    </div>
    <span class="badge text-bg-success"><?= e($doctor['doctor_name'] ?? 'Doctor profile pending') ?></span>
</div>

<?php if (!$doctor): ?>
    <div class="alert alert-warning">This user is not linked to a doctor profile yet.</div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $appointment): ?>
                            <tr>
                                <td>
                                    <?= e($appointment['full_name']) ?><br>
                                    <small class="text-muted"><?= e($appointment['demo_health_id']) ?></small>
                                </td>
                                <td><?= e($appointment['appointment_date']) ?></td>
                                <td><?= e($appointment['reason']) ?></td>
                                <td>
                                    <?= e($appointment['status']) ?>
                                    <?php if ($appointment['doctor_response']): ?>
                                        <br><small class="text-muted"><?= e($appointment['doctor_response']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if ($appointment['status'] === 'scheduled'): ?>
                                            <form method="post" class="d-flex gap-1 flex-wrap">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                                <button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button>
                                                <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">Reject</button>
                                                <button class="btn btn-sm btn-outline-secondary" name="decision" value="reschedule_requested">Suggest time</button>
                                            </form>
                                        <?php elseif ($appointment['status'] === 'approved'): ?>
                                            <a class="btn btn-sm btn-primary" href="consultation.php?appointment_id=<?= (int) $appointment['id'] ?>">Start consultation</a>
                                        <?php elseif ($appointment['status'] === 'completed'): ?>
                                            <?php if (!empty($appointment['prescription_id'])): ?>
                                                <a class="btn btn-sm btn-outline-primary text-nowrap" href="view_prescription.php?id=<?= (int) $appointment['prescription_id'] ?>">
                                                    <i class="bi bi-file-earmark-medical"></i> Prescription (<?= e($appointment['signing_status'] ?? 'pending') ?>)
                                                </a>
                                            <?php elseif (!empty($appointment['consultation_id'])): ?>
                                                <a class="btn btn-sm btn-outline-secondary text-nowrap" href="prescriptions.php?consultation_id=<?= (int) $appointment['consultation_id'] ?>">
                                                    <i class="bi bi-plus-circle"></i> Add Prescription
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="patient_data.php?patient_id=<?= (int) $appointment['patient_id'] ?>">Protected data</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$appointments): ?>
                            <tr><td colspan="5" class="text-muted">No appointments assigned.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
