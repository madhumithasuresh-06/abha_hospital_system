<?php
session_start(); require_once __DIR__ . '/../includes/auth.php'; require_once __DIR__ . '/../includes/audit.php'; require_once __DIR__ . '/../includes/notifications.php'; requireRole('doctor');
$doctorQuery = $pdo->prepare('SELECT id FROM doctors WHERE user_id = :user_id LIMIT 1'); $doctorQuery->execute([':user_id' => $_SESSION['user_id']]); $doctorId = $doctorQuery->fetchColumn(); if (!$doctorId) { http_response_code(403); exit('Doctor profile not found.'); }
$consultationId = filter_input(INPUT_GET, 'consultation_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'consultation_id', FILTER_VALIDATE_INT); $consultationQuery = $pdo->prepare('SELECT c.id, c.patient_id, p.user_id AS patient_user_id, i.full_name FROM consultations c JOIN doctors d ON d.id = c.doctor_id JOIN patients p ON p.id = c.patient_id JOIN demo_health_identities i ON i.id = p.health_identity_id WHERE c.id = :id AND c.doctor_id = :doctor_id LIMIT 1'); $consultationQuery->execute([':id' => $consultationId, ':doctor_id' => $doctorId]); $consultation = $consultationQuery->fetch(PDO::FETCH_ASSOC);
if (!$consultation) { http_response_code(404); exit('Consultation not found or not created by this doctor.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { flash('danger', 'The form expired.'); } else { $medicineNames = $_POST['medicine_name'] ?? []; $items = []; foreach ($medicineNames as $index => $medicine) { $medicine = trim((string) $medicine); if ($medicine !== '') { $items[] = [$medicine, trim((string) ($_POST['dosage'][$index] ?? '')), trim((string) ($_POST['frequency'][$index] ?? '')), trim((string) ($_POST['duration'][$index] ?? '')), trim((string) ($_POST['instructions'][$index] ?? ''))]; } } if (!$items) { flash('danger', 'Add at least one medicine.'); } else { try { $pdo->beginTransaction(); $insert = $pdo->prepare('INSERT INTO prescriptions (consultation_id, patient_id, doctor_id) VALUES (:consultation_id, :patient_id, :doctor_id)'); $insert->execute([':consultation_id' => $consultation['id'], ':patient_id' => $consultation['patient_id'], ':doctor_id' => $doctorId]); $prescriptionId = (int) $pdo->lastInsertId(); $itemInsert = $pdo->prepare('INSERT INTO prescription_items (prescription_id, medicine_name, dosage, frequency, duration, instructions) VALUES (:prescription_id, :medicine, :dosage, :frequency, :duration, :instructions)'); foreach ($items as $item) { $itemInsert->execute([':prescription_id' => $prescriptionId, ':medicine' => $item[0], ':dosage' => $item[1] !== '' ? $item[1] : null, ':frequency' => $item[2] !== '' ? $item[2] : null, ':duration' => $item[3] !== '' ? $item[3] : null, ':instructions' => $item[4] !== '' ? $item[4] : null]); } $pdo->commit(); if (!empty($consultation['patient_user_id'])) { createNotification($pdo, (int) $consultation['patient_user_id'], 'Prescription created', 'A new prescription is available in your patient portal.'); } logAudit('PRESCRIPTION_CREATED', 'prescription', $prescriptionId, 'Prescription created by doctor.'); flash('success', 'Prescription created. You can now generate the PDF and digitally sign it.'); redirect('view_prescription.php?id=' . $prescriptionId); } catch (Throwable $exception) { if ($pdo->inTransaction()) { $pdo->rollBack(); } flash('danger', 'Could not create prescription.'); } } } }
$pageTitle = 'Create Prescription'; require __DIR__ . '/../includes/header.php';
?><div class="setup-shell"><div class="setup-card"><p class="eyebrow">Clinical documentation</p><h1 class="h3">Create Prescription</h1><p class="text-muted">Patient: <strong><?= e($consultation['full_name']) ?></strong></p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="consultation_id" value="<?= (int) $consultation['id'] ?>"><div id="medicine-list"><div class="medicine-entry border-top pt-3 mt-3"><label class="form-label">Medicine 1</label><input class="form-control mb-2" name="medicine_name[]"><input class="form-control mb-2" name="dosage[]" placeholder="Dosage"><input class="form-control mb-2" name="frequency[]" placeholder="Frequency"><input class="form-control mb-2" name="duration[]" placeholder="Duration"><input class="form-control mb-2" name="instructions[]" placeholder="Instructions"><button type="button" class="btn btn-outline-danger btn-sm remove-medicine" data-index="0">Remove</button></div></div><button type="button" id="add-medicine" class="btn btn-outline-primary mt-3">+ Add Medicine</button><button class="btn btn-primary mt-3 ms-2">Create prescription</button></form></div></div><script>
let medicineIndex = 1;
const medicineList = document.getElementById('medicine-list');
const addMedicineButton = document.getElementById('add-medicine');
function addMedicineEntry() {
    const wrapper = document.createElement('div');
    wrapper.className = 'medicine-entry border-top pt-3 mt-3';
    wrapper.innerHTML = `
        <label class="form-label">Medicine ${medicineIndex + 1}</label>
        <input class="form-control mb-2" name="medicine_name[]">
        <input class="form-control mb-2" name="dosage[]" placeholder="Dosage">
        <input class="form-control mb-2" name="frequency[]" placeholder="Frequency">
        <input class="form-control mb-2" name="duration[]" placeholder="Duration">
        <input class="form-control mb-2" name="instructions[]" placeholder="Instructions">
        <button type="button" class="btn btn-outline-danger btn-sm remove-medicine" data-index="${medicineIndex}">Remove</button>
    `;
    medicineList.appendChild(wrapper);
    medicineIndex += 1;
}
addMedicineButton.addEventListener('click', addMedicineEntry);
document.addEventListener('click', function (event) {
    if (event.target && event.target.classList.contains('remove-medicine')) {
        const entry = event.target.closest('.medicine-entry');
        if (entry && medicineList.children.length > 1) {
            entry.remove();
        }
    }
});
</script><?php require __DIR__ . '/../includes/footer.php'; ?>