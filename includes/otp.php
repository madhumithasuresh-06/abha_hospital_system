<?php

function generateSecureOtp(int $length = 6): string
{
    $digits = '0123456789';
    $otp = '';

    for ($index = 0; $index < $length; $index++) {
        $otp .= $digits[random_int(0, strlen($digits) - 1)];
    }

    return $otp;
}

function maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $localPart = $parts[0];
    $domain = $parts[1];
    $visibleLength = max(1, min(2, strlen($localPart)));

    return substr($localPart, 0, $visibleLength) . str_repeat('*', max(1, strlen($localPart) - $visibleLength)) . '@' . $domain;
}

function buildOtpEmailBody(string $otp, int $expiryMinutes): string
{
    return "ABHA Patient Registration\n\n"
        . "Your one-time password (OTP) is: {$otp}\n\n"
        . "This OTP expires in {$expiryMinutes} minutes.\n"
        . "Do not share this OTP with anyone.\n\n"
        . "If you did not request this code, please ignore this email.";
}

function readSmtpResponse($socket): array
{
    $response = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^([0-9]{3})([- ])/', $line, $matches) === 1) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    return ['code' => $code, 'message' => trim($response)];
}

function sendSmtpEmail(string $to, string $from, string $subject, string $body): bool
{
    $host = trim((string) env('SMTP_HOST', ''));
    $port = (int) env('SMTP_PORT', 587);
    $username = trim((string) env('SMTP_USERNAME', ''));
    $password = (string) env('SMTP_PASSWORD', '');
    $encryption = strtolower((string) env('SMTP_ENCRYPTION', 'tls'));

    if ($host === '' || $from === '' || $username === '' || $password === '') {
        error_log('SMTP email is not configured. Please set SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD, and EMAIL_FROM.');
        return false;
    }

    $socketScheme = $encryption === 'ssl' ? 'ssl://' : '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($socketScheme . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

    if (!$socket) {
        error_log('Could not connect to SMTP host: ' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 30);

    $initialResponse = readSmtpResponse($socket);
    if ($initialResponse['code'] !== 220) {
        fclose($socket);
        error_log('SMTP handshake failed: ' . $initialResponse['message']);
        return false;
    }

    $commands = [
        'EHLO localhost',
    ];

    foreach ($commands as $command) {
        if (!@fwrite($socket, $command . "\r\n")) {
            fclose($socket);
            error_log('SMTP command failed: ' . $command);
            return false;
        }

        $response = readSmtpResponse($socket);
        if ($response['code'] !== 250) {
            fclose($socket);
            error_log('SMTP EHLO failed: ' . $response['message']);
            return false;
        }
    }

    if ($encryption === 'tls') {
        if (!@fwrite($socket, "STARTTLS\r\n")) {
            fclose($socket);
            error_log('SMTP STARTTLS command failed.');
            return false;
        }

        $tlsResponse = readSmtpResponse($socket);
        if ($tlsResponse['code'] !== 220) {
            fclose($socket);
            error_log('SMTP STARTTLS failed: ' . $tlsResponse['message']);
            return false;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            error_log('SMTP TLS handshake failed.');
            return false;
        }

        if (!@fwrite($socket, "EHLO localhost\r\n")) {
            fclose($socket);
            error_log('SMTP EHLO after STARTTLS failed.');
            return false;
        }

        $ehloAfterTls = readSmtpResponse($socket);
        if ($ehloAfterTls['code'] !== 250) {
            fclose($socket);
            error_log('SMTP EHLO after STARTTLS failed: ' . $ehloAfterTls['message']);
            return false;
        }
    }

    $authSteps = [
        'AUTH LOGIN',
        base64_encode($username),
        base64_encode($password),
    ];

    foreach ($authSteps as $command) {
        if (!@fwrite($socket, $command . "\r\n")) {
            fclose($socket);
            error_log('SMTP authentication command failed.');
            return false;
        }

        $response = readSmtpResponse($socket);
        if ($command === 'AUTH LOGIN' && $response['code'] !== 334) {
            fclose($socket);
            error_log('SMTP AUTH LOGIN failed: ' . $response['message']);
            return false;
        }

        if ($command === base64_encode($username) && $response['code'] !== 334) {
            fclose($socket);
            error_log('SMTP username step failed: ' . $response['message']);
            return false;
        }

        if ($command === base64_encode($password) && $response['code'] !== 235) {
            fclose($socket);
            error_log('SMTP password step failed: ' . $response['message']);
            return false;
        }
    }

    $mailFrom = 'MAIL FROM:<' . $from . '>';
    if (!@fwrite($socket, $mailFrom . "\r\n")) {
        fclose($socket);
        error_log('SMTP MAIL FROM failed.');
        return false;
    }

    $mailFromResponse = readSmtpResponse($socket);
    if ($mailFromResponse['code'] !== 250) {
        fclose($socket);
        error_log('SMTP MAIL FROM failed: ' . $mailFromResponse['message']);
        return false;
    }

    $rcptCommand = 'RCPT TO:<' . $to . '>';
    if (!@fwrite($socket, $rcptCommand . "\r\n")) {
        fclose($socket);
        error_log('SMTP RCPT TO failed.');
        return false;
    }

    $rcptResponse = readSmtpResponse($socket);
    if ($rcptResponse['code'] !== 250 && $rcptResponse['code'] !== 251) {
        fclose($socket);
        error_log('SMTP RCPT TO failed: ' . $rcptResponse['message']);
        return false;
    }

    if (!@fwrite($socket, "DATA\r\n")) {
        fclose($socket);
        error_log('SMTP DATA command failed.');
        return false;
    }

    $dataResponse = readSmtpResponse($socket);
    if ($dataResponse['code'] !== 354) {
        fclose($socket);
        error_log('SMTP DATA failed: ' . $dataResponse['message']);
        return false;
    }

    $headers = [
        'From: ' . $from,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: ABHA Patient Registration',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";

    if (!@fwrite($socket, $message)) {
        fclose($socket);
        error_log('SMTP message write failed.');
        return false;
    }

    $messageResponse = readSmtpResponse($socket);
    if ($messageResponse['code'] !== 250) {
        fclose($socket);
        error_log('SMTP message submit failed: ' . $messageResponse['message']);
        return false;
    }

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

function sendOtpEmail(string $email, string $otp, int $expiryMinutes): bool
{
    $provider = strtolower((string) env('EMAIL_PROVIDER', 'log'));
    $from = trim((string) env('EMAIL_FROM', 'no-reply@hospital.local'));
    $subject = 'ABHA Patient Registration - Verification Code';
    $body = buildOtpEmailBody($otp, $expiryMinutes);

    if ($provider === 'log') {
        error_log('OTP email queued for ' . $email . ' via log provider.');
        $_SESSION['otp_debug_code'] = $otp;
        return true;
    }

    if ($provider === 'mail') {
        $headers = [
            'From: ' . $from,
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return mail($email, $subject, $body, implode("\r\n", $headers));
    }

    if ($provider === 'smtp') {
        return sendSmtpEmail($email, $from, $subject, $body);
    }

    return false;
}

function beginOtpChallenge(int $userId, string $email, string $role, string $purpose = 'general'): array
{
    $expiryMinutes = (int) env('OTP_EXPIRY_MINUTES', 5);
    $resendCooldown = (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60);
    $maxAttempts = (int) env('OTP_MAX_ATTEMPTS', 5);
    $otp = generateSecureOtp();

    $_SESSION['otp_challenge'] = [
        'user_id' => $userId,
        'email' => $email,
        'role' => $role,
        'purpose' => $purpose,
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + ($expiryMinutes * 60),
        'otp_attempts_left' => $maxAttempts,
        'resend_cooldown_until' => time() + $resendCooldown,
        'sent_at' => time(),
    ];

    $sent = sendOtpEmail($email, $otp, $expiryMinutes);

    return [
        'success' => $sent,
        'email' => $email,
        'masked_email' => maskEmail($email),
    ];
}

function getOtpChallenge(): ?array
{
    $challenge = $_SESSION['otp_challenge'] ?? null;

    if (!is_array($challenge)) {
        return null;
    }

    if (($challenge['expires_at'] ?? 0) <= time()) {
        clearOtpChallenge();
        return null;
    }

    return $challenge;
}

function verifyOtpChallenge(string $submittedOtp): bool
{
    $challenge = getOtpChallenge();

    if (!$challenge) {
        return false;
    }

    if (password_verify($submittedOtp, $challenge['otp_hash'] ?? '')) {
        clearOtpChallenge();
        return true;
    }

    $remainingAttempts = max(0, (int) ($challenge['otp_attempts_left'] ?? 0) - 1);
    $challenge['otp_attempts_left'] = $remainingAttempts;
    $_SESSION['otp_challenge'] = $challenge;

    if ($remainingAttempts <= 0) {
        clearOtpChallenge();
    }

    return false;
}

function clearOtpChallenge(): void
{
    unset($_SESSION['otp_challenge'], $_SESSION['otp_debug_code']);
}

function canResendOtp(): bool
{
    $challenge = getOtpChallenge();

    if (!$challenge) {
        return true;
    }

    return time() >= (int) ($challenge['resend_cooldown_until'] ?? 0);
}
