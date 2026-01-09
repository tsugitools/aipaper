<?php
/**
 * Utility functions for AI comment generation
 */

 use \Tsugi\Util\U;
use \Tsugi\Core\Settings;

/**
 * Get the AI API URL, handling special case for key '12345'
 * @return array Array with 'url' (string|null) and 'configured' (bool)
 */
function getAIApiUrl() {
    global $LAUNCH;
    
    $ai_api_url = Settings::linkGet('ai_api_url', '');
    $key = $LAUNCH->key->key ?? '';
    
    // Auto-use test endpoint if key is '12345' and no API URL is configured
    if ( empty($ai_api_url) && $key === '12345' ) {
        $path = U::rest_path();
        $ai_api_url = $path->base_url . $path->parent . '/ai-test.php';
    }
    
    $configured = !empty($ai_api_url) || $key === '12345';
    
    return array(
        'url' => !empty($ai_api_url) ? $ai_api_url : null,
        'configured' => $configured
    );
}

/**
 * Check if AI is configured (has API URL or key is '12345')
 * @return bool True if AI is configured
 */
function isAIConfigured() {
    $api_info = getAIApiUrl();
    return $api_info['configured'];
}

/**
 * Generate AI comment by calling AI API with instructions and paper
 * @param string $instructions Assignment instructions/rubric
 * @param string $paper_text The student's paper text
 * @param string $api_url Optional API endpoint URL (if null, uses settings)
 * @return array Array with 'success' (bool) and 'comment' (string) or 'error' (string)
 */
