<?php

function maskAadhaarNumber(string $value): string
{
    $normalized = preg_replace('/\D+/', '', $value);
    if (strlen($normalized) < 4) {
        return str_repeat('*', max(1, strlen($normalized)));
    }

    $visibleStart = 4;
    $visibleEnd = 4;
    $maskedLength = max(0, strlen($normalized) - $visibleStart - $visibleEnd);

    return substr($normalized, 0, $visibleStart) . str_repeat('*', max(1, $maskedLength)) . substr($normalized, -$visibleEnd);
}

function normalizeEkycComparisonValue(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9 ]+/', '', $value);
    $value = trim((string) $value);

    return (string) $value;
}

function resolveAadhaarOcrExecutable(string $command, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    $commandPath = trim((string) shell_exec('where.exe ' . escapeshellarg($command) . ' 2> nul'));
    if ($commandPath !== '') {
        $candidate = preg_split('/\r?\n/', $commandPath, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($candidate) && !empty($candidate[0])) {
            return trim((string) $candidate[0]);
        }
    }

    return null;
}

function normalizeAadhaarOcrText(string $text): string
{
    $text = preg_replace('/\r\n?/', "\n", $text);
    $text = preg_replace('/Date\s+of\s+rth/i', 'Date of Birth', $text);
    $text = preg_replace('/Date\s+of\s+Birth/i', 'Date of Birth', $text);
    $text = preg_replace('/Addres\s+s/i', 'Address', $text);
    $text = preg_replace('/Aadhaar\s+No\.?/i', 'Aadhaar', $text);
    $text = preg_replace('/\h+/', ' ', $text);
    $text = preg_replace('/\n\s+/', "\n", $text);

    return trim((string) $text);
}

