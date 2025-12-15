<?php
/**
 * Test script for OpenAI API connection
 * 
 * Usage: Set your OpenAI endpoint and key below, then run this script
 */

// Set your OpenAI endpoint and key here for testing
$openai_endpoint = 'https://api.openai.com/v1/chat/completions';  // Change this to your endpoint
// Get API key from environment variable or set it here
$openai_key = getenv('OPENAI_API_KEY') ?: '';  // Reads from OPENAI_API_KEY env var, or set directly here

// Test data
$instructions = "Write a 500-word essay about the importance of education.";
$paper_text = "Education is very important. It helps people learn new things and get better jobs. Without education, people would not know how to read or write. Education makes society better.";

echo "Testing OpenAI API Connection\n";
echo "=============================\n\n";
echo "Endpoint: {$openai_endpoint}\n";
echo "API Key: " . (empty($openai_key) ? '(not set)' : substr($openai_key, 0, 10) . '...') . "\n\n";

if ( empty($openai_key) ) {
    echo "ERROR: Please set your OpenAI API key in the script.\n";
    exit(1);
}

// Prepare OpenAI API request
    $system_prompt = "You are reviewing a student's paper submission. Provide constructive feedback in a brief paragraph (approximately 200 words or less). Focus on strengths, areas for improvement, and specific suggestions for revision. Be encouraging but honest, and reference specific parts of the paper when possible.";

if ( !empty(trim($instructions)) ) {
    $system_prompt .= "\n\nAssignment Instructions:\n" . $instructions;
}

$payload = array(
    'model' => 'gpt-4.1-mini',
    'messages' => array(
        array(
            'role' => 'system',
            'content' => $system_prompt
        ),
        array(
            'role' => 'user',
            'content' => "Please review the following paper:\n\n" . $paper_text
        )
    ),
    'max_tokens' => 250,
    'temperature' => 0.7
);

echo "Sending request...\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

// Make API call
$ch = curl_init($openai_endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $openai_key
));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "Response Code: {$http_code}\n";

if ( $curl_error ) {
    echo "ERROR: CURL Error - {$curl_error}\n";
    exit(1);
}

if ( $http_code !== 200 ) {
    echo "ERROR: HTTP {$http_code}\n";
    echo "Response: {$response}\n";
    exit(1);
}

$response_data = json_decode($response, true);

if ( !$response_data ) {
    echo "ERROR: Invalid JSON response\n";
    echo "Response: {$response}\n";
    exit(1);
}

echo "\nResponse Data:\n";
echo json_encode($response_data, JSON_PRETTY_PRINT) . "\n\n";

if ( isset($response_data['choices']) && is_array($response_data['choices']) && !empty($response_data['choices']) ) {
    $comment = $response_data['choices'][0]['message']['content'] ?? '';
    if ( !empty($comment) ) {
        echo "SUCCESS! AI Comment Generated:\n";
        echo "==============================\n";
        echo $comment . "\n";
        echo "\nComment length: " . strlen($comment) . " characters\n";
    } else {
        echo "ERROR: Empty comment in response\n";
        exit(1);
    }
} else {
    echo "ERROR: Invalid response format - missing 'choices' array\n";
    exit(1);
}

echo "\nTest completed successfully!\n";

