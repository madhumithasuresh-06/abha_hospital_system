<?php

require_once __DIR__ . '/../config/database.php';

function logAudit(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    ?int $userId = null,
    ?string $roleName = null
): void {
    global $pdo;

    $userId ??= $_SESSION['user_id'] ?? null;
    $roleName ??= $_SESSION['role'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // Verify user exists before inserting (handle case where users were cleared)
    if ($userId !== null) {
        $userExists = $pdo->prepare('SELECT 1 FROM users WHERE id = :user_id');
        $userExists->execute([':user_id' => $userId]);
        if (!$userExists->fetch()) {
            $userId = null; // Set to NULL if user doesn't exist
        }
    }

    $statement = $pdo->prepare(
        'INSERT INTO audit_logs
            (user_id, role_name, action, entity_type, entity_id, description, ip_address)
         VALUES (:user_id, :role_name, :action, :entity_type, :entity_id, :description, :ip_address)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':role_name' => $roleName,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':description' => $description,
        ':ip_address' => $ipAddress,
    ]);
}