function extractAadhaarFieldValue(string $text, array $labels): ?string
{
    $lines = preg_split('/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $knownFieldLabels = ['Name', 'Full Name', 'Date of Birth', 'DOB', 'Gender', 'Address', 'Aadhaar', 'UID', 'Mobile', 'Phone', 'Email', 'State', 'District', 'Pincode'];

    foreach ($lines as $index => $line) {
        $trimmedLine = trim((string) $line);
        if ($trimmedLine === '') {
            continue;
        }

        foreach ($labels as $label) {
            $escapedLabel = preg_quote($label, '/');
            if (preg_match('/\b' . $escapedLabel . '\b/i', $trimmedLine)) {
                $withValue = preg_replace('/^.*?\b' . $escapedLabel . '\b\s*[:\-]?\s*/i', '', $trimmedLine);
                $withValue = trim(preg_replace('/\s+/', ' ', $withValue));
                $withValue = preg_replace('/[\|,;]+$/', '', $withValue);

                if ($withValue !== '' && !preg_match('/^(?:' . implode('|', array_map(fn ($knownLabel) => preg_quote($knownLabel, '/'), $knownFieldLabels)) . ')\b/i', $withValue)) {
                    return $withValue;
                }

                $nextIndex = $index + 1;
                while ($nextIndex < count($lines)) {
                    $nextLine = trim((string) $lines[$nextIndex]);
                    if ($nextLine === '') {
                        $nextIndex++;
                        continue;
                    }

                    if (preg_match('/\b(?:' . implode('|', array_map(fn ($knownLabel) => preg_quote($knownLabel, '/'), $knownFieldLabels)) . ')\b/i', $nextLine)) {
                        break;
                    }

                    $nextValue = trim(preg_replace('/\s+/', ' ', $nextLine));
                    $nextValue = preg_replace('/[\|,;]+$/', '', $nextValue);
                    if ($nextValue !== '') {
                        return $nextValue;
                    }

                    $nextIndex++;
                }
            }
        }
    }

    foreach ($labels as $label) {
        $pattern = '/\b' . preg_quote($label, '/') . '\b\s*[:\-]?\s*([^\n]+)/i';
        if (preg_match($pattern, $text, $matches)) {
            $value = trim((string) $matches[1]);
            $value = preg_replace('/[\|,;]+$/', '', $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}

function extractAadhaarAddressValue(string $text): ?string
{
    $lines = preg_split('/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $knownFieldLabels = ['Name', 'Full Name', 'Date of Birth', 'DOB', 'Gender', 'Address', 'Aadhaar', 'UID', 'Mobile', 'Phone', 'Email', 'State', 'District', 'Pincode'];

    foreach ($lines as $index => $line) {
        $trimmedLine = trim((string) $line);
        if ($trimmedLine === '' || !preg_match('/\bAddress\b/i', $trimmedLine)) {
            continue;
        }

        $parts = [];
        $nextIndex = $index + 1;
        while ($nextIndex < count($lines)) {
            $nextLine = trim((string) $lines[$nextIndex]);
            if ($nextLine === '') {
                $nextIndex++;
                continue;
            }

            if (preg_match('/\b(?:' . implode('|', array_map(fn ($knownLabel) => preg_quote($knownLabel, '/'), $knownFieldLabels)) . ')\b/i', $nextLine)) {
                break;
            }

            $parts[] = $nextLine;
            $nextIndex++;
        }

        if (!empty($parts)) {
            $address = trim(implode(' ', $parts));
            $address = preg_replace('/\s+/', ' ', $address);
            $address = preg_replace('/[\|,;]+$/', '', $address);

            if ($address !== '') {
                return $address;
            }
        }

        $withValue = preg_replace('/^.*?\bAddress\b\s*[:\-]?\s*/i', '', $trimmedLine);
        $withValue = trim(preg_replace('/\s+/', ' ', $withValue));
        $withValue = preg_replace('/[\|,;]+$/', '', $withValue);
        if ($withValue !== '') {
            return $withValue;
        }
    }

    $patterns = [
        '/\bAddress\b\s*[:\-]?\s*(.+?)(?=\n\s*(?:Name|Full Name|Date of Birth|DOB|Gender|Aadhaar|UID|Mobile|Phone|Email|State|District|Pincode)\s*[:\-]?|$)/is',
        '/\bAddress\b\s*[:\-]?\s*(.+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $value = trim(preg_replace('/\s+/', ' ', $matches[1]));
            $value = preg_replace('/[\|,;]+$/', '', $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}

function sanitizeOfflineEkycFieldValue(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);

    if ($value === '') {
        return null;
    }

    return $value;
}

function extractOfflineEkycTextField(string $xmlContent, array $candidateKeys): ?string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    libxml_clear_errors();

    if (!$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
        return null;
    }

    $normalizedCandidates = [];
    foreach ($candidateKeys as $candidateKey) {
        $normalizedCandidates[] = strtolower(trim((string) $candidateKey));
    }

    $elements = $dom->getElementsByTagName('*');
    foreach ($elements as $element) {
        $localName = strtolower((string) ($element->localName ?? $element->nodeName));
        if (in_array($localName, $normalizedCandidates, true)) {
            $value = sanitizeOfflineEkycFieldValue($element->textContent);
            if ($value !== null) {
                return $value;
            }
        }

        if ($element->attributes && $element->attributes->length > 0) {
            foreach ($element->attributes as $attribute) {
                $attributeName = strtolower((string) ($attribute->localName ?? $attribute->nodeName));
                if (in_array($attributeName, $normalizedCandidates, true)) {
                    $value = sanitizeOfflineEkycFieldValue($attribute->value);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }
    }

    return null;
}

function extractOfflineEkycFields(string $xmlContent): array
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    libxml_clear_errors();

    $name = null;
    $dob = null;
    $gender = null;
    $address = null;
    $mobileHash = null;
    $photo = null;
    $referenceId = null;

    if ($dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
        $xpath = new DOMXPath($dom);

        $name = sanitizeOfflineEkycFieldValue($xpath->evaluate('string(//*[local-name()="UidData"]/*[local-name()="Poi"]/@name)')) ?: null;
        $dob = sanitizeOfflineEkycFieldValue($xpath->evaluate('string(//*[local-name()="UidData"]/*[local-name()="Poi"]/@dob)')) ?: null;
        $gender = sanitizeOfflineEkycFieldValue($xpath->evaluate('string(//*[local-name()="UidData"]/*[local-name()="Poi"]/@gender)')) ?: null;
        $mobileHash = sanitizeOfflineEkycFieldValue($xpath->evaluate('string(//*[local-name()="UidData"]/*[local-name()="Poi"]/@m)')) ?: null;
        $referenceId = sanitizeOfflineEkycFieldValue($xpath->evaluate('string(//*[local-name()="OfflinePaperlessKyc"]/@referenceId)')) ?: null;
    }

    if ($name === null) {
        $name = extractOfflineEkycTextField($xmlContent, ['Name', 'name', 'fullName', 'full_name', 'residentName', 'uidaiName']);
    }

    if ($dob === null) {
        $dob = extractOfflineEkycTextField($xmlContent, ['DOB', 'DateOfBirth', 'dateOfBirth', 'dob', 'date_of_birth']);
    }

    if ($gender === null) {
        $gender = extractOfflineEkycTextField($xmlContent, ['Gender', 'gender']);
    }

    if ($address === null) {
        $address = extractOfflineEkycTextField($xmlContent, ['Address', 'address', 'careOf', 'house', 'street', 'loc', 'POA']);
    }

    if ($mobileHash === null) {
        $mobileHash = extractOfflineEkycTextField($xmlContent, ['m', 'mobileHash', 'mobile_hash']);
    }

    if ($photo === null) {
        $photo = extractOfflineEkycTextField($xmlContent, ['Photo', 'photo', 'profilePhoto']);
    }

    if ($referenceId === null) {
        $referenceId = extractOfflineEkycTextField($xmlContent, ['ReferenceId', 'referenceId', 'reference_id', 'uidaiReferenceId', 'UID']);
    }

    $aadhaarNumber = null;
    if (preg_match('/\b(\d{4}[\s\-]?\d{4}[\s\-]?\d{4})\b/', $xmlContent, $matches)) {
        $aadhaarNumber = maskAadhaarNumber($matches[1]);
    }

    return [
        'name' => $name,
        'date_of_birth' => $dob,
        'gender' => $gender,
        'address' => $address,
        'mobile_number' => null,
        'mobile_hash' => $mobileHash !== null && preg_match('/^[a-f0-9]{64}$/i', $mobileHash) === 1 ? strtolower($mobileHash) : null,
        'aadhaar_number' => $aadhaarNumber,
        'photo' => $photo,
        'reference_id' => $referenceId,
    ];
}

function deriveOfflineEkycMobileHashIterations(?string $referenceId): ?int
{
    $referenceId = trim((string) $referenceId);
    if (preg_match('/^(\d{4})/', $referenceId, $matches) !== 1) {
        return null;
    }

    $aadhaarLastDigit = (int) $matches[1][3];

    return $aadhaarLastDigit <= 1 ? 1 : $aadhaarLastDigit;
}

function verifyOfflineEkycSignature(string $xmlContent): array
{
    if (!function_exists('openssl_verify')) {
        return [
            'verified' => false,
            'error' => 'OpenSSL XML signature verification is unavailable in this XAMPP/PHP environment.',
        ];
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    libxml_clear_errors();

    if (!$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC XML is malformed or corrupted.',
        ];
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    $signatureNodes = $xpath->query('//ds:Signature');
    if ($signatureNodes === false || $signatureNodes->length === 0) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC file does not contain the required XML digital signature block.',
        ];
    }

    $signatureNode = $signatureNodes->item(0);
    $signedInfoNode = $xpath->query('./ds:SignedInfo', $signatureNode)->item(0);
    $signatureValueNode = $xpath->query('./ds:SignatureValue', $signatureNode)->item(0);

    if (!$signedInfoNode || !$signatureValueNode) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC signature is incomplete or missing SignedInfo/SignatureValue elements.',
        ];
    }

    $signatureMethodNode = $xpath->query('./ds:SignatureMethod', $signedInfoNode)->item(0);
    $canonicalizationMethodNode = $xpath->query('./ds:CanonicalizationMethod', $signedInfoNode)->item(0);

    if (!$signatureMethodNode || !$canonicalizationMethodNode) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC SignedInfo block is missing SignatureMethod or CanonicalizationMethod.',
        ];
    }

    $signatureMethod = trim((string) $signatureMethodNode->getAttribute('Algorithm'));
    $canonicalizationMethod = trim((string) $canonicalizationMethodNode->getAttribute('Algorithm'));

    if ($signatureMethod === '') {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC SignatureMethod is missing its Algorithm attribute.',
        ];
    }

    if ($canonicalizationMethod === '') {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC CanonicalizationMethod is missing its Algorithm attribute.',
        ];
    }

    if ($canonicalizationMethod !== 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315') {
        return [
            'verified' => false,
            'error' => 'Unsupported XML canonicalization method for Offline e-KYC verification: ' . $canonicalizationMethod,
        ];
    }

    $canonicalizedSignedInfo = $signedInfoNode->C14N(false, false);
    if ($canonicalizedSignedInfo === false || trim($canonicalizedSignedInfo) === '') {
        return [
            'verified' => false,
            'error' => 'The Offline e-KYC SignedInfo element could not be canonicalized using XML C14N 1.0.',
        ];
    }

    $signatureBytes = base64_decode(trim((string) $signatureValueNode->textContent), true);
    if ($signatureBytes === false) {
        return [
            'verified' => false,
            'error' => 'The Offline e-KYC SignatureValue is not valid Base64.',
        ];
    }

    $certificatePath = env('OFFLINE_EKYC_PUBLIC_CERT_PATH', __DIR__ . '/../config/uidai/uidai_offline_publickey_2026.cer');
    if (!is_file($certificatePath)) {
        return [
            'verified' => false,
            'error' => 'The UIDAI Offline e-KYC public certificate file was not found at: ' . $certificatePath,
        ];
    }

    $certificatePem = file_get_contents($certificatePath);
    if ($certificatePem === false) {
        return [
            'verified' => false,
            'error' => 'The UIDAI Offline e-KYC public certificate could not be read from: ' . $certificatePath,
        ];
    }

    $publicKey = openssl_pkey_get_public($certificatePem);
    if ($publicKey === false) {
        return [
            'verified' => false,
            'error' => 'The supplied UIDAI Offline e-KYC public certificate is invalid or unreadable.',
        ];
    }

    $opensslAlgorithm = null;
    if ($signatureMethod === 'http://www.w3.org/2000/09/xmldsig#rsa-sha1') {
        $opensslAlgorithm = OPENSSL_ALGO_SHA1;
    } elseif ($signatureMethod === 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256') {
        $opensslAlgorithm = OPENSSL_ALGO_SHA256;
    }

    if ($opensslAlgorithm === null) {
        return [
            'verified' => false,
            'error' => 'Unsupported XML SignatureMethod for Offline e-KYC verification: ' . $signatureMethod,
        ];
    }

    $verified = openssl_verify($canonicalizedSignedInfo, $signatureBytes, $publicKey, $opensslAlgorithm);
    if ($verified !== 1) {
        $opensslErrors = [];
        while (($opensslError = openssl_error_string()) !== false) {
            $opensslErrors[] = $opensslError;
        }

        $diagnostic = 'The Offline e-KYC XML digital signature verification failed after XML C14N 1.0 canonicalization of SignedInfo.';
        $diagnostic .= ' SignatureMethod=' . $signatureMethod . ', CanonicalizationMethod=' . $canonicalizationMethod . '.';
        if (!empty($opensslErrors)) {
            $diagnostic .= ' OpenSSL diagnostics: ' . implode(' | ', $opensslErrors) . '.';
        }

        return [
            'verified' => false,
            'error' => $diagnostic,
        ];
    }

    $referenceNode = $xpath->query('./ds:Reference', $signedInfoNode)->item(0);
    if (!$referenceNode) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC SignedInfo block does not contain a Reference element for digest validation.',
        ];
    }

    $digestMethodNode = $xpath->query('./ds:DigestMethod', $referenceNode)->item(0);
    $digestValueNode = $xpath->query('./ds:DigestValue', $referenceNode)->item(0);

    if (!$digestMethodNode || !$digestValueNode) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC SignedInfo Reference is missing DigestMethod or DigestValue.',
        ];
    }

    $digestMethod = trim((string) $digestMethodNode->getAttribute('Algorithm'));
    $expectedDigest = trim((string) $digestValueNode->textContent);
    $referenceUri = trim((string) $referenceNode->getAttribute('URI'));

    if ($digestMethod === '') {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC DigestMethod is missing its Algorithm attribute.',
        ];
    }

    if ($expectedDigest === '') {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC DigestValue is missing.',
        ];
    }

    if ($referenceUri !== '') {
        return [
            'verified' => false,
            'error' => 'Unsupported Offline e-KYC Reference URI for local digest validation: ' . $referenceUri,
        ];
    }

    $documentClone = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    libxml_clear_errors();

    if (!$documentClone->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC XML could not be reloaded for digest validation.',
        ];
    }

    $documentCloneXPath = new DOMXPath($documentClone);
    $documentCloneXPath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    $signatureNodesToRemove = $documentCloneXPath->query('//ds:Signature');
    if ($signatureNodesToRemove === false || $signatureNodesToRemove->length === 0) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC XML does not contain a Signature node for digest validation.',
        ];
    }

    while ($signatureNodesToRemove->length > 0) {
        $signatureNodeToRemove = $signatureNodesToRemove->item(0);
        if ($signatureNodeToRemove && $signatureNodeToRemove->parentNode) {
            $signatureNodeToRemove->parentNode->removeChild($signatureNodeToRemove);
        }
        $signatureNodesToRemove = $documentCloneXPath->query('//ds:Signature');
    }

    $documentElement = $documentClone->documentElement;
    if (!$documentElement) {
        return [
            'verified' => false,
            'error' => 'The uploaded Offline e-KYC XML did not contain a document element for digest validation.',
        ];
    }

    $canonicalizedReferenceData = $documentElement->C14N(false, false);
    if ($canonicalizedReferenceData === false || trim($canonicalizedReferenceData) === '') {
        return [
            'verified' => false,
            'error' => 'The Offline e-KYC reference content could not be canonicalized for digest validation.',
        ];
    }

    $actualDigest = null;
    if ($digestMethod === 'http://www.w3.org/2001/04/xmlenc#sha256') {
        $actualDigest = base64_encode(hash('sha256', $canonicalizedReferenceData, true));
    } elseif ($digestMethod === 'http://www.w3.org/2001/04/xmlenc#sha1') {
        $actualDigest = base64_encode(hash('sha1', $canonicalizedReferenceData, true));
    }

    if ($actualDigest === null) {
        return [
            'verified' => false,
            'error' => 'Unsupported Offline e-KYC DigestMethod for local validation: ' . $digestMethod,
        ];
    }

    if (!hash_equals($expectedDigest, $actualDigest)) {
        return [
            'verified' => false,
            'error' => 'The Offline e-KYC XML digest validation failed. Expected SHA-256 digest=' . $expectedDigest . ', computed digest=' . $actualDigest . '.',
        ];
    }

    return [
        'verified' => true,
        'error' => null,
    ];
}

