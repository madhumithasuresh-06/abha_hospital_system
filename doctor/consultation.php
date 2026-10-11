<?php
session_start(); 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/audit.php'; 
require_once __DIR__ . '/../includes/notifications.php'; 

requireRole('doctor');

// Get current doctor
$doctorQuery = $pdo->prepare('SELECT id FROM doctors WHERE user_id = :user_id LIMIT 1'); 
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]); 
$currentDoctorId = $doctorQuery->fetchColumn();

if (!$currentDoctorId) {
    http_response_code(403);
    exit('Doctor profile not found.');
}

// Get appointment
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
$appointmentQuery = $pdo->prepare("
    SELECT a.id, a.patient_id, a.doctor_id, a.status, p.user_id AS patient_user_id, u.hospital_id, i.full_name, i.demo_health_id 
    FROM appointments a 
    JOIN doctors d ON d.id = a.doctor_id 
    JOIN patients p ON p.id = a.patient_id 
    JOIN users u ON u.id = d.user_id 
    JOIN demo_health_identities i ON i.id = p.health_identity_id 
    WHERE a.id = :id AND d.user_id = :user_id LIMIT 1
"); 
$appointmentQuery->execute([':id' => $appointmentId, ':user_id' => $_SESSION['user_id']]); 
$appointment = $appointmentQuery->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    http_response_code(404);
    exit('Appointment not found or not assigned to this doctor.');
}

if ($appointment['status'] !== 'approved') {
    http_response_code(403);
    exit('Appointment approval is required before consultation.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $symptoms = trim($_POST['symptoms'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif ($diagnosis === '') {
        flash('danger', 'Diagnosis is required.');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if consultation already exists
            $check = $pdo->prepare('SELECT id FROM consultations WHERE appointment_id = :appointment_id LIMIT 1');
            $check->execute([':appointment_id' => $appointmentId]);
            
            if ($check->fetchColumn()) {
                throw new RuntimeException('A consultation already exists for this appointment.');
            }
            
            // Insert consultation
            $insert = $pdo->prepare('
                INSERT INTO consultations (appointment_id, patient_id, doctor_id, symptoms, diagnosis, notes) 
                VALUES (:appointment_id, :patient_id, :doctor_id, :symptoms, :diagnosis, :notes)
            ');
            $insert->execute([
                ':appointment_id' => $appointmentId,
                ':patient_id' => $appointment['patient_id'],
                ':doctor_id' => $currentDoctorId,
                ':symptoms' => $symptoms ?: null,
                ':diagnosis' => $diagnosis,
                ':notes' => $notes ?: null
            ]);
            
            $consultationId = (int) $pdo->lastInsertId();
            
            // Update appointment status to completed
            $update = $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE id = :id AND status = 'approved'");
            $update->execute([':id' => $appointmentId]);
            
            $pdo->commit();
            
            // Create medical record (outside transaction to avoid cascading failures)
            try {
                $recordInsert = $pdo->prepare('
                    INSERT INTO medical_records (patient_id, doctor_id, hospital_id, record_type, record_title, record_description) 
                    VALUES (:patient_id, :doctor_id, :hospital_id, :record_type, :record_title, :record_description)
                ');
                $recordInsert->execute([
                    ':patient_id' => $appointment['patient_id'],
                    ':doctor_id' => $currentDoctorId,
                    ':hospital_id' => $appointment['hospital_id'] ?: null,
                    ':record_type' => 'Consultation',
                    ':record_title' => 'Consultation - ' . ($diagnosis ? substr($diagnosis, 0, 100) : 'Clinical Visit'),
                    ':record_description' => "Symptoms: " . ($symptoms ?: 'None recorded') . "\n\nDiagnosis: " . $diagnosis . "\n\nNotes: " . ($notes ?: 'None recorded')
                ]);
            } catch (Exception $e) {
                // Log but don't fail the consultation save
                error_log('Failed to create medical record: ' . $e->getMessage());
            }
            
            // Send notification
            if (!empty($appointment['patient_user_id'])) {
                createNotification($pdo, (int) $appointment['patient_user_id'], 'Consultation completed', 'Your doctor has completed a consultation for your appointment.');
            }
            
            logAudit('CONSULTATION_CREATED', 'consultation', $consultationId, 'Consultation recorded and appointment completed.');
            flash('success', 'Consultation saved.');
            redirect('prescriptions.php?consultation_id=' . $consultationId);
            
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = $exception instanceof RuntimeException ? $exception->getMessage() : 'Failed to save consultation. Please try again.';
            flash('danger', $errorMsg);
        }
    }
}

$pageTitle = 'Consultation'; 
require __DIR__ . '/../includes/header.php';
?>

<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Clinical documentation</p>
        <h1 class="h3">Consultation</h1>
        <p class="text-muted mb-4">Patient: <strong><?= e($appointment['full_name']) ?></strong> (<?= e($appointment['demo_health_id']) ?>)</p>
        
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
            
            <label class="form-label">Symptoms</label>
            <textarea class="form-control mb-3" name="symptoms" rows="3"></textarea>
            
            <label class="form-label">Diagnosis</label>
            <textarea class="form-control mb-3" name="diagnosis" rows="3" required></textarea>
            
            <label class="form-label">Notes</label>
            <textarea class="form-control mb-3" name="notes" rows="3"></textarea>
            
            <button class="btn btn-primary">Save consultation</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
