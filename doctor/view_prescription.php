<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/generate_prescription_pdf.php';
require_once __DIR__ . '/../includes/emsigner.php';

requireRole('doctor');

$doctorQuery = $pdo->prepare('SELECT d.id, d.doctor_name, u.email AS doctor_email FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.user_id = :user_id LIMIT 1');
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]);
$doctor = $doctorQuery->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    http_response_code(403);
    exit('Doctor profile not found.');
}

$prescriptionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'prescription_id', FILTER_VALIDATE_INT);

if (!$prescriptionId) {
    flash('danger', 'Invalid prescription ID.');
    redirect(appUrl('doctor/appointments.php'));
}

// Fetch Prescription Details
$prescQuery = $pdo->prepare("
    SELECT 
        pr.id AS prescription_id,
        pr.prescription_date,
        pr.consultation_id,
        pr.patient_id,
        pr.doctor_id,
        p.patient_id AS patient_code,
        p.full_name AS patient_name,
        p.gender,
        p.date_of_birth,
        p.mobile,
        i.demo_health_id,
        c.symptoms,
        c.diagnosis,
        c.notes,
        c.consultation_date,
        doc.id AS document_id,
        doc.unsigned_pdf_path,
        doc.signed_pdf_path,
        doc.signing_status,
        doc.emsigner_reference_number,
        doc.emsigner_workflow_id,
        doc.emsigner_document_id,
        doc.emsigner_status,
        doc.emsigner_transaction_id,
        doc.signed_at
    FROM prescriptions pr
    JOIN patients p ON p.id = pr.patient_id
    LEFT JOIN demo_health_identities i ON i.id = p.health_identity_id
    JOIN consultations c ON c.id = pr.consultation_id
    LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
    WHERE pr.id = :id AND pr.doctor_id = :doctor_id
    LIMIT 1
");
$prescQuery->execute([':id' => $prescriptionId, ':doctor_id' => $doctor['id']]);
$prescription = $prescQuery->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    http_response_code(404);
    exit('Prescription not found or unauthorized.');
}

// Fetch Medication Items
$itemsQuery = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = :id ORDER BY id ASC");
$itemsQuery->execute([':id' => $prescriptionId]);
$medicationItems = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

