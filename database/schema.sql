CREATE DATABASE IF NOT EXISTS abha_hospital_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE abha_hospital_db;

-- ==========================================
-- ROLES
-- ==========================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO roles (role_name) VALUES
('patient'),
('receptionist'),
('doctor'),
('consent_manager'),
('admin');


-- ==========================================
-- USERS
-- ==========================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    hospital_id INT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    full_name VARCHAR(150),
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','inactive','blocked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
);


-- ==========================================
-- DEMO HEALTH IDENTITIES
-- ==========================================

CREATE TABLE demo_health_identities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    demo_health_id VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('male','female','other') NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    email VARCHAR(150),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    pincode VARCHAR(10),
    government_id_type ENUM('Aadhaar','PAN Card','Passport','Driving Licence','Other'),
    government_id_number VARCHAR(100),
    emergency_contact_name VARCHAR(150),
    emergency_contact_relationship VARCHAR(100),
    emergency_contact_number VARCHAR(15),
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'),
    allergies TEXT,
    existing_conditions TEXT,
    verification_status ENUM('pending','verified','failed')
        DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


-- ==========================================
-- MOCK EKYC
-- ==========================================

CREATE TABLE mock_ekyc_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    health_identity_id INT NOT NULL,
    document_type VARCHAR(50),
    document_reference VARCHAR(100),
    verification_status ENUM('pending','verified','failed')
        DEFAULT 'pending',
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ekyc_identity
        FOREIGN KEY (health_identity_id)
        REFERENCES demo_health_identities(id)
        ON DELETE CASCADE
);


-- ==========================================
-- PATIENTS
-- ==========================================

CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    health_identity_id INT NOT NULL UNIQUE,
    patient_id VARCHAR(30) NOT NULL UNIQUE,
    hospital_patient_id VARCHAR(30) UNIQUE,
    full_name VARCHAR(150),
    date_of_birth DATE,
    gender ENUM('male','female','other'),
    mobile VARCHAR(15),
    email VARCHAR(150),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    pincode VARCHAR(10),
    government_id_type ENUM('Aadhaar','PAN Card','Passport','Driving Licence','Other'),
    government_id_number VARCHAR(100),
    emergency_contact_name VARCHAR(150),
    emergency_contact_relationship VARCHAR(100),
    emergency_contact_number VARCHAR(15),
    emergency_contact VARCHAR(15),
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'),
    allergies TEXT,
    existing_conditions TEXT,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_patient_user
        FOREIGN KEY (user_id) REFERENCES users(id),

    CONSTRAINT fk_patient_identity
        FOREIGN KEY (health_identity_id)
        REFERENCES demo_health_identities(id)
);


-- ==========================================
-- HOSPITALS
-- ==========================================

CREATE TABLE hospitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name VARCHAR(150) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(150),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- ==========================================
-- DEPARTMENTS
-- ==========================================

CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_department_hospital
        FOREIGN KEY (hospital_id)
        REFERENCES hospitals(id)
        ON DELETE CASCADE
);


-- ==========================================
-- DOCTORS
-- ==========================================

CREATE TABLE doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    department_id INT NOT NULL,
    doctor_name VARCHAR(150) NOT NULL,
    specialization VARCHAR(100),
    license_number VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_doctor_user
        FOREIGN KEY (user_id) REFERENCES users(id),

    CONSTRAINT fk_doctor_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
);


-- ==========================================
-- HOSPITAL PATIENT REGISTRATION
-- ==========================================

CREATE TABLE hospital_patient_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    hospital_id INT NOT NULL,
    department_id INT NOT NULL,
    registration_status ENUM('pending','approved','rejected')
        DEFAULT 'pending',
    registered_by INT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_registration_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),

    CONSTRAINT fk_registration_hospital
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id),

    CONSTRAINT fk_registration_department
        FOREIGN KEY (department_id) REFERENCES departments(id),

    CONSTRAINT fk_registration_user
        FOREIGN KEY (registered_by) REFERENCES users(id)
);


-- ==========================================
-- APPOINTMENTS
-- ==========================================

CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    hospital_id INT NULL,
    doctor_id INT NULL,
    requested_by INT NULL,
    forwarded_by INT NULL,
    appointment_date DATETIME NOT NULL,
    reason TEXT,
    doctor_response TEXT,
    status ENUM('requested','scheduled','approved','rejected','reschedule_requested','completed','cancelled')
        DEFAULT 'requested',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_appointment_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),

    CONSTRAINT fk_appointments_hospital
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointment_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id),

    CONSTRAINT fk_appointments_requested_by
        FOREIGN KEY (requested_by) REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_appointments_forwarded_by
        FOREIGN KEY (forwarded_by) REFERENCES users(id)
        ON DELETE SET NULL
);


-- ==========================================
-- MEDICAL RECORDS
-- ==========================================

CREATE TABLE medical_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    hospital_id INT NULL,
    record_type VARCHAR(100),
    record_title VARCHAR(200),
    record_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_record_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),

    CONSTRAINT fk_record_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id),

    CONSTRAINT fk_medical_records_hospital
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id)
        ON DELETE RESTRICT
);


-- ==========================================
-- CONSULTATIONS
-- ==========================================

CREATE TABLE consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    symptoms TEXT,
    diagnosis TEXT,
    notes TEXT,
    consultation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_consult_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id),

    CONSTRAINT fk_consult_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),

    CONSTRAINT fk_consult_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(id)
);


-- ==========================================
-- PRESCRIPTIONS
-- ==========================================

CREATE TABLE prescriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    prescription_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_prescription_consultation
        FOREIGN KEY (consultation_id)
        REFERENCES consultations(id),

    CONSTRAINT fk_prescription_patient
        FOREIGN KEY (patient_id)
        REFERENCES patients(id),

    CONSTRAINT fk_prescription_doctor
        FOREIGN KEY (doctor_id)
        REFERENCES doctors(id)
);


-- ==========================================
-- PRESCRIPTION ITEMS
-- ==========================================

CREATE TABLE prescription_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    medicine_name VARCHAR(150) NOT NULL,
    dosage VARCHAR(100),
    frequency VARCHAR(100),
    duration VARCHAR(100),
    instructions TEXT,

    CONSTRAINT fk_prescription_item
        FOREIGN KEY (prescription_id)
        REFERENCES prescriptions(id)
        ON DELETE CASCADE
);


-- ==========================================
-- CONSENT REQUESTS
-- ==========================================

CREATE TABLE consent_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    requested_by INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    requested_data TEXT NOT NULL,
    status ENUM('pending','approved','denied','expired','revoked')
        DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,

    CONSTRAINT fk_consent_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id),

    CONSTRAINT fk_consent_requester
        FOREIGN KEY (requested_by) REFERENCES users(id)
);


-- ==========================================
-- CONSENT RECORDS
-- ==========================================

CREATE TABLE consent_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consent_request_id INT NOT NULL,
    patient_id INT NOT NULL,
    decision ENUM('approved','denied','revoked') NOT NULL,
    decision_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_consent_record_request
        FOREIGN KEY (consent_request_id)
        REFERENCES consent_requests(id),

    CONSTRAINT fk_consent_record_patient
        FOREIGN KEY (patient_id)
        REFERENCES patients(id)
);


-- ==========================================
-- AUDIT LOGS
-- ==========================================

CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role_name VARCHAR(50),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);


-- ==========================================
-- NOTIFICATIONS
-- ==========================================

CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

ALTER TABLE users
    ADD CONSTRAINT fk_users_hospital
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id)
        ON DELETE SET NULL;
