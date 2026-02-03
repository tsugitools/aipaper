<?php

function grade_paper($paper, $rubric, $api_key) {
    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $api_key;

    $prompt = "
    You are an instructor grading a short paper.
    Rubric:
    $rubric

    Paper:
    $paper

    Give one paragraph of feedback based strictly on the rubric.
    ";

    $data = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ]
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result["candidates"][0]["content"]["parts"][0]["text"] ?? null;
}

// Example code (commented out to avoid undefined variable errors):
// $feedback = grade_paper($paper, $rubric, "your-api-key");
// echo $feedback;



function call_llm($provider, $prompt, $api_key) {

    if ($provider === "gemini") {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $api_key;

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ]
        ];

        $extract = function($result) {
            return $result["candidates"][0]["content"]["parts"][0]["text"] ?? null;
        };

    } else if ($provider === "openai") {
        $endpoint = "https://api.openai.com/v1/chat/completions";

        $payload = [
            "model" => "gpt-4.1-mini",   // or another cheap model
            "messages" => [
                ["role" => "user", "content" => $prompt]
            ]
        ];

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $api_key
        ];

        $extract = function($result) {
            return $result["choices"][0]["message"]["content"] ?? null;
        };
    }

    // --- send request ---
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $extract($result);
}

// Example code (commented out to avoid undefined variable errors):
// $prompt = "
// Rubric:
// $rubric
//
// Paper:
// $paper
//
// Give one paragraph of constructive feedback.
// ";
//
// // Gemini
// $gemini_feedback = call_llm("gemini", $prompt, $gemini_key);
//
// // OpenAI
// $openai_feedback = call_llm("openai", $prompt, $openai_key);

/**
 * Send a notification to a student when a comment is added to their paper
 * 
 * @param int $paper_owner_user_id The user ID of the student who owns the paper
 * @param string $comment_type The type of comment ('student', 'instructor', or 'AI')
 * @param string|null $assignment_title Optional assignment title for the notification
 * @param string|null $url Optional URL to link to
 * @return bool True if notification was sent (or not needed), false on error
 */
function notifyCommentAdded($paper_owner_user_id, $comment_type, $assignment_title = null, $url = null)
{
    global $LAUNCH, $LINK;
    
    // Check if NotificationsService class exists before using it
    // class_exists() will trigger Composer autoloader if available
    try {
        if (!class_exists('\Tsugi\Util\NotificationsService', true)) {
            // Notification feature not available, silently skip
            error_log("Aipaper notification skipped: NotificationsService class not available (paper_owner_user_id=$paper_owner_user_id, comment_type=$comment_type, url=" . ($url ?? 'null') . ")");
            return true;
        }
    } catch (\Exception $e) {
        // If autoloader throws an exception, assume class doesn't exist
        error_log("Aipaper notification skipped: Exception checking NotificationsService class (paper_owner_user_id=$paper_owner_user_id, comment_type=$comment_type, url=" . ($url ?? 'null') . "): " . $e->getMessage());
        return true;
    }
    
    try {
        // Determine who added the comment
        $commenter_name = 'Someone';
        $title = ''; // Initialize title
        if ($comment_type == 'AI') {
            $commenter_name = 'AI';
            $title = 'AI feedback added to your paper';
        } else if ($comment_type == 'instructor') {
            $commenter_name = 'An instructor';
            $title = 'Instructor comment added to your paper';
        } else {
            $commenter_name = 'A peer';
            $title = 'Peer comment added to your paper';
        }
        
        if ($assignment_title) {
            $title = $title . ': ' . $assignment_title;
        }
        
        $text = "{$commenter_name} has added a comment to your paper.";
        
        // Generate dedupe_key based on paper/assignment and comment type
        $link_id = (isset($LINK) && is_object($LINK) && isset($LINK->id)) ? $LINK->id : 'unknown';
        $dedupe_key = \Tsugi\Util\NotificationsService::generateDedupeKey('aipaper-comment', $paper_owner_user_id, $link_id, $comment_type);
        
        $result = \Tsugi\Util\NotificationsService::create($paper_owner_user_id, $title, $text, $url, null, $dedupe_key);
        if ($result !== false) {
            error_log("Aipaper notification sent successfully (paper_owner_user_id=$paper_owner_user_id, comment_type=$comment_type, title=" . ($assignment_title ?? 'default') . ", url=" . ($url ?? 'null') . ", dedupe_key=$dedupe_key)");
            return true;
        } else {
            error_log("Aipaper notification failed: NotificationsService::create returned false (paper_owner_user_id=$paper_owner_user_id, comment_type=$comment_type, url=" . ($url ?? 'null') . ")");
            return false;
        }
    } catch (\Exception $e) {
        // Log error but don't fail the comment addition
        error_log("Error sending aipaper notification (paper_owner_user_id=$paper_owner_user_id, comment_type=$comment_type, url=" . ($url ?? 'null') . "): " . $e->getMessage());
        return false;
    }
}
