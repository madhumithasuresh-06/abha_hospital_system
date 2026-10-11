<?php
require 'C:\xampp\htdocs\abha_hospital_system\includes\env.php';
loadEnvironment('C:\xampp\htdocs\abha_hospital_system');

$xmlPath = 'C:\xampp\htdocs\abha_hospital_system\storage\temp\actual_offline_ekyc.xml';
$xmlContent = file_get_contents($xmlPath);
if ($xmlContent === false) {
    echo "XML_READ_FAILED\n";
    exit(1);
}

$certPath = env('OFFLINE_EKYC_PUBLIC_CERT_PATH', __DIR__ . '/../config/uidai/uidai_offline_publickey_2026.cer');
$certPathReal = realpath($certPath);
$certExists = is_file($certPath) || ($certPathReal !== false);
$certReadable = false;
$certPem = null;
if ($certExists) {
    $certPem = file_get_contents($certPathReal !== false ? $certPathReal : $certPath);
    $certReadable = ($certPem !== false && strlen($certPem) > 0);
}

echo "CERT_PATH_USED=" . $certPath . PHP_EOL;
echo "CERT_PATH_REAL=" . ($certPathReal !== false ? $certPathReal : 'unresolved') . PHP_EOL;
echo "CERT_EXISTS=" . ($certExists ? 'yes' : 'no') . PHP_EOL;
echo "CERT_READABLE=" . ($certReadable ? 'yes' : 'no') . PHP_EOL;

if ($certReadable) {
    $x509 = openssl_x509_read($certPem);
    echo "CERT_VALID=" . ($x509 !== false ? 'yes' : 'no') . PHP_EOL;
    $publicKey = openssl_pkey_get_public($certPem);
    echo "PUBLIC_KEY_LOADED=" . ($publicKey !== false ? 'yes' : 'no') . PHP_EOL;
    if ($publicKey !== false) {
        $details = openssl_pkey_get_details($publicKey);
        echo "PUBLIC_KEY_TYPE=" . ($details['type'] ?? 'unknown') . PHP_EOL;
    }
}

$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
libxml_clear_errors();
$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
$errors = libxml_get_errors();
if ($errors) {
    echo "XML_PARSE_ERRORS=" . count($errors) . PHP_EOL;
    foreach ($errors as $err) {
        echo "XML_PARSE_ERR=" . trim($err->message) . PHP_EOL;
    }
}

$xpath = new DOMXPath($dom);
$signatureNodes = $xpath->query('//*[local-name()="Signature"]');
$signatureFound = ($signatureNodes !== false && $signatureNodes->length > 0);
echo "XML_SIGNATURE_FOUND=" . ($signatureFound ? 'yes' : 'no') . PHP_EOL;

