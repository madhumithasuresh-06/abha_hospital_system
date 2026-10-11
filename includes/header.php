<?php

require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? 'ABHA-Inspired Hospital System';
$styleVersion = filemtime(__DIR__ . '/../assets/css/style.css') ?: time();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Hospital Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(appUrl('assets/css/style.css?v=' . $styleVersion)) ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/navbar.php'; ?>
<main class="container py-4">
<?php if (basename($_SERVER['PHP_SELF']) !== 'index.php'): ?>
    <div class="page-back">
        <?php if (!empty($backUrl)): ?>
            <a class="back-link" href="<?= e($backUrl) ?>" aria-label="Go back">&larr; Back</a>
        <?php else: ?>
            <button type="button" class="back-link" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '<?= e(appUrl()) ?>'; }" aria-label="Go back">&larr; Back</button>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php foreach (consumeFlashMessages() as $message): ?>
    <div class="alert alert-<?= e($message['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($message['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
