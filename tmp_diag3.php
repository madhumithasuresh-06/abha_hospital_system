<?php
$xmlPath = 'C:\xampp\htdocs\abha_hospital_system\storage\temp\actual_offline_ekyc.xml';
$xmlText = file_get_contents($xmlPath);
if ($xmlText === false) {
    echo "XML_READ_FAILED\n";
    exit(1);
}

$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$loaded = $dom->loadXML($xmlText, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
if (!$loaded) {
    echo "XML_LOAD_FAILED\n";
    foreach (libxml_get_errors() as $err) {
        echo $err->message . PHP_EOL;
    }
    exit(1);
}

$xpath = new DOMXPath($dom);
$signatureNodes = $xpath->query('//*[local-name()="Signature"]');
if ($signatureNodes === false || $signatureNodes->length === 0) {
    echo "XML_SIGNATURE_NOT_FOUND\n";
    exit(1);
}
$signatureNode = $signatureNodes->item(0);

$embeddedCertNode = $xpath->query('./*[local-name()="KeyInfo"]/*[local-name()="X509Data"]/*[local-name()="X509Certificate"]', $signatureNode)->item(0);
if ($embeddedCertNode) {
    $embeddedCertBase64 = preg_replace('/\s+/', '', (string) $embeddedCertNode->textContent);
    $embeddedCertPem = "-----BEGIN CERTIFICATE-----\n";
    $embeddedCertPem .= implode("\n", str_split($embeddedCertBase64, 64));
    $embeddedCertPem .= "\n-----END CERTIFICATE-----\n";
    $embeddedCert = openssl_x509_read($embeddedCertPem);
    $embeddedCertInfo = openssl_x509_parse($embeddedCert);
    $embeddedFinger = openssl_x509_fingerprint($embeddedCert, 'sha256');
    echo "EMBEDDED_CERT_PRESENT=YES\n";
    echo "EMBEDDED_CERT_SUBJECT=" . ($embeddedCertInfo['subject']['CN'] ?? 'unknown') . "\n";
    echo "EMBEDDED_CERT_ISSUER=" . ($embeddedCertInfo['issuer']['CN'] ?? 'unknown') . "\n";
    echo "EMBEDDED_CERT_SERIAL=" . strtoupper($embeddedCertInfo['serialNumberHex'] ?? '') . "\n";
    echo "EMBEDDED_CERT_FP=" . $embeddedFinger . "\n";
} else {
    echo "EMBEDDED_CERT_PRESENT=NO\n";
}

$configCertPath = 'C:\xampp\htdocs\abha_hospital_system\config\uidai\uidai_offline_publickey_2026.cer';
$configCertText = file_get_contents($configCertPath);
$configCertInfo = openssl_x509_parse(openssl_x509_read($configCertText));
$configFinger = openssl_x509_fingerprint(openssl_x509_read($configCertText), 'sha256');

echo "CONFIGURED_CERT_FP=" . $configFinger . "\n";
echo "CONFIGURED_CERT_SUBJECT=" . ($configCertInfo['subject']['CN'] ?? 'unknown') . "\n";
echo "CONFIGURED_CERT_ISSUER=" . ($configCertInfo['issuer']['CN'] ?? 'unknown') . "\n";
echo "CONFIGURED_CERT_SERIAL=" . strtoupper($configCertInfo['serialNumberHex'] ?? '') . "\n";

if ($embeddedCertNode) {
    echo "CERTIFICATES_MATCH=" . (($embeddedFinger ?? '') === $configFinger ? 'YES' : 'NO') . "\n";
}

$signatureMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="SignatureMethod"]', $signatureNode)->item(0);
$canonicalizationMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="CanonicalizationMethod"]', $signatureNode)->item(0);
$referenceNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]', $signatureNode)->item(0);
$digestMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="DigestMethod"]', $signatureNode)->item(0);
$digestValueNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="DigestValue"]', $signatureNode)->item(0);

$signatureMethod = $signatureMethodNode ? $signatureMethodNode->getAttribute('Algorithm') : 'not_found';
$canonicalizationMethod = $canonicalizationMethodNode ? $canonicalizationMethodNode->getAttribute('Algorithm') : 'not_found';
$referenceUri = $referenceNode ? $referenceNode->getAttribute('URI') : 'not_found';
$digestMethod = $digestMethodNode ? $digestMethodNode->getAttribute('Algorithm') : 'not_found';
$digestValue = $digestValueNode ? trim((string) $digestValueNode->textContent) : 'not_found';

echo "SIGNATURE_METHOD=" . $signatureMethod . "\n";
echo "CANONICALIZATION_METHOD=" . $canonicalizationMethod . "\n";
echo "REFERENCE_URI=" . $referenceUri . "\n";
echo "DIGEST_METHOD=" . $digestMethod . "\n";

// Compute digest over the exact signed target with enveloped-signature transform (remove Signature node) and canonicalize using XML c14n 1.0.
$docClone = new DOMDocument('1.0', 'UTF-8');
$docClone->loadXML($xmlText, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
$signatureToRemove = $docClone->getElementsByTagName('Signature')->item(0);
if ($signatureToRemove && $signatureToRemove->parentNode) {
    $signatureToRemove->parentNode->removeChild($signatureToRemove);
}
$canonicalDoc = $docClone->documentElement ? $docClone->documentElement->C14N(false, false) : '';
$computedDigest = base64_encode(hash('sha256', $canonicalDoc, true));
echo "COMPUTED_DIGEST=" . $computedDigest . "\n";
echo "EXPECTED_DIGEST=" . $digestValue . "\n";
echo "DIGEST_VALID=" . ($computedDigest === $digestValue ? 'PASS' : 'FAIL') . "\n";

// Verify signature with signature method interpreted as RSA-SHA1, using the configured cert.
$signatureValueNode = $xpath->query('./*[local-name()="SignatureValue"]', $signatureNode)->item(0);
$sigBytes = base64_decode(trim((string) ($signatureValueNode ? $signatureValueNode->textContent : '')), true);
if ($sigBytes === false) {
    echo "SIGNATURE_VALUE_DECODED=NO\n";
    exit(0);
}

echo "SIGNATURE_VALUE_DECODED=YES\n";
$signedInfoNode = $xpath->query('./*[local-name()="SignedInfo"]', $signatureNode)->item(0);
$signedInfoXmlRaw = $dom->saveXML($signedInfoNode);
$signedInfoXml = preg_replace('/>\s+</', '><', trim((string) $signedInfoXmlRaw));
$publicKey = openssl_pkey_get_public($configCertText);
if ($publicKey === false) {
    echo "PUBLIC_KEY_LOADED=NO\n";
    exit(0);
}
echo "PUBLIC_KEY_LOADED=YES\n";
$verifyResult = openssl_verify($signedInfoXml, $sigBytes, $publicKey, OPENSSL_ALGO_SHA1);
echo "RSA_SHA1_VERIFY_RESULT=" . ($verifyResult === 1 ? 'PASS' : 'FAIL') . "\n";
$opensslErrors = [];
while (($err = openssl_error_string()) !== false) {
    $opensslErrors[] = $err;
}
if (!empty($opensslErrors)) {
    echo "OPENSSL_ERROR=" . implode(' | ', $opensslErrors) . "\n";
}
