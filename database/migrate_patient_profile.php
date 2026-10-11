<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $query = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $query->execute([':table_name' => $table, ':column_name' => $column]);
    return (int) $query->fetchColumn() > 0;
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
        echo "Added $table.$column\n";
    }
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $query = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $query->execute([':table_name' => $table, ':index_name' => $index]);
    return (int) $query->fetchColumn() > 0;
}

$identityColumns = [
    'city' => 'city VARCHAR(100) NULL AFTER address',
    'state' => 'state VARCHAR(100) NULL AFTER city',
    'pincode' => 'pincode VARCHAR(10) NULL AFTER state',
    'government_id_type' => "government_id_type ENUM('Aadhaar','PAN Card','Passport','Driving Licence','Other') NULL AFTER pincode",
    'government_id_number' => 'government_id_number VARCHAR(100) NULL AFTER government_id_type',
    'emergency_contact_name' => 'emergency_contact_name VARCHAR(150) NULL AFTER government_id_number',
    'emergency_contact_relationship' => 'emergency_contact_relationship VARCHAR(100) NULL AFTER emergency_contact_name',
    'emergency_contact_number' => 'emergency_contact_number VARCHAR(15) NULL AFTER emergency_contact_relationship',
    'blood_group' => "blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown') NULL AFTER emergency_contact_number",
    'allergies' => 'allergies TEXT NULL AFTER blood_group',
    'existing_conditions' => 'existing_conditions TEXT NULL AFTER allergies',
];

$patientColumns = [
    'patient_id' => 'patient_id VARCHAR(30) NULL AFTER health_identity_id',
    'full_name' => 'full_name VARCHAR(150) NULL AFTER patient_id',
    'date_of_birth' => 'date_of_birth DATE NULL AFTER full_name',
    'gender' => "gender ENUM('male','female','other') NULL AFTER date_of_birth",
    'mobile' => 'mobile VARCHAR(15) NULL AFTER gender',
    'email' => 'email VARCHAR(150) NULL AFTER mobile',
    'address' => 'address TEXT NULL AFTER email',
    'city' => 'city VARCHAR(100) NULL AFTER address',
    'state' => 'state VARCHAR(100) NULL AFTER city',
    'pincode' => 'pincode VARCHAR(10) NULL AFTER state',
    'government_id_type' => "government_id_type ENUM('Aadhaar','PAN Card','Passport','Driving Licence','Other') NULL AFTER pincode",
    'government_id_number' => 'government_id_number VARCHAR(100) NULL AFTER government_id_type',
    'emergency_contact_name' => 'emergency_contact_name VARCHAR(150) NULL AFTER government_id_number',
    'emergency_contact_relationship' => 'emergency_contact_relationship VARCHAR(100) NULL AFTER emergency_contact_name',
    'emergency_contact_number' => 'emergency_contact_number VARCHAR(15) NULL AFTER emergency_contact_relationship',
    'blood_group' => "blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown') NULL AFTER emergency_contact_number",
    'allergies' => 'allergies TEXT NULL AFTER blood_group',
    'existing_conditions' => 'existing_conditions TEXT NULL AFTER allergies',
    'registration_date' => 'registration_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER existing_conditions',
];

foreach ($identityColumns as $column => $definition) {
    addColumnIfMissing($pdo, 'demo_health_identities', $column, $definition);
}

foreach ($patientColumns as $column => $definition) {
    addColumnIfMissing($pdo, 'patients', $column, $definition);
}

if (!indexExists($pdo, 'patients', 'patient_id')) {
    $pdo->exec('ALTER TABLE patients ADD UNIQUE KEY patient_id (patient_id)');
    echo "Added patients.patient_id unique index\n";
}

$patients = $pdo->query(
    'SELECT p.id, p.patient_id, p.hospital_patient_id, p.health_identity_id,
            i.full_name, i.date_of_birth, i.gender, i.mobile, i.email, i.address,
            i.city, i.state, i.pincode, i.government_id_type, i.government_id_number,
            i.emergency_contact_name, i.emergency_contact_relationship, i.emergency_contact_number,
            i.blood_group, i.allergies, i.existing_conditions
     FROM patients p
     JOIN demo_health_identities i ON i.id = p.health_identity_id'
)->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare(
    'UPDATE patients SET
        patient_id = :patient_id,
        hospital_patient_id = COALESCE(hospital_patient_id, :patient_id),
        full_name = COALESCE(full_name, :full_name),
        date_of_birth = COALESCE(date_of_birth, :date_of_birth),
        gender = COALESCE(gender, :gender),
        mobile = COALESCE(mobile, :mobile),
        email = COALESCE(email, :email),
        address = COALESCE(address, :address),
        city = COALESCE(city, :city),
        state = COALESCE(state, :state),
        pincode = COALESCE(pincode, :pincode),
        government_id_type = COALESCE(government_id_type, :government_id_type),
        government_id_number = COALESCE(government_id_number, :government_id_number),
        emergency_contact_name = COALESCE(emergency_contact_name, :emergency_contact_name),
        emergency_contact_relationship = COALESCE(emergency_contact_relationship, :emergency_contact_relationship),
        emergency_contact_number = COALESCE(emergency_contact_number, :emergency_contact_number),
        emergency_contact = COALESCE(emergency_contact, :emergency_contact_number),
        blood_group = COALESCE(blood_group, :blood_group),
        allergies = COALESCE(allergies, :allergies),
        existing_conditions = COALESCE(existing_conditions, :existing_conditions),
        registration_date = COALESCE(registration_date, created_at)
     WHERE id = :id'
);

foreach ($patients as $patient) {
    $patientId = $patient['patient_id'] ?: generatePatientId($pdo);
    $update->execute([
        ':patient_id' => $patientId,
        ':full_name' => $patient['full_name'],
        ':date_of_birth' => $patient['date_of_birth'],
        ':gender' => $patient['gender'],
        ':mobile' => $patient['mobile'],
        ':email' => $patient['email'],
        ':address' => $patient['address'],
        ':city' => $patient['city'],
        ':state' => $patient['state'],
        ':pincode' => $patient['pincode'],
        ':government_id_type' => $patient['government_id_type'],
        ':government_id_number' => $patient['government_id_number'],
        ':emergency_contact_name' => $patient['emergency_contact_name'],
        ':emergency_contact_relationship' => $patient['emergency_contact_relationship'],
        ':emergency_contact_number' => $patient['emergency_contact_number'],
        ':blood_group' => $patient['blood_group'] ?: 'Unknown',
        ':allergies' => $patient['allergies'],
        ':existing_conditions' => $patient['existing_conditions'],
        ':id' => $patient['id'],
    ]);
}

if ((int) $pdo->query('SELECT COUNT(*) FROM patients WHERE patient_id IS NULL OR patient_id = \'\'')->fetchColumn() === 0) {
    $pdo->exec('ALTER TABLE patients MODIFY patient_id VARCHAR(30) NOT NULL');
    echo "Ensured patients.patient_id is NOT NULL\n";
}

echo "Patient profile migration complete.\n";