function generateAIComment($instructions, $paper_text, $api_url = null) {
    global $CFG, $LAUNCH;
    
    // Increase execution time for API calls (MAMP/web context may have shorter limits)
    $max_execution_time = ini_get('max_execution_time');
    if ( $max_execution_time > 0 && $max_execution_time < 60 ) {
        set_time_limit(60);
    }
    
    // Get API URL from settings if not provided or if empty string passed
    if ( $api_url === null || $api_url === '' ) {
        $api_url = Settings::linkGet('ai_api_url', '');
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
    
    // Get organization if configured (for UMich API)
    $api_organization = Settings::linkGet('ai_api_organization', '');
    
    // Check if this is an OpenAI-compatible endpoint
    $is_openai = (strpos($api_url, 'api.openai.com') !== false || strpos($api_url, 'openai.com') !== false);
    // Check if this is a UMich API endpoint (check for specific UMich API domain)
    $is_umich = (strpos($api_url, 'umgpt.umich.edu') !== false);
    
    $api_type = $is_umich ? 'UMich' : ($is_openai ? 'OpenAI' : 'Custom');
    error_log("AI Comment: Accessing {$api_type} API - user_id: {$user_id}, api_url: '{$api_url}'");
    
    // Prepare the request payload based on API type
    if ( $is_openai || $is_umich ) {
        // Build system prompt - strip HTML tags for cleaner content
        $system_prompt = "You are reviewing a student's paper submission. Provide constructive feedback in a brief paragraph (approximately 200 words or less). Focus on strengths, areas for improvement, and specific suggestions for revision. Be encouraging but honest, and reference specific parts of the paper when possible.";
        
        if ( !empty(trim($instructions)) ) {
            // Strip HTML tags from instructions for cleaner API payload
            $instructions_clean = strip_tags($instructions);
            $instructions_clean = preg_replace('/\s+/', ' ', $instructions_clean); // Normalize whitespace
            $instructions_clean = trim($instructions_clean);
            if ( !empty($instructions_clean) ) {
                $system_prompt .= "\n\nAssignment Instructions:\n" . $instructions_clean;
            }
        }
        
        // Clean paper text - strip HTML tags
        $paper_text_clean = strip_tags($paper_text);
        $paper_text_clean = trim($paper_text_clean);
        
        if ( $is_umich ) {
            // UMich API format - match test-umich-api-web.php structure (no max_tokens/temperature)
            // Extract deployment ID from UMich URL (e.g., /deployments/gpt-5-mini/)
            $deployment_id = 'gpt-5-mini'; // default
            if ( preg_match('/\/deployments\/([^\/]+)/', $api_url, $matches) ) {
                $deployment_id = $matches[1];
            }
            
            $payload = array(
                "messages" => array(
                    array("role" => "system", "content" => $system_prompt),
                    array("role" => "user", "content" => "Please review the following paper:\n\n" . $paper_text_clean)
                ),
                "model" => $deployment_id
            );
        } else {
            // OpenAI API format
            $payload = array(
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => $system_prompt
                    ),
                    array(
                        'role' => 'user',
                        'content' => "Please review the following paper:\n\n" . $paper_text_clean
                    )
                ),
                'max_tokens' => 250,
                'temperature' => 0.7,
                'model' => 'gpt-4.1-mini'
            );
        }
    } else {
        // Custom API format (original)
        $payload = array(
            'instructions' => $instructions,
            'paper' => $paper_text,
            'max_length' => 200  // Request a short paragraph
        );
    }
    
    // Prepare headers
    $headers = array();
    if ( $is_umich ) {
        // UMich API headers - match bash script exactly
        $headers[] = 'Content-Type: application/json';
        if ( !empty($api_organization) ) {
            $headers[] = 'OpenAI-Organization: ' . $api_organization;
        }
        if ( !empty($api_key) ) {
            $headers[] = 'api-key: ' . $api_key;
        }
    } else {
        // OpenAI and other APIs
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: */*';
        $headers[] = 'User-Agent: AIPaper-Tool/1.0';
        if ( !empty($api_key) ) {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }
    }
    
    // Encode JSON payload
    $json_payload = json_encode($payload);
    
    // Make API call - match working test-umich-api.php exactly
    // Add MAMP-specific workarounds if needed
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    error_log("AI Comment: Sending request to {$api_type} API");
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ( $curl_error ) {
        $error_log_msg = "AI Comment: API request failed - CURL Error: {$curl_error} - user_id: {$user_id}, api_url: '{$api_url}'";
        error_log($error_log_msg);
        return array(
            'success' => false, 
            'error' => 'CURL Error: ' . $curl_error,
            'error_log' => $error_log_msg
        );
    }
    
    if ( $http_code !== 200 ) {
        $response_preview = substr($response, 0, 200);
        $error_log_msg = "AI Comment: API request failed - HTTP {$http_code} - user_id: {$user_id}, api_url: '{$api_url}', response: {$response_preview}";
        error_log($error_log_msg);
        return array(
            'success' => false, 
            'error' => 'API returned HTTP ' . $http_code,
            'error_log' => $error_log_msg
        );
    }
    
    $response_data = json_decode($response, true);
    
    // Parse response based on API type
    if ( $is_openai || $is_umich ) {
        // OpenAI-compatible response format: choices[0].message.content
        if ( !$response_data || !isset($response_data['choices']) || !is_array($response_data['choices']) || empty($response_data['choices']) ) {
            $response_preview = substr($response, 0, 200);
            $api_type = $is_umich ? 'UMich' : 'OpenAI';
            $error_log_msg = "AI Comment: {$api_type} API request failed - Invalid response format - user_id: {$user_id}, api_url: '{$api_url}', response: {$response_preview}";
            error_log($error_log_msg);
            return array(
                'success' => false, 
                'error' => "Invalid {$api_type} API response format",
                'error_log' => $error_log_msg
            );
        }
        
        $comment = $response_data['choices'][0]['message']['content'] ?? '';
        if ( empty($comment) ) {
            $response_preview = substr($response, 0, 200);
            $api_type = $is_umich ? 'UMich' : 'OpenAI';
            $error_log_msg = "AI Comment: {$api_type} API request failed - Empty comment in response - user_id: {$user_id}, api_url: '{$api_url}', response: {$response_preview}";
            error_log($error_log_msg);
            return array(
                'success' => false, 
                'error' => "{$api_type} API returned empty comment",
                'error_log' => $error_log_msg
            );
        }
        
        $comment_length = strlen($comment);
        error_log("AI Comment: Received response from {$api_type} API - user_id: {$user_id}, comment_length: {$comment_length} characters");
        return array('success' => true, 'comment' => trim($comment));
    } else {
        // Custom API response format: comment field
        if ( !$response_data || !isset($response_data['comment']) ) {
            $response_preview = substr($response, 0, 200);
            $error_log_msg = "AI Comment: API request failed - Invalid response format - user_id: {$user_id}, api_url: '{$api_url}', response: {$response_preview}";
            error_log($error_log_msg);
            return array(
                'success' => false, 
                'error' => 'Invalid API response format',
                'error_log' => $error_log_msg
            );
        }
        
        $comment_length = strlen($response_data['comment']);
        error_log("AI Comment: Received response from Custom API - user_id: {$user_id}, comment_length: {$comment_length} characters");
        return array('success' => true, 'comment' => $response_data['comment']);
    }
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
    
    return array('success' => true, 'comment' => $comment);
}