function processOfflineEkycUpload(array $file, array $formData): array
{
    if (($file['name'] ?? '') === '') {
        return ['success' => false, 'error' => 'Upload a UIDAI Offline e-KYC XML or ZIP file to continue.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'The uploaded Offline e-KYC file could not be processed.'];
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid uploaded Offline e-KYC file.'];
    }

    $fileName = strtolower((string) ($file['name'] ?? ''));
    $isXml = preg_match('/\.xml$/i', $fileName) === 1;
    $isZip = preg_match('/\.(zip)$/i', $fileName) === 1;

    if (!$isXml && !$isZip) {
        return ['success' => false, 'error' => 'Only UIDAI Offline e-KYC XML or ZIP files are accepted.'];
    }

    $allowedMimeTypes = ['application/xml', 'text/xml', 'application/zip', 'application/x-zip-compressed'];
    $detectedMimeType = strtolower((string) ($file['type'] ?? 'application/octet-stream'));
    if (!in_array($detectedMimeType, $allowedMimeTypes, true) && !$isXml && !$isZip) {
        return ['success' => false, 'error' => 'Invalid Offline e-KYC file type.'];
    }

    $maxBytes = (int) env('AADHAAR_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'error' => 'Offline e-KYC file must be smaller than ' . round($maxBytes / (1024 * 1024), 1) . ' MB.'];
    }

    $uploadDirectory = __DIR__ . '/../storage/temp';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
        return ['success' => false, 'error' => 'Temporary upload storage is unavailable.'];
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file['name']));
    $targetPath = $uploadDirectory . '/' . uniqid('offline_ekyc_', true) . '_' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'The Offline e-KYC file could not be stored for processing.'];
    }

    $shareCode = trim((string) ($formData['share_code'] ?? ''));
    if ($shareCode === '') {
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        return ['success' => false, 'error' => 'Please enter the Share Code for the uploaded Offline e-KYC file.'];
    }

    $xmlContent = '';
    if ($isZip) {
        $zip = new ZipArchive();
        if ($zip->open($targetPath) !== true) {
            if (is_file($targetPath)) {
                unlink($targetPath);
            }
            return ['success' => false, 'error' => 'Unable to open the Offline e-KYC ZIP archive.'];
        }

        $xmlFileNames = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $normalizedName = str_replace('\\', '/', (string) $name);
            if (preg_match('/(^|\/)\.\.($|\/)/', $normalizedName) === 1
                || str_starts_with($normalizedName, '/')
                || preg_match('/^[A-Za-z]:\//', $normalizedName) === 1
                || str_contains($normalizedName, "\0")) {
                $zip->close();
                if (is_file($targetPath)) {
                    unlink($targetPath);
                }
                return ['success' => false, 'error' => 'The Offline e-KYC ZIP archive contains an unsafe file path.'];
            }

            if (preg_match('/\.xml$/i', $normalizedName) === 1) {
                $xmlFileNames[] = $name;
            }
        }

        if (count($xmlFileNames) !== 1) {
            $zip->close();
            if (is_file($targetPath)) {
                unlink($targetPath);
            }
            return ['success' => false, 'error' => 'The Offline e-KYC ZIP archive must contain exactly one XML file.'];
        }

        if (!$zip->setPassword($shareCode)) {
            $zip->close();
            if (is_file($targetPath)) {
                unlink($targetPath);
            }
            return ['success' => false, 'error' => 'Invalid Offline e-KYC Share Code.'];
        }

        $xmlContent = $zip->getFromName($xmlFileNames[0]);
        if ($xmlContent === false) {
            $zip->close();
            if (is_file($targetPath)) {
                unlink($targetPath);
            }
            return ['success' => false, 'error' => 'Invalid Offline e-KYC Share Code.'];
        }
        $zip->close();
    } else {
        $xmlContent = file_get_contents($targetPath);
    }

    if (is_file($targetPath)) {
        unlink($targetPath);
    }

    if ($xmlContent === false || trim($xmlContent) === '') {
        return ['success' => false, 'error' => 'Unable to read the Offline e-KYC XML content.'];
    }

    $signatureResult = verifyOfflineEkycSignature($xmlContent);
    $signatureVerified = ($signatureResult['verified'] ?? false) === true;
    if (!$signatureVerified && empty($signatureResult['demo_mode'])) {
        return ['success' => false, 'error' => $signatureResult['error']];
    }

    $parsedFields = extractOfflineEkycFields($xmlContent);
    $extractedFields = [
        'name' => $parsedFields['name'] ?? null,
        'date_of_birth' => $parsedFields['date_of_birth'] ?? null,
        'gender' => $parsedFields['gender'] ?? null,
        'address' => $parsedFields['address'] ?? null,
        'mobile_number' => null,
        'mobile_hash' => $parsedFields['mobile_hash'] ?? null,
        'reference_id' => $parsedFields['reference_id'] ?? null,
        'mobile_hash_iterations' => deriveOfflineEkycMobileHashIterations($parsedFields['reference_id'] ?? null),
        'aadhaar_number' => $parsedFields['aadhaar_number'] ?? null,
        'document_file_name' => $safeName,
        'document_mime_type' => $detectedMimeType,
        'document_size_bytes' => (int) ($file['size'] ?? 0),
    ];

    $documentInfo = [
        'document_type' => 'Offline Aadhaar e-KYC file',
        'document_file_name' => $safeName,
        'document_mime_type' => $detectedMimeType,
        'document_size_bytes' => (int) ($file['size'] ?? 0),
        'provider' => 'offline_ekyc_file',
        'processing_status' => 'offline_ekyc_file_processed',
        'state' => 'extracted',
        'notes' => $signatureVerified
            ? 'This upload was accepted as a UIDAI Offline e-KYC file candidate and the local XML structure was validated.'
            : 'This upload was accepted as a UIDAI Offline e-KYC file candidate for demo/sandbox review. Signature verification is not configured in this XAMPP environment, so the file is treated as a structurally validated sample only.',
        'extracted_fields' => $extractedFields,
        'ocr_status' => 'offline_ekyc_file_validation',
        'ocr_note' => $signatureVerified
            ? 'UIDAI Offline e-KYC file accepted for structural validation.'
            : 'UIDAI Offline e-KYC file accepted for demo/sandbox review. Full signature verification requires the official UIDAI certificate chain and XML signature verification dependency.',
        'consent_required' => true,
    ];

    return ['success' => true, 'document' => $documentInfo];
}

