<?php
/**
 * Standalone test script for UMich API - matches bash script exactly
 * 
 * Usage: Set environment variables and run:
 * export UMICH_API_KEY="your-api-key"
 * export UMICH_ORGANIZATION="your-organization-id"
 * php test-umich-api.php
 */

// Get API key and organization from environment variables
$API_KEY = getenv('UMICH_API_KEY') ?: '';
$ORGANIZATION = getenv('UMICH_ORGANIZATION') ?: '';

$API_BASE = "https://api.umgpt.umich.edu/azure-openai-api";
$API_VERSION = "2025-01-01-preview";
$DEPLOYMENT_ID = "gpt-5-mini";

echo "Testing UMich API\n\n";

if ( empty($API_KEY) || empty($ORGANIZATION) ) {
    echo "ERROR: Required environment variables are not set.\n\n";
    if ( empty($API_KEY) ) {
        echo "  - UMICH_API_KEY is missing\n";
    }
    if ( empty($ORGANIZATION) ) {
        echo "  - UMICH_ORGANIZATION is missing\n";
    }
    echo "\nTo set them, run:\n";
    echo "  export UMICH_API_KEY=\"your-api-key-here\"\n";
    echo "  export UMICH_ORGANIZATION=\"your-organization-id\"\n";
    echo "  php test-umich-api.php\n\n";
    echo "Or set them inline:\n";
    echo "  UMICH_API_KEY=\"your-api-key\" UMICH_ORGANIZATION=\"your-org-id\" php test-umich-api.php\n";
    exit(1);
}

// Build the URL
$url = $API_BASE . "/openai/deployments/" . $DEPLOYMENT_ID . "/chat/completions?api-version=" . $API_VERSION;

echo "URL: " . $url . "\n";
echo "API Key: " . substr($API_KEY, 0, 10) . "...\n";
echo "Organization: " . $ORGANIZATION . "\n\n";

// Prepare the payload (exactly as in bash script)
$payload = array(
    "messages" => array(
        array("role" => "system", "content" => "You are a helpful bot"),
        array("role" => "user", "content" => "What is 2+2")
    ),
    "model" => "gpt-5-mini"
);

$json_payload = json_encode($payload);
echo "Payload: " . $json_payload . "\n\n";

// Prepare headers (exactly as in bash script)
$headers = array(
    "Content-Type: application/json",
    "OpenAI-Organization: " . $ORGANIZATION,
    "api-key: " . $API_KEY
);

echo "Headers:\n";
foreach ($headers as $header) {
    echo "  " . $header . "\n";
}
echo "\n";

// Make the curl request
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// Capture verbose output
$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_STDERR, $verbose);

echo "Sending request...\n";
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_errno = curl_errno($ch);

// Get verbose output
rewind($verbose);
$verbose_log = stream_get_contents($verbose);
fclose($verbose);

curl_close($ch);

echo "\n=== Results ===\n";
echo "HTTP Code: " . $http_code . "\n";
echo "CURL Error Number: " . $curl_errno . "\n";
echo "CURL Error: " . ($curl_error ?: 'none') . "\n";

if ( !empty($verbose_log) ) {
    echo "\n=== Verbose Output ===\n";
    echo $verbose_log . "\n";
}

echo "\n=== Response ===\n";
if ( $response ) {
    echo $response . "\n";
    
    // Try to decode and pretty print JSON
    $response_data = json_decode($response, true);
    if ( $response_data ) {
        echo "\n=== Parsed Response ===\n";
        echo json_encode($response_data, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "No response received\n";
}
