<?php
require_once __DIR__ . '/notifications.php';
$loggedIn = function_exists('isLoggedIn') && isLoggedIn();
$unreadNotifications = $loggedIn ? unreadNotificationCount($pdo, (int) $_SESSION['user_id']) : 0;
?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(appUrl()) ?>"><span class="brand-mark">+</span><span>MediCore Hospital Portal</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="<?= e(appUrl()) ?>">Home</a>
                <a class="nav-link" href="<?= e(appUrl()) ?>#services">Services</a>
                <a class="nav-link" href="<?= e(appUrl()) ?>#about">About</a>
                <?php if ($loggedIn): ?>
                                        <a class="nav-link" href="<?= e(appUrl(dashboardForRole($_SESSION['role']))) ?>"><?= e(roleDisplayName($_SESSION['role'])) ?></a>
                    <?php if ($_SESSION['role'] === 'admin'): ?><a class="nav-link" href="<?= e(appUrl('update_admin.php')) ?>">Account</a><?php else: ?><a class="nav-link" href="<?= e(appUrl('change_password.php')) ?>">Password</a><?php endif; ?>
                    <?php if ($_SESSION['role'] === 'patient'): ?><a class="nav-link" href="<?= e(appUrl('patient/notifications.php')) ?>">Notifications<?php if ($unreadNotifications): ?> <span class="badge text-bg-warning"><?= $unreadNotifications ?></span><?php endif; ?></a><?php endif; ?>
                    <a class="btn btn-outline-light btn-sm ms-lg-2" href="<?= e(appUrl('logout.php')) ?>">Sign Out</a>
                <?php else: ?>
                    <a class="btn btn-light btn-sm ms-lg-2" href="<?= e(appUrl('sso_login.php')) ?>">Sign In</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
