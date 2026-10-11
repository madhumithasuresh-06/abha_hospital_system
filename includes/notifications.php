<?php

require_once __DIR__ . '/../config/database.php';

function createNotification(PDO $pdo, int $userId, string $title, string $message): void
{
    $statement = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (:user_id, :title, :message)');
    $statement->execute([':user_id' => $userId, ':title' => $title, ':message' => $message]);
}

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $statement->execute([':user_id' => $userId]);
    return (int) $statement->fetchColumn();
}