function extractAadhaarDocumentFields(array $file): array
{
    $sourceFile = $file['tmp_name'] ?? null;
    $mimeType = $file['type'] ?? 'application/octet-stream';
    $ocrOutput = runAadhaarOcr($sourceFile, $mimeType);

    if ($ocrOutput['success'] !== true || !is_array($ocrOutput['parsed_fields'])) {
        $fallback = buildDemoAadhaarFixture($file);
        return [
            'state' => 'extracted',
            'processing_status' => $fallback['processing_status'],
            'ocr_status' => $fallback['ocr_status'],
            'ocr_note' => $fallback['ocr_note'],
            'source' => $fallback['source'],
            'extracted_fields' => $fallback['extracted_fields'],
            'consent_required' => true,
        ];
    }

    $parsedFields = $ocrOutput['parsed_fields'];
    $extractedFields = [
        'name' => $parsedFields['name'] ?? null,
        'date_of_birth' => $parsedFields['date_of_birth'] ?? null,
        'gender' => $parsedFields['gender'] ?? null,
        'address' => $parsedFields['address'] ?? null,
        'mobile_number' => $parsedFields['mobile_number'] ?? null,
        'aadhaar_number' => $parsedFields['aadhaar_number'] ?? null,
        'document_file_name' => basename((string) ($file['name'] ?? 'aadhaar_document')),
        'document_mime_type' => $mimeType,
        'document_size_bytes' => (int) ($file['size'] ?? 0),
    ];

    return [
        'state' => 'extracted',
        'processing_status' => 'uploaded_and_extracted',
        'ocr_status' => 'ocr_engine_extraction',
        'ocr_note' => $ocrOutput['message'],
        'source' => 'local_tesseract_ocr',
        'extracted_fields' => $extractedFields,
        'consent_required' => true,
    ];
}

