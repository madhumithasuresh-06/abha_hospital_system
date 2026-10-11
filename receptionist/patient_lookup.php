<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
requireRole('receptionist');

$patient = null;
$healthId = filter_input(INPUT_GET, 'health_id') ?: filter_input(INPUT_POST, 'health_id');

if ($healthId) {
    $query = $pdo->prepare('
        SELECT 
            p.id, p.patient_id, p.full_name, p.date_of_birth, p.gender, p.mobile, p.email,
            p.address, p.city, p.state, p.pincode, p.blood_group, p.allergies, p.existing_conditions,
            i.full_name AS health_name, i.demo_health_id, i.government_id_type, i.government_id_number,
            i.emergency_contact_name, i.emergency_contact_relationship, i.emergency_contact_number
        FROM patients p
        JOIN demo_health_identities i ON i.id = p.health_identity_id
        WHERE i.demo_health_id = :health_id LIMIT 1
    ');
    $query->execute([':health_id' => $healthId]);
    $patient = $query->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Patient Lookup';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="eyebrow">Front Desk Officer</p>
        <h1 class="h2">Patient Lookup</h1>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="post" class="d-flex gap-2">
            <input type="text" name="health_id" class="form-control" placeholder="Enter Health ID (e.g., DHI-2026-848935)" value="<?= e($healthId) ?>">
            <button class="btn btn-primary">Search</button>
        </form>
    </div>
</div>

<?php if ($patient): ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Personal Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="small text-muted">Full Name</label>
                    <p class="mb-0"><?= e($patient['full_name']) ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Health ID</label>
                    <p class="mb-0"><?= e($patient['demo_health_id']) ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Patient ID</label>
                    <p class="mb-0"><?= e($patient['patient_id']) ?></p>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="small text-muted">Date of Birth</label>
                        <p class="mb-0"><?= e($patient['date_of_birth']) ?></p>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="small text-muted">Gender</label>
                        <p class="mb-0"><?= e(ucfirst($patient['gender'])) ?></p>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Mobile</label>
                    <p class="mb-0"><?= e($patient['mobile']) ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Email</label>
                    <p class="mb-0"><?= e($patient['email'] ?: 'Not provided') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Address & Emergency Contact</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="small text-muted">Address</label>
                    <p class="mb-0"><?= e($patient['address'] ?: 'Not provided') ?></p>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="small text-muted">City</label>
                        <p class="mb-0"><?= e($patient['city'] ?: '-') ?></p>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="small text-muted">State</label>
                        <p class="mb-0"><?= e($patient['state'] ?: '-') ?></p>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Pincode</label>
                    <p class="mb-0"><?= e($patient['pincode'] ?: '-') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Emergency Contact Name</label>
                    <p class="mb-0"><?= e($patient['emergency_contact_name'] ?: 'Not provided') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Relationship</label>
                    <p class="mb-0"><?= e($patient['emergency_contact_relationship'] ?: '-') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Emergency Contact Number</label>
                    <p class="mb-0"><?= e($patient['emergency_contact_number'] ?: '-') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Medical Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="small text-muted">Blood Group</label>
                    <p class="mb-0"><?= e($patient['blood_group'] ?: 'Unknown') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Allergies</label>
                    <p class="mb-0"><?= e($patient['allergies'] ?: 'None recorded') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Existing Conditions</label>
                    <p class="mb-0"><?= e($patient['existing_conditions'] ?: 'None recorded') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Government ID</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="small text-muted">ID Type</label>
                    <p class="mb-0"><?= e($patient['government_id_type'] ?: 'Not provided') ?></p>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">ID Number</label>
                    <p class="mb-0"><?= e($patient['government_id_number'] ?: 'Not provided') ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($healthId): ?>
<div class="alert alert-warning">
    No patient found with Health ID: <strong><?= e($healthId) ?></strong>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