$missingRequirements = emsignerCheckRequirements();
$signingUrl = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Form session expired. Please retry.');
        redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
    }

    if ($action === 'generate_pdf') {
        try {
            $pdfResult = generatePrescriptionPdf($pdo, $prescriptionId);
            logAudit('PRESCRIPTION_PDF_GENERATED', 'prescription', $prescriptionId, 'Prescription PDF generated.');
            flash('success', 'Prescription PDF generated successfully. You can now digitally sign it.');
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        } catch (Throwable $e) {
            flash('danger', 'Error generating PDF: ' . $e->getMessage());
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        }
    }

    if ($action === 'initiate_signing') {
        if (!empty($missingRequirements)) {
            flash('danger', 'Cannot initiate signing: ' . implode('<br>', $missingRequirements));
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        }

        try {
            $unsignedFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'prescriptions' . DIRECTORY_SEPARATOR . 'prescription_' . $prescriptionId . '_unsigned.pdf';
            if (!file_exists($unsignedFile)) {
                generatePrescriptionPdf($pdo, $prescriptionId);
            }

            $referenceNumber = emsignerGenerateReferenceNumber($prescriptionId);

            $updateDoc = $pdo->prepare("
                UPDATE prescription_documents
                SET emsigner_reference_number = :ref,
                    emsigner_workflow_id = NULL,
                    emsigner_document_id = NULL,
                    emsigner_status = 'Signing initiated',
                    signing_status = 'awaiting_signature'
                WHERE prescription_id = :presc_id
            ");
            $updateDoc->execute([
                ':ref'      => $referenceNumber,
                ':presc_id' => $prescriptionId
            ]);

            $signingResult = emsignerInitiateSigning(
                $unsignedFile,
                $referenceNumber,
                $doctor['doctor_name'],
                $doctor['doctor_email']
            );

            $returnedReference = $signingResult['reference'];
            $workflowId = $signingResult['workflow_id'];
            if ($workflowId === null) {
                throw new EmSignerException('emSigner did not return a WorkflowId.');
            }
            $updateWorkflow = $pdo->prepare('UPDATE prescription_documents SET emsigner_reference_number = :ref, emsigner_workflow_id = :workflow_id, emsigner_document_id = :document_id, emsigner_status = :status, emsigner_response_code = :response_code WHERE prescription_id = :presc_id');
            $updateWorkflow->execute([
                ':ref' => $returnedReference,
                ':workflow_id' => $workflowId,
                ':document_id' => $signingResult['document_id'],
                ':status' => $signingResult['status'] !== null ? substr($signingResult['status'], 0, 50) : 'Signing initiated',
                ':response_code' => $signingResult['status'] !== null ? substr($signingResult['status'], 0, 50) : null,
                ':presc_id' => $prescriptionId,
            ]);
            $signingUrl = $signingResult['signing_url'];

            logAudit('EMSIGNER_SIGNING_INITIATED', 'prescription', $prescriptionId, 'emSigner REST signing workflow initiated with Ref: ' . $returnedReference);

            if ($signingUrl !== null) {
                header('Location: ' . $signingUrl);
                exit;
            }
            flash('info', 'Signing has been initiated. Complete the emSigner request, then return here to refresh the status.');
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));

        } catch (Throwable $e) {
            $pdo->prepare("UPDATE prescription_documents SET signing_status = 'failed', emsigner_status = 'Failed', emsigner_response_code = 'INITIATION_FAILED' WHERE prescription_id = :prescription_id")
                ->execute([':prescription_id' => $prescriptionId]);
            flash('danger', 'Failed to prepare emSigner signing request: ' . $e->getMessage());
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        }
    }

    if ($action === 'poll_signing') {
        $workflowId = trim((string)($prescription['emsigner_workflow_id'] ?? ''));
        $referenceNumber = trim((string)($prescription['emsigner_reference_number'] ?? ''));
        if ($referenceNumber === '') {
            flash('danger', 'No emSigner reference is available to check.');
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        }
        try {
            $completion = emsignerFinalizePrescription(
                $pdo,
                $prescriptionId,
                $workflowId,
                $referenceNumber,
                $prescription['emsigner_transaction_id'] ?? null,
                $prescription['emsigner_document_id'] ?? null
            );
            if ($completion['workflow_id'] !== null && $completion['workflow_id'] !== $workflowId) {
                $updateWorkflowId = $pdo->prepare("UPDATE prescription_documents SET emsigner_workflow_id = :workflow_id WHERE prescription_id = :prescription_id");
                $updateWorkflowId->execute([':workflow_id' => $completion['workflow_id'], ':prescription_id' => $prescriptionId]);
            }
            if ($completion['status'] === 'signed') {
                $updateDocumentId = $pdo->prepare("UPDATE prescription_documents SET emsigner_document_id = :document_id, emsigner_status = 'Completed' WHERE prescription_id = :prescription_id");
                $updateDocumentId->execute([':document_id' => $completion['document_id'], ':prescription_id' => $prescriptionId]);
                flash('success', 'Prescription was digitally signed and saved successfully.');
            } elseif ($completion['status'] === 'failed') {
                $pdo->prepare("UPDATE prescription_documents SET signing_status = 'failed', emsigner_status = 'Failed' WHERE prescription_id = :prescription_id")
                    ->execute([':prescription_id' => $prescriptionId]);
                flash('danger', 'emSigner reported that signing failed.');
            } else {
                flash('info', 'Signing is still pending. ' . $completion['diagnostic']);
            }
        } catch (Throwable $e) {
            flash('danger', 'Signed document retrieval failed: ' . $e->getMessage());
        }
        redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
    }

}

$pageTitle = 'Prescription Details & Digital Signing';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <p class="eyebrow mb-1">Clinical Documentation & Compliance</p>
        <h1 class="h2 mb-0">Prescription #RX-<?= date('Y', strtotime($prescription['prescription_date'])) ?>-<?= str_pad((string)$prescriptionId, 6, '0', STR_PAD_LEFT) ?></h1>
    </div>
    <a href="<?= e(appUrl('doctor/appointments.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Appointments</a>
</div>