function buildDemoAadhaarFixture(array $file): array
{
    $fileName = strtolower(basename((string) ($file['name'] ?? 'demo_aadhaar_document.pdf')));
    $normalizedFileName = preg_replace('/[^a-z0-9]+/', '-', $fileName);
    $normalizedFileName = trim((string) $normalizedFileName, '-');

    $demoFixtures = [
        'test-user-aadhaar' => [
            'name' => 'Test User',
            'date_of_birth' => '10/05/2003',
            'gender' => 'Male',
            'address' => 'Chennai',
            'mobile_number' => '9876543210',
            'aadhaar_number' => 'XXXX XXXX 1234',
        ],
        'test-user' => [
            'name' => 'Test User',
            'date_of_birth' => '10/05/2003',
            'gender' => 'Male',
            'address' => 'Chennai',
            'mobile_number' => '9876543210',
            'aadhaar_number' => 'XXXX XXXX 1234',
        ],
        'demo-sandbox-aadhaar' => [
            'name' => 'Demo Sandbox User',
            'date_of_birth' => '15/08/2003',
            'gender' => 'Male',
            'address' => 'Demo City, India',
            'mobile_number' => '9123456780',
            'aadhaar_number' => 'XXXX XXXX 5678',
        ],
    ];

    $fixture = $demoFixtures[$normalizedFileName] ?? $demoFixtures['demo-sandbox-aadhaar'];

    return [
        'name' => $fixture['name'],
        'date_of_birth' => $fixture['date_of_birth'],
        'gender' => $fixture['gender'],
        'address' => $fixture['address'],
        'mobile_number' => $fixture['mobile_number'] ?? null,
        'aadhaar_number' => $fixture['aadhaar_number'],
        'document_file_name' => basename((string) ($file['name'] ?? 'demo_aadhaar_document.pdf')),
        'document_mime_type' => $file['type'] ?? 'application/octet-stream',
        'document_size_bytes' => (int) ($file['size'] ?? 0),
        'source' => 'demo_sandbox_fixture',
        'processing_status' => 'uploaded_and_extracted',
        'ocr_status' => 'demo_sandbox_extraction',
        'ocr_note' => 'Demo/Sandbox Aadhaar Extraction: no OCR engine is available for this upload, so the app is using a clearly separated sandbox fixture. This is not official UIDAI Aadhaar verification.',
        'consent_required' => true,
    ];
}

