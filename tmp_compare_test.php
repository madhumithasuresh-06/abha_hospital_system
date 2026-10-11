<?php
require 'C:\xampp\htdocs\abha_hospital_system\includes\env.php';
require 'C:\xampp\htdocs\abha_hospital_system\includes\aadhaar.php';
require 'C:\xampp\htdocs\abha_hospital_system\includes\ekyc.php';

$_ENV = [];
$_SERVER = [];
loadEnvironment('C:\xampp\htdocs\abha_hospital_system');

$xml = file_get_contents('C:\xampp\htdocs\abha_hospital_system\storage\temp\actual_offline_ekyc.xml');
$parsed = extractOfflineEkycFields($xml);
$identity = [
    'full_name' => 'Madhumitha S',
    'date_of_birth' => '2006-05-06',
];

$nameNormalizedIdentity = normalizeEkycComparisonValue((string) $identity['full_name']);
$nameNormalizedExtracted = normalizeEkycComparisonValue((string) $parsed['name']);
$dobNormalizedIdentity = normalizeDateForComparison((string) $identity['date_of_birth']);
$dobNormalizedExtracted = normalizeDateForComparison((string) $parsed['date_of_birth']);

$summary = buildEkycComparisonSummary($identity, ['extracted_fields' => $parsed]);

var_export([
    'extracted_name' => $parsed['name'],
    'extracted_dob' => $parsed['date_of_birth'],
    'health_identity_name' => $identity['full_name'],
    'health_identity_dob' => $identity['date_of_birth'],
    'normalized_name_values' => [
        'identity' => $nameNormalizedIdentity,
        'extracted' => $nameNormalizedExtracted,
    ],
    'normalized_dob_values' => [
        'identity' => $dobNormalizedIdentity,
        'extracted' => $dobNormalizedExtracted,
    ],
    'name_comparison_result' => $nameNormalizedIdentity === $nameNormalizedExtracted,
    'dob_comparison_result' => $dobNormalizedIdentity === $dobNormalizedExtracted,
    'overall_verification_result' => (bool) ($summary['all_required_fields_matched'] ?? false),
    'comparison_summary' => $summary,
]);
