<?php

require_once __DIR__ . '/env.php';
loadEnvironment(dirname(__DIR__));

const BASE_URL = '/abha_hospital_system';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consumeFlashMessages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function dashboardForRole(string $role): string
{
    $dashboards = [
        'patient' => 'patient/dashboard.php',
        'receptionist' => 'receptionist/dashboard.php',
        'doctor' => 'doctor/dashboard.php',
        'consent_manager' => 'consent/dashboard.php',
        'admin' => 'admin/dashboard.php',
    ];

    return $dashboards[$role] ?? 'index.php';
}

function roleDisplayName(string $role): string
{
    return $role === 'receptionist' ? 'Front Desk Officer' : ucwords(str_replace('_', ' ', $role));
}

function appUrl(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function configuredHospital(PDO $pdo): ?array
{
    $hospital = $pdo->query('SELECT id, hospital_name, address, phone, email FROM hospitals ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $hospital ?: null;
}

function currentHospitalId(): ?int
{
    $user = currentUser();
    return isset($user['hospital_id']) ? (int) $user['hospital_id'] : null;
}

function generateDemoHealthId(PDO $pdo): string
{
    do {
        $healthId = 'DHI-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT id FROM demo_health_identities WHERE demo_health_id = :health_id LIMIT 1');
        $check->execute([':health_id' => $healthId]);
    } while ($check->fetchColumn());

    return $healthId;
}

function generatePatientId(PDO $pdo): string
{
    $year = date('Y');

    do {
        $sequenceQuery = $pdo->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(patient_id, 10) AS UNSIGNED)), 0) + 1
             FROM patients
             WHERE patient_id LIKE :prefix'
        );
        $sequenceQuery->execute([':prefix' => 'HMS-' . $year . '-%']);
        $sequence = (int) $sequenceQuery->fetchColumn();
        $patientId = 'HMS-' . $year . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT id FROM patients WHERE patient_id = :patient_id LIMIT 1');
        $check->execute([':patient_id' => $patientId]);
    } while ($check->fetchColumn());

    return $patientId;
}

function generateHospitalPatientId(PDO $pdo): string
{
    return generatePatientId($pdo);
}
