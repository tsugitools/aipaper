<?php
/**
 * Utility functions for AI comment generation
 */

use \Tsugi\Core\Settings;

/**
 * Generate AI comment by calling AI API with instructions and paper
 * @param string $instructions Assignment instructions/rubric
 * @param string $paper_text The student's paper text
 * @param string $api_url Optional API endpoint URL (if null, uses settings)
 * @return array Array with 'success' (bool) and 'comment' (string) or 'error' (string)
 */
function generateAIComment($instructions, $paper_text, $api_url = null) {
    global $CFG, $LAUNCH;
    
    // Get API URL from settings if not provided or if empty string passed
    if ( $api_url === null || $api_url === '' ) {
        $api_url = Settings::linkGet('ai_api_url', '');
        error_log("AI Comment: API URL consulted from settings: '" . $api_url . "'");
    } else {
        error_log("AI Comment: API URL provided as parameter: '" . $api_url . "'");
    }
    
    // Get user info for logging
    $user_id = isset($LAUNCH->user->id) ? $LAUNCH->user->id : 'unknown';
    $user_email = isset($LAUNCH->user->email) ? $LAUNCH->user->email : 'unknown';
    $link_id = isset($LAUNCH->link->id) ? $LAUNCH->link->id : 'unknown';
    
    // If no API URL is configured, use test endpoint function directly
    if ( empty($api_url) ) {
        error_log("AI Comment: No API URL configured, using test function - user_id: {$user_id}, email: {$user_email}, link_id: {$link_id}");
        return generateAITestComment($instructions, $paper_text);
    }
    
    // Get API key if configured
    $api_key = Settings::linkGet('ai_api_key', '');
    $has_api_key = !empty($api_key);
    
    error_log("AI Comment: Starting API request - user_id: {$user_id}, email: {$user_email}, link_id: {$link_id}, api_url: '{$api_url}', has_api_key: " . ($has_api_key ? 'yes' : 'no'));
    
    // Prepare the request payload
    $payload = array(
        'instructions' => $instructions,
        'paper' => $paper_text,
        'max_length' => 500  // Request a short paragraph
    );
    
    // Make API call
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        !empty($api_key) ? 'Authorization: Bearer ' . $api_key : ''
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 second timeout
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ( $curl_error ) {
        error_log("AI Comment: API request failed - CURL Error: {$curl_error} - user_id: {$user_id}, api_url: '{$api_url}'");
        return array('success' => false, 'error' => 'CURL Error: ' . $curl_error);
    }
    
    if ( $http_code !== 200 ) {
        error_log("AI Comment: API request failed - HTTP {$http_code} - user_id: {$user_id}, api_url: '{$api_url}', response: " . substr($response, 0, 200));
        return array('success' => false, 'error' => 'API returned HTTP ' . $http_code);
    }
    
    $response_data = json_decode($response, true);
    
    if ( !$response_data || !isset($response_data['comment']) ) {
        error_log("AI Comment: API request failed - Invalid response format - user_id: {$user_id}, api_url: '{$api_url}', response: " . substr($response, 0, 200));
        return array('success' => false, 'error' => 'Invalid API response format');
    }
    
    error_log("AI Comment: API request successful - user_id: {$user_id}, api_url: '{$api_url}', comment_length: " . strlen($response_data['comment']));
    return array('success' => true, 'comment' => $response_data['comment']);
}

/**
 * Generate a test AI comment (used when no API endpoint is configured)
 * @param string $instructions Assignment instructions/rubric
 * @param string $paper_text The student's paper text
 * @return array Array with 'success' (bool) and 'comment' (string)
 */
function generateAITestComment($instructions, $paper_text) {
    global $LAUNCH;
    
    // Get user info for logging
    $user_id = isset($LAUNCH->user->id) ? $LAUNCH->user->id : 'unknown';
    $user_email = isset($LAUNCH->user->email) ? $LAUNCH->user->email : 'unknown';
    
    // Simple test implementation - generates a basic feedback comment
    $word_count = str_word_count(strip_tags($paper_text));
    $has_instructions = !empty(trim($instructions));
    
    $comment = "This is a test AI-generated comment. ";
    $comment .= "Your paper contains approximately {$word_count} words. ";
    
    if ( $has_instructions ) {
        $comment .= "The assignment instructions have been reviewed. ";
    }
    
    $comment .= "In a production environment, this would be replaced with actual AI-generated feedback based on the rubric and your paper content.";
    
    error_log("AI Comment: Test comment generated - user_id: {$user_id}, email: {$user_email}, word_count: {$word_count}");
    
    return array('success' => true, 'comment' => $comment);
}

