<?php

require_once __DIR__ . '/../config/database.php';

function ensurePrescriptionDocumentsTable(PDO $pdo): void
{
    $tableCheck = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'prescription_documents'"
    )->fetchColumn();

    if (!$tableCheck) {
        $pdo->exec("
            CREATE TABLE prescription_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prescription_id INT NOT NULL,
                consultation_id INT NOT NULL,
                doctor_id INT NOT NULL,
                patient_id INT NOT NULL,
                unsigned_pdf_path VARCHAR(500) NULL,
                signed_pdf_path VARCHAR(500) NULL,
                signing_status ENUM('generated','awaiting_signature','signed','failed') DEFAULT 'generated',
                emsigner_transaction_id VARCHAR(100) NULL,
                emsigner_reference_number VARCHAR(100) NULL,
                emsigner_workflow_id VARCHAR(100) NULL,
                emsigner_document_id VARCHAR(100) NULL,
                emsigner_status VARCHAR(50) NULL,
                emsigner_response_code VARCHAR(50) NULL,
                signed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_presc_doc_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
                CONSTRAINT fk_presc_doc_consultation FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
                CONSTRAINT fk_presc_doc_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id),
                CONSTRAINT fk_presc_doc_patient FOREIGN KEY (patient_id) REFERENCES patients(id),

                UNIQUE KEY uk_prescription_doc (prescription_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "Created table prescription_documents\n";
    } else {
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prescription_documents'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('emsigner_workflow_id', $columns, true)) {
            $pdo->exec('ALTER TABLE prescription_documents ADD COLUMN emsigner_workflow_id VARCHAR(100) NULL AFTER emsigner_reference_number');
        }
        if (!in_array('emsigner_document_id', $columns, true)) {
            $pdo->exec('ALTER TABLE prescription_documents ADD COLUMN emsigner_document_id VARCHAR(100) NULL AFTER emsigner_workflow_id');
        }
        if (!in_array('emsigner_status', $columns, true)) {
            $pdo->exec('ALTER TABLE prescription_documents ADD COLUMN emsigner_status VARCHAR(50) NULL AFTER emsigner_document_id');
        }
        echo "Table prescription_documents already exists\n";
    }
}

if (php_sapi_name() === 'cli' || basename($_SERVER['PHP_SELF'] ?? '') === 'migrate_prescription_documents.php') {
    ensurePrescriptionDocumentsTable($pdo);
    echo "Migration completed.\n";
}
