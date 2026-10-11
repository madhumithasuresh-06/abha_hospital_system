<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/otp.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/audit.php';

if (empty($_SESSION['google_pending_user'])) {
    flash('danger', 'Google Sign-In session expired. Please try again.');
    redirect('login.php');
}

$user = $_SESSION['google_pending_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? 'verify_otp';

        if ($action === 'begin_otp') {
            if (!canResendOtp()) {
                flash('warning', 'Please wait before requesting another OTP code.');
            } else {
                $result = beginOtpChallenge((int) $user['id'], $user['email'], $user['role_name'], 'google_login');
                if ($result['success']) {
                    flash('success', 'A new verification code has been sent to ' . $result['masked_email'] . '.');
                } else {
                    flash('danger', 'The verification code could not be sent. Please try again.');
                }
            }
        } elseif ($action === 'verify_otp') {
            $otp = trim((string) ($_POST['otp'] ?? ''));
            if ($otp === '') {
                flash('danger', 'Enter the verification code sent to your email.');
            } elseif (verifyOtpChallenge($otp)) {
                unset($_SESSION['google_pending_user'], $_SESSION['google_pending_email']);
                ssoLogin($user);
                createApplicationSession();
                logAudit('GOOGLE_LOGIN', 'user', (int) $user['id'], 'User authenticated with Google + OTP.', (int) $user['id'], $user['role_name']);
                redirect(dashboardForRole($user['role_name']));
            } else {
                $attemptsLeft = (int) ((getOtpChallenge()['otp_attempts_left'] ?? 0));
                if ($attemptsLeft > 0) {
                    flash('danger', 'Incorrect verification code. ' . $attemptsLeft . ' attempt(s) left.');
                } else {
                    flash('danger', 'Too many incorrect attempts. Please request a new verification code.');
                }
            }
        }
    }
}

$challenge = getOtpChallenge();
$otpSent = is_array($challenge) && ($challenge['email'] ?? null) !== null;
$pageTitle = 'Verify Google Sign-In';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <p class="eyebrow">Secure verification</p>
                <h1 class="h3">Verify your email</h1>
                <?php if ($otpSent): ?>
                    <p class="text-muted">We sent a one-time code to <strong><?= e($challenge['email'] ?? '') ?></strong>.</p>
                <?php else: ?>
                    <p class="text-muted">Start a new one-time code for your Google-authenticated account.</p>
                <?php endif; ?>

                <?php if (isset($_SESSION['otp_debug_code']) && env('EMAIL_PROVIDER', 'log') === 'log'): ?>
                    <div class="alert alert-info small mb-3">Debug OTP: <strong><?= e($_SESSION['otp_debug_code']) ?></strong></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <?php if ($otpSent): ?>
                        <input type="hidden" name="action" value="verify_otp">
                        <label class="form-label" for="otp">One-time password</label>
                        <input class="form-control mb-3" id="otp" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code">
                        <button class="btn btn-primary w-100 mb-2">Verify code</button>
                        <?php if (canResendOtp()): ?>
                            <button type="submit" class="btn btn-outline-secondary w-100" name="action" value="begin_otp">Resend code</button>
                        <?php else: ?>
                            <p class="small text-muted mt-2 mb-0">You can request a new code after the cooldown expires.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <input type="hidden" name="action" value="begin_otp">
                        <button class="btn btn-primary w-100">Send verification code</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
