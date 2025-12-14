<?php
/**
 * Simple test endpoint for AI comment generation
 * This endpoint simulates an AI API for testing purposes
 */

header('Content-Type: application/json');

// Only allow POST requests
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed. Use POST.'));
    exit;
}

// Read JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if ( !$data ) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid JSON'));
    exit;
}

// Validate required fields
if ( !isset($data['instructions']) || !isset($data['paper']) ) {
    http_response_code(400);
    echo json_encode(array('error' => 'Missing required fields: instructions and paper'));
    exit;
}

$instructions = $data['instructions'];
$paper = $data['paper'];
$max_length = isset($data['max_length']) ? intval($data['max_length']) : 500;

// Simple test implementation
$word_count = str_word_count(strip_tags($paper));
$instructions_length = strlen(trim($instructions));

// Generate a test comment
$comment = "This is a test AI-generated comment from the test endpoint. ";
$comment .= "Your paper contains approximately {$word_count} words. ";

if ( !empty($instructions) ) {
    $comment .= "The rubric/instructions (" . $instructions_length . " characters) have been considered. ";
}

$comment .= "This is simulated feedback - in production, this would be actual AI analysis of your paper against the rubric. ";

// Truncate if needed
if ( strlen($comment) > $max_length ) {
    $comment = substr($comment, 0, $max_length - 3) . '...';
}

// Return response in expected format
echo json_encode(array(
    'comment' => $comment,
    'test_mode' => true,
    'word_count' => $word_count
));

