<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

requireRole('admin');

$confirmation = $_POST['confirmation'] ?? '';
$pageTitle = 'Clear All Data';
require __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif ($confirmation !== 'YES I UNDERSTAND') {
        flash('danger', 'Please type the confirmation text exactly.');
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Disable foreign key checks temporarily
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Delete data - order doesn't matter with foreign key checks disabled
            $pdo->exec('DELETE FROM prescription_items');
            $pdo->exec('DELETE FROM prescriptions');
            $pdo->exec('DELETE FROM consultations');
            $pdo->exec('DELETE FROM appointments');
            $pdo->exec('DELETE FROM medical_records');
            $pdo->exec('DELETE FROM hospital_patient_registrations');
            $pdo->exec('DELETE FROM consent_records');
            $pdo->exec('DELETE FROM consent_requests');
            $pdo->exec('DELETE FROM notifications');
            $pdo->exec('DELETE FROM patients');
            $pdo->exec('DELETE FROM mock_ekyc_verifications');
            $pdo->exec('DELETE FROM demo_health_identities');
            $pdo->exec('DELETE FROM doctors');
            $pdo->exec('DELETE FROM departments');
            $pdo->exec('DELETE FROM users');
            $pdo->exec('DELETE FROM hospitals');
            $pdo->exec('DELETE FROM audit_logs');

            // Re-enable foreign key checks
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // Commit transaction
            $pdo->commit();

            flash('success', 'All data has been cleared successfully! You can now create new accounts from scratch.');
            redirect('dashboard.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            flash('danger', 'Error clearing data: ' . $e->getMessage());
        }
    }
}
?>

<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow text-danger">⚠️ DANGER ZONE</p>
        <h1 class="h3 text-danger">Clear All Data</h1>
        <p class="text-muted mb-4">This will permanently delete ALL data from the system including:</p>
        <ul class="text-muted small mb-4">
            <li>All user accounts (admins, doctors, patients, receptionists)</li>
            <li>All hospitals and departments</li>
            <li>All appointments and medical records</li>
            <li>All consent requests</li>
            <li>All audit logs</li>
        </ul>
        <p class="text-danger fw-bold mb-4">This action cannot be undone!</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            
            <label class="form-label" for="confirmation">
                Type <strong>YES I UNDERSTAND</strong> to confirm:
            </label>
            <input 
                class="form-control mb-3" 
                id="confirmation" 
                type="text" 
                name="confirmation" 
                placeholder="YES I UNDERSTAND"
                required
            >
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">Delete All Data</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
