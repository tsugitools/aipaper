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

// Example:
$feedback = grade_paper($paper, $rubric, "your-api-key");
echo $feedback;



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

$prompt = "
Rubric:
$rubric

Paper:
$paper

Give one paragraph of constructive feedback.
";

// Gemini
$gemini_feedback = call_llm("gemini", $prompt, $gemini_key);

// OpenAI
$openai_feedback = call_llm("openai", $prompt, $openai_key);