if ($signatureFound) {
    $signatureNode = $signatureNodes->item(0);
    $signedInfoNode = $xpath->query('./*[local-name()="SignedInfo"]', $signatureNode)->item(0);
    $signedInfoNodeXml = $dom->saveXML($signedInfoNode);
    $signatureValueNode = $xpath->query('./*[local-name()="SignatureValue"]', $signatureNode)->item(0);
    $signatureValue = trim((string) ($signatureValueNode ? $signatureValueNode->textContent : ''));
    $signatureDecoded = base64_decode($signatureValue, true);
    echo "SIGNATURE_VALUE_DECODED=" . ($signatureDecoded !== false ? 'yes' : 'no') . PHP_EOL;

    $signatureMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="SignatureMethod"]', $signatureNode)->item(0);
    $canonicalizationMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="CanonicalizationMethod"]', $signatureNode)->item(0);
    $referenceNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]', $signatureNode)->item(0);
    $digestMethodNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="DigestMethod"]', $signatureNode)->item(0);
    $digestValueNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="DigestValue"]', $signatureNode)->item(0);
    $transformsNode = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="Transforms"]', $signatureNode)->item(0);
    $transformNodes = $xpath->query('./*[local-name()="Transform"]', $transformsNode ?: $signatureNode);

    echo "SIGNATURE_METHOD=" . ($signatureMethodNode ? $signatureMethodNode->getAttribute('Algorithm') : 'not_found') . PHP_EOL;
    echo "CANONICALIZATION_METHOD=" . ($canonicalizationMethodNode ? $canonicalizationMethodNode->getAttribute('Algorithm') : 'not_found') . PHP_EOL;
    echo "REFERENCE_URI=" . ($referenceNode ? $referenceNode->getAttribute('URI') : 'not_found') . PHP_EOL;
    echo "DIGEST_METHOD=" . ($digestMethodNode ? $digestMethodNode->getAttribute('Algorithm') : 'not_found') . PHP_EOL;
    echo "DIGEST_VALUE_PRESENT=" . ($digestValueNode ? 'yes' : 'no') . PHP_EOL;

    $signedInfoXmlNormalized = preg_replace('/>\s+</', '><', trim((string) $signedInfoNodeXml));
    echo "SIGNEDINFO_CANONICALIZED_PREVIEW=" . substr($signedInfoXmlNormalized, 0, 400) . PHP_EOL;

    $referenceUri = $referenceNode ? $referenceNode->getAttribute('URI') : '';
    $referenceTarget = null;
    if ($referenceUri === '') {
        $referenceTarget = $dom->documentElement;
    } elseif ($referenceUri === '#') {
        $referenceTarget = $dom->documentElement;
    } elseif (preg_match('/^#/', $referenceUri)) {
        $id = substr($referenceUri, 1);
        $referenceTarget = $xpath->query("//*[@*[local-name()='Id' and .='{$id}']]")->item(0) ?: $xpath->query("//*[@Id='{$id}']")->item(0);
    }

    if ($referenceTarget instanceof DOMNode) {
        $referenceXml = $dom->saveXML($referenceTarget);
        $referenceXml = trim((string) $referenceXml);
        echo "REFERENCE_TARGET_XML_PREVIEW=" . substr($referenceXml, 0, 300) . PHP_EOL;

        $transformContent = $referenceXml;
        $transforms = $xpath->query('./*[local-name()="SignedInfo"]/*[local-name()="Reference"]/*[local-name()="Transforms"]/*[local-name()="Transform"]', $signatureNode);
        foreach ($transforms as $transform) {
            $alg = $transform->getAttribute('Algorithm');
            if ($alg === 'http://www.w3.org/2001/10/xml-exc-c14n#') {
                $transformContent = $transformContent; // no-op for diagnosis; actual canonicalization is not applied here
            }
        }

        $digestAlgorithm = $digestMethodNode ? $digestMethodNode->getAttribute('Algorithm') : '';
        echo "DIGEST_ALGORYTHM=" . $digestAlgorithm . PHP_EOL;
        $digestValue = trim((string) ($digestValueNode ? $digestValueNode->textContent : ''));
        $computedDigest = null;
        if ($digestAlgorithm === 'http://www.w3.org/2001/04/xmlenc#sha256') {
            $computedDigest = base64_encode(hash('sha256', $referenceXml, true));
        } elseif ($digestAlgorithm === 'http://www.w3.org/2001/04/xmlenc#sha1') {
            $computedDigest = base64_encode(hash('sha1', $referenceXml, true));
        }
        echo "COMPUTED_DIGEST=" . ($computedDigest ?? 'not_computed') . PHP_EOL;
        echo "EXPECTED_DIGEST=" . ($digestValue ?: 'not_found') . PHP_EOL;
        echo "DIGEST_VALID=" . (($computedDigest !== null && $computedDigest === $digestValue) ? 'yes' : 'no') . PHP_EOL;
    }

    if ($publicKey !== false && $signatureDecoded !== false) {
        $opensslVerify = openssl_verify($signedInfoXmlNormalized, $signatureDecoded, $publicKey, OPENSSL_ALGO_SHA256);
        echo "OPENSSL_VERIFY_RESULT=" . var_export($opensslVerify, true) . PHP_EOL;
        while (($err = openssl_error_string()) !== false) {
            echo "OPENSSL_ERROR=" . trim($err) . PHP_EOL;
        }
    }
}
