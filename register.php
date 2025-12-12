<?php

$REGISTER_LTI2 = array(
"name" => "MiniPaper",
"FontAwesome" => "fa-align-left",
"short_name" => "MiniPaper",
"description" => "A tool to collect and grade short papers written with AI assistance. Students submit papers using CKEditor 5.0, with feedback provided through comment streams. Supports instructor grading, peer review comments, and optional AI-generated feedback.",
    // By default, accept launch messages..
    "messages" => array("launch"),
    "privacy_level" => "name_only",  // anonymous, name_only, public
    "license" => "Apache",
    "languages" => array(
        "English",
    ),
    "source_url" => "https://github.com/tsugitools/aipaper",
    // For now Tsugi tools delegate this to /lti/store
    "placements" => array(
        /*
        "course_navigation", "homework_submission",
        "course_home_submission", "editor_button",
        "link_selection", "migration_selection", "resource_selection",
        "tool_configuration", "user_navigation"
        */
    ),
    "video" => "https://www.youtube.com/watch?v=n2qz6XpxZ1Q",
    "submissionReview" => array(
        "reviewableStatus" => array("InProgress", "Submitted", "Completed"),
        "label" => "MiniPaper",
        "url" => "grade-detail.php",  // A relative URL in this context
        "custom" => array(
            "review_towel" => "42",
        ),
    ),
    "analytics" => array(
        "launches"
    ),
    "screen_shots" => array(
        
    )

);
