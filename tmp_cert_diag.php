<?php
require 'C:\xampp\htdocs\abha_hospital_system\includes\env.php';
loadEnvironment('C:\xampp\htdocs\abha_hospital_system');
$cert = env('OFFLINE_EKYC_PUBLIC_CERT_PATH', __DIR__ . '/../config/uidai/uidai_offline_publickey_2026.cer');
echo "CERT_PATH=" . $cert . PHP_EOL;
echo "CERT_EXISTS=" . (is_file($cert) ? 'yes' : 'no') . PHP_EOL;
if (is_file($cert)) {
    $data = file_get_contents($cert);
    echo "CERT_READABLE=" . (($data !== false && strlen($data) > 0) ? 'yes' : 'no') . PHP_EOL;
}