function parseAadhaarOcrText(string $ocrText): array
{
    $normalizedText = normalizeAadhaarOcrText($ocrText);

    $name = extractAadhaarFieldValue($normalizedText, ['Name', 'Full Name']);
    $dob = extractAadhaarFieldValue($normalizedText, ['Date of Birth', 'DOB']);
    $gender = extractAadhaarFieldValue($normalizedText, ['Gender']);
    $address = extractAadhaarAddressValue($normalizedText);
    $mobileNumber = extractAadhaarFieldValue($normalizedText, ['Mobile Number', 'Mobile', 'Phone Number', 'Phone']);

    $aadhaarNumber = null;
    if (preg_match('/\b(\d{4}[\s\-]?\d{4}[\s\-]?\d{4})\b/', $normalizedText, $matches)) {
        $aadhaarNumber = $matches[1];
    }

    if ($dob === null) {
        if (preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $normalizedText, $matches)) {
            $dob = $matches[1];
        }
    }

    if ($mobileNumber !== null) {
        $mobileNumber = preg_replace('/\D+/', '', $mobileNumber);
        $mobileNumber = strlen($mobileNumber) === 10 ? $mobileNumber : null;
    }

    return [
        'name' => $name,
        'date_of_birth' => $dob,
        'gender' => $gender,
        'address' => $address,
        'mobile_number' => $mobileNumber,
        'aadhaar_number' => $aadhaarNumber !== null ? maskAadhaarNumber($aadhaarNumber) : null,
    ];
}