<!-- Status & Action Bar -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-muted me-2">Digital Signature Status:</span>
            <?php 
            $status = $prescription['signing_status'] ?? 'pending';
            if ($status === 'signed'): ?>
                <span class="badge bg-success fs-6"><i class="bi bi-shield-check me-1"></i> Digitally Signed (eMudhra emSigner)</span>
                <?php if (!empty($prescription['signed_at'])): ?>
                    <small class="text-muted ms-2">Signed on: <?= date('d-M-Y H:i A', strtotime($prescription['signed_at'])) ?></small>
                <?php endif; ?>
            <?php elseif ($status === 'awaiting_signature'): ?>
                <span class="badge bg-warning text-dark fs-6"><i class="bi bi-clock-history me-1"></i> Awaiting Signature</span>
            <?php elseif ($status === 'generated'): ?>
                <span class="badge bg-info text-dark fs-6"><i class="bi bi-file-earmark-pdf me-1"></i> PDF Generated (Unsigned)</span>
            <?php elseif ($status === 'failed'): ?>
                <span class="badge bg-danger fs-6"><i class="bi bi-exclamation-triangle me-1"></i> Signing Failed</span>
            <?php else: ?>
                <span class="badge bg-secondary fs-6">No PDF Generated</span>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($status === 'signed'): ?>
                <a href="<?= e(appUrl('doctor/serve_prescription_pdf.php?id=' . $prescriptionId . '&mode=view')) ?>" target="_blank" class="btn btn-success">
                    <i class="bi bi-file-earmark-check"></i> View Signed PDF
                </a>
                <a href="<?= e(appUrl('doctor/serve_prescription_pdf.php?id=' . $prescriptionId . '&mode=download')) ?>" class="btn btn-outline-success">
                    <i class="bi bi-download"></i> Download Signed PDF
                </a>
            <?php elseif ($status === 'awaiting_signature' || $status === 'generated' || $status === 'failed'): ?>
                <?php if ($status === 'awaiting_signature' && !empty($prescription['emsigner_reference_number'])): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                        <input type="hidden" name="action" value="poll_signing">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-repeat"></i> Check Signing Status
                        </button>
                    </form>
                <?php endif; ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                    <input type="hidden" name="action" value="initiate_signing">
                    <button type="submit" class="btn btn-primary" <?= !empty($missingRequirements) ? 'disabled' : '' ?>>
                        <i class="bi bi-pen"></i> Sign PDF with emSigner
                    </button>
                </form>
                <a href="<?= e(appUrl('doctor/serve_prescription_pdf.php?id=' . $prescriptionId . '&mode=view')) ?>" target="_blank" class="btn btn-outline-secondary">
                    <i class="bi bi-eye"></i> Preview Unsigned PDF
                </a>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                    <input type="hidden" name="action" value="generate_pdf">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Re-generate PDF
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                    <input type="hidden" name="action" value="generate_pdf">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus"></i> Generate Prescription PDF
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($missingRequirements) && $status !== 'signed'): ?>
    <div class="alert alert-warning border-warning">
        <h6 class="alert-heading fw-bold"><i class="bi bi-info-circle me-1"></i> eMudhra emSigner Configuration Required</h6>
        <ul class="mb-0 small">
            <?php foreach ($missingRequirements as $req): ?>
                <li><?= e($req) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Clinical Information Summary -->
<div class="row g-4 mb-4">
    <!-- Patient Card -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="card-title text-primary mb-0"><i class="bi bi-person me-2"></i>Patient Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width: 40%;">Full Name:</td><td class="fw-bold"><?= e($prescription['patient_name']) ?></td></tr>
                    <tr><td class="text-muted">Patient ID:</td><td><?= e($prescription['patient_code'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Health ID (ABHA):</td><td><?= e($prescription['demo_health_id'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Gender / DOB:</td><td><?= e(ucfirst($prescription['gender'] ?? '')) ?> / <?= e($prescription['date_of_birth'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Mobile:</td><td><?= e($prescription['mobile'] ?? 'N/A') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Consultation & Diagnosis Card -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="card-title text-primary mb-0"><i class="bi bi-clipboard2-pulse me-2"></i>Consultation Assessment</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width: 35%;">Consultation Date:</td><td><?= e(date('d-M-Y H:i', strtotime($prescription['consultation_date'] ?? $prescription['prescription_date']))) ?></td></tr>
                    <tr><td class="text-muted">Symptoms:</td><td><?= e($prescription['symptoms'] ?: 'None recorded') ?></td></tr>
                    <tr><td class="text-muted">Diagnosis:</td><td class="fw-bold text-danger"><?= e($prescription['diagnosis'] ?: 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Clinical Notes:</td><td><?= e($prescription['notes'] ?: 'None recorded') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Prescription Items Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title text-primary mb-0"><i class="bi bi-capsule me-2"></i>Prescribed Medications</h5>
        <span class="badge bg-light text-dark"><?= count($medicationItems) ?> item(s)</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 30%;">Medicine Name</th>
                        <th style="width: 15%;">Dosage</th>
                        <th style="width: 15%;">Frequency</th>
                        <th style="width: 15%;">Duration</th>
                        <th style="width: 20%;">Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($medicationItems): ?>
                        <?php foreach ($medicationItems as $idx => $item): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td class="fw-semibold text-dark"><?= e($item['medicine_name']) ?></td>
                                <td><?= e($item['dosage'] ?: '-') ?></td>
                                <td><?= e($item['frequency'] ?: '-') ?></td>
                                <td><?= e($item['duration'] ?: '-') ?></td>
                                <td><span class="text-muted"><?= e($item['instructions'] ?: 'As directed') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">No medications recorded for this prescription.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
