<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospitalId = filter_input(INPUT_POST, 'hospital_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? 'active';
    $name = trim($_POST['hospital_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null) || !in_array($status, ['active', 'inactive'], true) || $name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        flash('danger', 'Enter a hospital name and valid email.');
    } else {
        if ($hospitalId) {
            $save = $pdo->prepare('UPDATE hospitals SET hospital_name = :name, address = :address, phone = :phone, email = :email, status = :status WHERE id = :id');
            $save->execute([':name' => $name, ':address' => $address ?: null, ':phone' => $phone ?: null, ':email' => $email ?: null, ':status' => $status, ':id' => $hospitalId]);
            logAudit('HOSPITAL_UPDATED', 'hospital', $hospitalId, 'Hospital information updated.');
        } else {
            $save = $pdo->prepare('INSERT INTO hospitals (hospital_name, address, phone, email) VALUES (:name, :address, :phone, :email)');
            $save->execute([':name' => $name, ':address' => $address ?: null, ':phone' => $phone ?: null, ':email' => $email ?: null]);
            logAudit('HOSPITAL_CREATED', 'hospital', (int) $pdo->lastInsertId(), 'Hospital created.');
        }
        flash('success', $hospitalId ? 'Hospital updated.' : 'Hospital added.');
        redirect('hospitals.php');
    }
}
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT); $editHospital = null; if ($editId) { $editQuery = $pdo->prepare('SELECT * FROM hospitals WHERE id = :id LIMIT 1'); $editQuery->execute([':id' => $editId]); $editHospital = $editQuery->fetch(PDO::FETCH_ASSOC); }
$hospitals = $pdo->query('SELECT * FROM hospitals ORDER BY hospital_name')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Hospitals'; require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Administration</p><h1 class="h2">Hospital Network</h1></div><span class="badge text-bg-dark">Admin</span></div>
<div class="row g-4"><div class="col-lg-5"><div class="setup-card"><h2 class="h5"><?= $editHospital ? 'Edit hospital' : 'Add hospital' ?></h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="hospital_id" value="<?= (int) ($editHospital['id'] ?? 0) ?>"><label class="form-label">Hospital name</label><input class="form-control mb-3" name="hospital_name" value="<?= e($editHospital['hospital_name'] ?? '') ?>" required><label class="form-label">Address</label><textarea class="form-control mb-3" name="address" rows="2"><?= e($editHospital['address'] ?? '') ?></textarea><label class="form-label">Phone</label><input class="form-control mb-3" name="phone" value="<?= e($editHospital['phone'] ?? '') ?>"><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" value="<?= e($editHospital['email'] ?? '') ?>"><label class="form-label">Status</label><select class="form-select mb-3" name="status"><option value="active" <?= (($editHospital['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option><option value="inactive" <?= (($editHospital['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option></select><button class="btn btn-primary"><?= $editHospital ? 'Save hospital' : 'Add hospital' ?></button></form></div></div><div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Configured hospitals</h2><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Status</th><th>Phone</th><th></th></tr></thead><tbody><?php foreach ($hospitals as $hospital): ?><tr><td><?= e($hospital['hospital_name']) ?></td><td><?= e($hospital['status']) ?></td><td><?= e($hospital['phone']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int) $hospital['id'] ?>">Edit</a></td></tr><?php endforeach; ?><?php if (!$hospitals): ?><tr><td colspan="4" class="text-muted">No hospitals added yet.</td></tr><?php endif; ?></tbody></table></div></div></div></div></div><?php require __DIR__ . '/../includes/footer.php'; ?>