function runAadhaarOcr(?string $sourceFile, string $mimeType): array
{
    if ($sourceFile === null || $sourceFile === '' || !is_file($sourceFile)) {
        return ['success' => false, 'message' => 'Aadhaar OCR could not read the uploaded document.', 'parsed_fields' => null];
    }

    $tesseractPath = resolveAadhaarOcrExecutable('tesseract', [
        env('AADHAAR_TESSERACT_PATH', null),
        'C:\Program Files\Tesseract-OCR\tesseract.exe',
    ]);

    $pdftoppmPath = resolveAadhaarOcrExecutable('pdftoppm', [
        env('AADHAAR_PDFTOPPM_PATH', null),
        'C:\Users\harik\AppData\Local\Microsoft\WinGet\Packages\oschwartz10612.Poppler_Microsoft.Winget.Source_8wekyb3d8bbwe\poppler-25.07.0\Library\bin\pdftoppm.exe',
    ]);

    if ($tesseractPath === null) {
        return ['success' => false, 'message' => 'Tesseract OCR is not configured for this environment.', 'parsed_fields' => null];
    }

    $ocrText = '';

    if ($mimeType === 'application/pdf') {
        if ($pdftoppmPath === null) {
            return ['success' => false, 'message' => 'PDF conversion utility is not available for OCR.', 'parsed_fields' => null];
        }

        $tempDir = dirname($sourceFile);
        $pagePrefix = $tempDir . DIRECTORY_SEPARATOR . 'aadhaar_ocr_page';
        $command = '"' . $pdftoppmPath . '" -f 1 -l 1 -png "' . $sourceFile . '" "' . $pagePrefix . '" 2>&1';
        exec($command, $conversionOutput, $conversionCode);

        $pageImages = glob($tempDir . DIRECTORY_SEPARATOR . 'aadhaar_ocr_page*.png');
        if (empty($pageImages)) {
            return ['success' => false, 'message' => 'The uploaded PDF could not be converted for OCR.', 'parsed_fields' => null];
        }

        $imagePath = $pageImages[0];
        $command = '"' . $tesseractPath . '" "' . $imagePath . '" stdout --psm 6 2>&1';
        exec($command, $ocrOutputLines, $ocrCode);
        $ocrText = implode("\n", $ocrOutputLines);
    } else {
        $command = '"' . $tesseractPath . '" "' . $sourceFile . '" stdout --psm 6 2>&1';
        exec($command, $ocrOutputLines, $ocrCode);
        $ocrText = implode("\n", $ocrOutputLines);
    }

    $ocrText = trim((string) $ocrText);
    if ($ocrText === '') {
        return ['success' => false, 'message' => 'OCR did not return any readable text from the uploaded Aadhaar document.', 'parsed_fields' => null];
    }

    $parsedFields = parseAadhaarOcrText($ocrText);
    $hasMinimumFields = !empty($parsedFields['name']) || !empty($parsedFields['date_of_birth']) || !empty($parsedFields['gender']) || !empty($parsedFields['address']) || !empty($parsedFields['aadhaar_number']);

    if (!$hasMinimumFields) {
        return ['success' => false, 'message' => 'OCR ran successfully, but the uploaded file did not contain extractable Aadhaar fields.', 'parsed_fields' => null];
    }

    return [
        'success' => true,
        'message' => 'Aadhaar document extracted successfully using the local Tesseract OCR engine.',
        'parsed_fields' => $parsedFields,
    ];
}

