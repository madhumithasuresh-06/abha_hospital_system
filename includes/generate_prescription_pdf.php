<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/fpdf/fpdf.php';
require_once __DIR__ . '/functions.php';

class PrescriptionPDF extends FPDF
{
    private array $hospitalInfo = [];

    public function setHospitalInfo(array $hospital): void
    {
        $this->hospitalInfo = $hospital;
    }

    public function Header(): void
    {
        $hospitalName = $this->hospitalInfo['hospital_name'] ?? 'MediCore Multispeciality Hospital';
        $hospitalAddress = $this->hospitalInfo['address'] ?? 'Healthcare Avenue, Medical City';
        $hospitalPhone = $this->hospitalInfo['phone'] ?? '+91 1800-123-4567';
        $hospitalEmail = $this->hospitalInfo['email'] ?? 'care@medicorehospital.local';

        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(30, 64, 175); // Royal Blue
        $this->Cell(0, 8, $hospitalName, 0, 1, 'C');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100, 116, 139); // Slate Grey
        $this->Cell(0, 5, $hospitalAddress . ' | Tel: ' . $hospitalPhone . ' | Email: ' . $hospitalEmail, 0, 1, 'C');

        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.4);
        $this->Line(10, 24, 200, 24);

        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} - MediCore Healthcare Digital Records System', 0, 0, 'C');
    }
}

