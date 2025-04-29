<?php
declare(strict_types=1);
const API_BASE_URL = 'http://localhost/sms2/api';
const MAX_CONCURRENT_REQUESTS = 123;
const CURL_TIMEOUT_MS = 1000;
const CONNECT_TIMEOUT_MS = 1000;
const PHONE_REGEX = '/^\+?\d{10,15}$/';
try {
    $phone = isset($_REQUEST['num']) ? trim((string) $_REQUEST['num']) : '';
    if (empty($phone) || !preg_match(PHONE_REGEX, $phone)) {
        header('Content-Type: application/json', true, 400);
        echo json_encode(['status' => false, 'message' => 'Invalid or missing phone number.'], JSON_PRETTY_PRINT);
        exit;
    }
    $multiCurl = [];
    $mh = curl_multi_init();
    $responses = [];
    for ($i = 1; $i <= MAX_CONCURRENT_REQUESTS; $i++) {
        $url = API_BASE_URL . $i . '.php?phone=' . urlencode($phone);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => CURL_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => CONNECT_TIMEOUT_MS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
        ]);
        curl_multi_add_handle($mh, $ch);
        $multiCurl[$i] = $ch;
    }
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
            throw new RuntimeException('cURL multi execution failed');
        }
        if ($active) {
            curl_multi_select($mh, 0.1);
        }
    } while ($active);
    foreach ($multiCurl as $index => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $responses[$index] = $errno ?
            ['status' => false, 'message' => 'Request failed', 'http_code' => $httpCode] :
            ['status' => true, 'message' => 'Request succeeded', 'http_code' => $httpCode, 'data' => json_decode($response, true) ?? $response];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    header('Content-Type: application/json', true, 200);
    echo json_encode(['status' => true, 'message' => 'All requests processed.', 'responses' => $responses], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['status' => false, 'message' => 'An unexpected error occurred.'], JSON_PRETTY_PRINT);
}
?>