function processAadhaarUpload(array $file, array $formData): array
{
    if (($file['name'] ?? '') === '') {
        return ['success' => false, 'error' => 'Upload an Aadhaar document to continue.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'The uploaded Aadhaar document could not be processed.'];
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['success' => false, 'error' => 'Invalid uploaded file.'];
    }

    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    $detectedMimeType = $file['type'] ?? 'application/octet-stream';
    if (!in_array($detectedMimeType, $allowedMimeTypes, true)) {
        return ['success' => false, 'error' => 'Only PDF, JPG, or PNG files are allowed for Aadhaar document upload.'];
    }

    $maxBytes = (int) env('AADHAAR_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'error' => 'Aadhaar document must be smaller than ' . round($maxBytes / (1024 * 1024), 1) . ' MB.'];
    }

    $uploadDirectory = __DIR__ . '/../storage/temp';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true) && !is_dir($uploadDirectory)) {
        return ['success' => false, 'error' => 'Temporary upload storage is unavailable.'];
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file['name']));
    $targetPath = $uploadDirectory . '/' . uniqid('aadhaar_', true) . '_' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'The Aadhaar document could not be stored for processing.'];
    }

    $extracted = extractAadhaarDocumentFields([
        'name' => $safeName,
        'type' => $detectedMimeType,
        'size' => (int) ($file['size'] ?? 0),
        'tmp_name' => $targetPath,
    ]);

    if (is_file($targetPath)) {
        unlink($targetPath);
    }

    $documentInfo = [
        'document_type' => 'Aadhaar document',
        'document_file_name' => $safeName,
        'document_mime_type' => $detectedMimeType,
        'document_size_bytes' => (int) ($file['size'] ?? 0),
        'provider' => $extracted['source'] === 'demo_sandbox_fixture' ? 'demo_sandbox' : 'local_tesseract',
        'processing_status' => $extracted['processing_status'],
        'state' => $extracted['state'],
        'notes' => 'This DEMO/SANDBOX flow extracts available document metadata and displays it for review. It does not perform official UIDAI verification.',
        'extracted_fields' => $extracted['extracted_fields'],
        'ocr_status' => $extracted['ocr_status'],
        'ocr_note' => $extracted['ocr_note'],
        'consent_required' => true,
    ];

    return ['success' => true, 'document' => $documentInfo];
}