function generatePrescriptionPdf(PDO $pdo, int $prescriptionId): array
{
    $prescQuery = $pdo->prepare("
        SELECT 
            pr.id AS prescription_id,
            pr.prescription_date,
            pr.consultation_id,
            pr.doctor_id,
            pr.patient_id,
            c.symptoms,
            c.diagnosis,
            c.notes,
            c.consultation_date,
            p.patient_id AS patient_code,
            p.full_name AS patient_name,
            p.date_of_birth,
            p.gender,
            p.mobile AS patient_mobile,
            p.blood_group,
            p.allergies,
            p.existing_conditions,
            i.demo_health_id,
            d.doctor_name,
            d.specialization,
            d.license_number,
            dept.department_name,
            h.id AS hospital_id,
            h.hospital_name,
            h.address AS hospital_address,
            h.phone AS hospital_phone,
            h.email AS hospital_email
        FROM prescriptions pr
        JOIN consultations c ON c.id = pr.consultation_id
        JOIN patients p ON p.id = pr.patient_id
        LEFT JOIN demo_health_identities i ON i.id = p.health_identity_id
        JOIN doctors d ON d.id = pr.doctor_id
        LEFT JOIN departments dept ON dept.id = d.department_id
        LEFT JOIN users u ON u.id = d.user_id
        LEFT JOIN hospitals h ON h.id = u.hospital_id
        WHERE pr.id = :prescription_id
        LIMIT 1
    ");
    $prescQuery->execute([':prescription_id' => $prescriptionId]);
    $data = $prescQuery->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new RuntimeException("Prescription not found with ID: " . $prescriptionId);
    }

    $itemsQuery = $pdo->prepare("
        SELECT medicine_name, dosage, frequency, duration, instructions
        FROM prescription_items
        WHERE prescription_id = :prescription_id
        ORDER BY id ASC
    ");
    $itemsQuery->execute([':prescription_id' => $prescriptionId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

    $hospitalInfo = [
        'hospital_name' => $data['hospital_name'] ?: 'MediCore General Hospital',
        'address' => $data['hospital_address'] ?: 'ABHA Integrated Health Facility',
        'phone' => $data['hospital_phone'] ?: '+91 1800-456-7890',
        'email' => $data['hospital_email'] ?: 'support@medicore.local'
    ];

    $pdf = new PrescriptionPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->setHospitalInfo($hospitalInfo);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 25);

    // Prescription Title Banner
    $pdf->SetFillColor(238, 242, 255); // Light Indigo
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->RoundedRect = function($x, $y, $w, $h) use ($pdf) {
        $pdf->Rect($x, $y, $w, $h, 'DF');
    };
    $pdf->Rect(10, 27, 190, 8, 'DF');
    $pdf->SetXY(10, 27);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(49, 46, 129);
    $pdf->Cell(95, 8, '  MEDICAL PRESCRIPTION & CLINICAL SUMMARY', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 8, 'Prescription #: RX-' . date('Y', strtotime($data['prescription_date'])) . '-' . str_pad((string)$prescriptionId, 6, '0', STR_PAD_LEFT) . '  ', 0, 1, 'R');

    $pdf->Ln(3);

    // Two Column Layout: Patient Details (Left) & Doctor / Visit Details (Right)
    $yStart = $pdf->GetY();

    // --- LEFT COLUMN: Patient Info ---
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Rect(10, $yStart, 93, 38, 'DF');

    $pdf->SetXY(12, $yStart + 2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(89, 4.5, 'PATIENT INFORMATION', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(12);
    $pdf->Cell(25, 4.5, 'Name:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['patient_name'], 0, 1);

    $pdf->SetX(12);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Patient ID:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['patient_code'] ?? 'N/A', 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Health ID (ABHA):', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['demo_health_id'] ?? 'N/A', 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Gender / DOB:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $genderStr = ucfirst($data['gender'] ?? 'Unknown');
    $dobStr = $data['date_of_birth'] ? date('d-M-Y', strtotime($data['date_of_birth'])) : 'N/A';
    $pdf->Cell(64, 4.5, $genderStr . ' / ' . $dobStr, 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Mobile / Blood:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, ($data['patient_mobile'] ?? 'N/A') . ' | ' . ($data['blood_group'] ?? 'Unknown'), 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Known Allergies:', 0, 0);
    $pdf->SetTextColor(185, 28, 28);
    $pdf->Cell(64, 4.5, $data['allergies'] ?: 'None Reported', 0, 1);

    // --- RIGHT COLUMN: Doctor & Consultation Info ---
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect(107, $yStart, 93, 38, 'DF');

    $pdf->SetXY(109, $yStart + 2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(89, 4.5, 'DOCTOR & VISIT INFORMATION', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(109);
    $pdf->Cell(28, 4.5, 'Doctor Name:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, $data['doctor_name'], 0, 1);

    $pdf->SetX(109);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Specialization:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, ($data['specialization'] ?: 'General Physician') . ' (' . ($data['department_name'] ?: 'Clinical') . ')', 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'License Number:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, $data['license_number'] ?: 'REG-MCI-VALID', 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Consultation Date:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, date('d-M-Y H:i A', strtotime($data['consultation_date'] ?? $data['prescription_date'])), 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Prescription Date:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, date('d-M-Y', strtotime($data['prescription_date'])), 0, 1);

    $pdf->SetY($yStart + 41);

    // --- CLINICAL FINDINGS SECTION (Symptoms, Diagnosis, Notes) ---
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(190, 6, '  CLINICAL FINDINGS & DIAGNOSIS', 1, 1, 'L', true);

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(51, 65, 85);

    $pdf->Cell(35, 6, ' Symptoms Recorded:', 'L', 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->Cell(155, 6, $data['symptoms'] ?: 'None recorded during visit', 'R', 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(35, 6, ' Primary Diagnosis:', 'L', 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(185, 28, 28);
    $pdf->Cell(155, 6, $data['diagnosis'] ?: 'Clinical Assessment Completed', 'R', 1, 'L');

    if (!empty($data['notes'])) {
        $pdf->SetTextColor(51, 65, 85);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(35, 6, ' Clinical Notes:', 'L', 0, 'L');
        $pdf->Cell(155, 6, $data['notes'], 'R', 1, 'L');
    }

    $pdf->Cell(190, 0, '', 'T', 1);
    $pdf->Ln(3);

    // --- RX MEDICATION TABLE ---
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(30, 64, 175);
    $pdf->Cell(190, 6, 'Rx - PRESCRIBED MEDICATIONS', 0, 1, 'L');

    // Table Header
    $pdf->SetFillColor(30, 64, 175);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(30, 64, 175);
    $pdf->SetFont('Helvetica', 'B', 8.5);

    $pdf->Cell(10, 7, '#', 1, 0, 'C', true);
    $pdf->Cell(60, 7, 'Medicine Name', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Dosage', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Frequency', 1, 0, 'L', true);
    $pdf->Cell(25, 7, 'Duration', 1, 0, 'L', true);
    $pdf->Cell(35, 7, 'Instructions', 1, 1, 'L', true);

    // Table Body
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetDrawColor(226, 232, 240);
    $fill = false;
    $itemIndex = 1;

    foreach ($items as $item) {
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell(10, 6.5, (string)$itemIndex, 1, 0, 'C', true);
        $pdf->Cell(60, 6.5, $item['medicine_name'], 1, 0, 'L', true);

        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(30, 6.5, $item['dosage'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(30, 6.5, $item['frequency'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(25, 6.5, $item['duration'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(35, 6.5, $item['instructions'] ?: 'As directed', 1, 1, 'L', true);

        $fill = !$fill;
        $itemIndex++;
    }

    if (empty($items)) {
        $pdf->Cell(190, 7, 'No medication items recorded.', 1, 1, 'C');
    }

    $pdf->Ln(6);

    // --- DIGITAL SIGNATURE & VERIFICATION SECTION ---
    $sigBoxY = $pdf->GetY();
    if ($sigBoxY > 205) {
        $pdf->AddPage();
        $sigBoxY = $pdf->GetY();
    }

    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect(10, $sigBoxY, 190, 64, 'DF');

    $pdf->SetXY(13, $sigBoxY + 3);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(100, 4.5, 'DIGITAL AUTHENTICATION & COMPLIANCE', 0, 0, 'L');
    $pdf->Cell(80, 4.5, 'ATTENDING PHYSICIAN', 0, 1, 'R');

    $pdf->SetXY(13, $sigBoxY + 8);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell(100, 3.8, "This electronic medical prescription is prepared in accordance with the Information Technology Act, 2000 and National Digital Health Mission guidelines.\nDigital signature via eMudhra emSigner binds the certified medical practitioner's credential.", 0, 'L');

    // Right Side: Physician Signature Box & Coordinate Region
    $pdf->SetXY(118, $sigBoxY + 8);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(78, 4.5, $data['doctor_name'], 0, 1, 'R');

    $pdf->SetXY(118, $sigBoxY + 12.5);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(78, 3.8, ($data['specialization'] ?: 'Medical Practitioner') . ' | Reg: ' . ($data['license_number'] ?: 'MCI-VALID'), 0, 1, 'R');

    $pdf->SetXY(118, $sigBoxY + 16.5);
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->Cell(78, 4, '[ eMudhra emSigner Digital Signature Area ]', 0, 1, 'R');

    $pdf->SetXY(118, $sigBoxY + 28);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(78, 3.5, 'Generated: ' . date('d-M-Y H:i:s T'), 0, 1, 'R');

    $pdf->SetXY(13, $sigBoxY + 37.5);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(183, 4.5, 'APPROVER / REVIEWING AUTHORITY', 0, 1, 'L');

    $pdf->SetXY(13, $sigBoxY + 43);
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->Cell(183, 4, '[ eMudhra emSigner Digital Signature Area ]', 0, 1, 'L');

    // Save File
    $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'prescriptions';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $fileName = 'prescription_' . $prescriptionId . '_unsigned.pdf';
    $filePath = $storageDir . DIRECTORY_SEPARATOR . $fileName;
    $relativePdfPath = 'storage/prescriptions/' . $fileName;

    $pdf->Output('F', $filePath);

    // Update or Insert into prescription_documents
    $docCheck = $pdo->prepare("SELECT id, signing_status, signed_pdf_path FROM prescription_documents WHERE prescription_id = :prescription_id LIMIT 1");
    $docCheck->execute([':prescription_id' => $prescriptionId]);
    $existingDoc = $docCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingDoc) {
        $updateDoc = $pdo->prepare("
            UPDATE prescription_documents
            SET unsigned_pdf_path = :unsigned_path,
                consultation_id = :consultation_id,
                doctor_id = :doctor_id,
                patient_id = :patient_id
            WHERE id = :id
        ");
        $updateDoc->execute([
            ':unsigned_path' => $relativePdfPath,
            ':consultation_id' => $data['consultation_id'],
            ':doctor_id' => $data['doctor_id'],
            ':patient_id' => $data['patient_id'],
            ':id' => $existingDoc['id']
        ]);
        $documentId = (int)$existingDoc['id'];
        $signingStatus = $existingDoc['signing_status'];
    } else {
        $insertDoc = $pdo->prepare("
            INSERT INTO prescription_documents (
                prescription_id, consultation_id, doctor_id, patient_id, unsigned_pdf_path, signing_status
            ) VALUES (
                :prescription_id, :consultation_id, :doctor_id, :patient_id, :unsigned_path, 'generated'
            )
        ");
        $insertDoc->execute([
            ':prescription_id' => $prescriptionId,
            ':consultation_id' => $data['consultation_id'],
            ':doctor_id' => $data['doctor_id'],
            ':patient_id' => $data['patient_id'],
            ':unsigned_path' => $relativePdfPath
        ]);
        $documentId = (int)$pdo->lastInsertId();
        $signingStatus = 'generated';
    }

    return [
        'document_id' => $documentId,
        'prescription_id' => $prescriptionId,
        'file_path' => $filePath,
        'relative_path' => $relativePdfPath,
        'signing_status' => $signingStatus,
        'patient_name' => $data['patient_name'],
        'doctor_name' => $data['doctor_name'],
        'prescription_date' => $data['prescription_date']
    ];
}
