<?php
require_once "../config.php";
require_once "points-util.php";
require_once "ai-util.php";

use \Tsugi\Util\U;
use \Tsugi\Util\FakeName;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\Core\Result;
use \Tsugi\Util\LTI13;
use \Tsugi\UI\SettingsForm;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

// If settings were updated
if ( SettingsForm::handleSettingsPost() ) {
    $_SESSION["success"] = "Settings updated.";
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

$LAUNCH->link->settingsDefaultsFromCustom(array('instructions', 'submitpoints', 'instructorpoints', 'commentpoints', 'mincomments', 'userealnames', 'allowall', 'resubmit', 'auto_timeout'));

// Grab the due date information
$dueDate = SettingsForm::getDueDate();

$user_id = U::safe_href(U::get($_GET, 'user_id'));
if ( $user_id && ! $LAUNCH->user->instructor ) {
    http_response_code(403);
    die('Not authorized');
}
if ( ! $user_id ) $user_id = $LAUNCH->user->id;

$inst_note = $LAUNCH->result->getNote($user_id);

// Get result_id for this user
$result_id = $RESULT->id;
if ( ! $result_id ) {
    $_SESSION['error'] = 'No result record found';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Load or create aipaper_result record
$paper_row = $PDOX->rowDie(
    "SELECT raw_submission, ai_enhanced_submission, submitted, flagged, flagged_by, json, updated_at, instructor_points
     FROM {$p}aipaper_result WHERE result_id = :RID",
    array(':RID' => $result_id)
);

if ( $paper_row === false ) {
    // Create new record
    $PDOX->queryDie(
        "INSERT INTO {$p}aipaper_result (result_id, created_at) VALUES (:RID, NOW())",
        array(':RID' => $result_id)
    );
    $paper_row = array(
        'raw_submission' => '',
        'ai_enhanced_submission' => '',
        'submitted' => false,
        'flagged' => false,
        'flagged_by' => null,
        'json' => null
    );
}

// Check submission status from submitted column (MySQL returns 0/1, not boolean)
$is_submitted = isset($paper_row['submitted']) && ($paper_row['submitted'] == true || $paper_row['submitted'] == 1);

// Check if resubmit is allowed
$resubmit_allowed = Settings::linkGet('resubmit', false);
// Can edit only if not submitted (or if instructor)
// Resubmit setting only controls Reset button visibility, not editability
$can_edit = !$is_submitted || $USER->instructor;

// Get minimum comments setting
$min_comments = Settings::linkGet('mincomments', 0);
$min_comments = intval($min_comments);

// Count how many unique submissions the current user has reviewed (for students only)
$reviewed_count = 0;
$total_comments_made = 0;
if ( !$USER->instructor ) {
    $reviewed_row = $PDOX->rowDie(
        "SELECT COUNT(DISTINCT result_id) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id = :UID",
        array(':UID' => $USER->id)
    );
    $reviewed_count = $reviewed_row ? intval($reviewed_row['cnt']) : 0;
    
    // Count total comments made by this student (for points calculation)
    $total_comments_row = $PDOX->rowDie(
        "SELECT COUNT(*) as cnt
         FROM {$p}aipaper_comment
         WHERE user_id = :UID",
        array(':UID' => $USER->id)
    );
    $total_comments_made = $total_comments_row ? intval($total_comments_row['cnt']) : 0;
}

// Auto-grade logic: Send grade of 1.0 after timeout if overall_points > 0
// This ensures students get full credit even if they can't complete enough reviews
if ( !$USER->instructor && $is_submitted ) {
    $auto_timeout = Settings::linkGet('auto_instructor_grade_timeout', 0);
    $auto_timeout = intval($auto_timeout);
    
    // Calculate overall_points to check if auto-grading should apply
    $instructor_points = Settings::linkGet('instructorpoints', 0);
    $instructor_points = intval($instructor_points);
    $submit_points = Settings::linkGet('submitpoints', 0);
    $submit_points = intval($submit_points);
    $comment_points = Settings::linkGet('commentpoints', 0);
    $comment_points = intval($comment_points);
    $min_comments = Settings::linkGet('mincomments', 0);
    $min_comments = intval($min_comments);
    $overall_points = $instructor_points + $submit_points + ($comment_points * $min_comments);
    
    // Only check if overall_points > 0 and timeout is set
    if ( $overall_points > 0 && $auto_timeout > 0 ) {
        // Get submission timestamp
        $submission_timestamp = isset($paper_row['updated_at']) ? strtotime($paper_row['updated_at']) : null;
        
        if ( $submission_timestamp ) {
            $current_timestamp = time();
            $seconds_since_submission = $current_timestamp - $submission_timestamp;
            
            // If timeout has passed, send grade of 1.0 (100%) regardless of instructor grading
            // This ensures students get credit even if they can't complete enough reviews
            if ( $seconds_since_submission >= $auto_timeout ) {
                // Send grade of 1.0 directly to LTI
                $result = Result::lookupResultBypass($USER->id);
                $result['grade'] = -1; // Force resend
                $debug_log = array();
                $extra13 = array(
                    LTI13::ACTIVITY_PROGRESS => LTI13::ACTIVITY_PROGRESS_COMPLETED,
                    LTI13::GRADING_PROGRESS => LTI13::GRADING_PROGRESS_FULLYGRADED,
                );
                
                $LAUNCH->result->gradeSend(1.0, $result, $debug_log, $extra13);
            }
        }
    }
}

// Calculate overall_points and earned_points using shared function
$points_data = calculatePoints($USER->id, $LAUNCH->link->id, $result_id);
$earned_points = $points_data['earned_points'];
$overall_points = $points_data['overall_points'];

// Send grade to LTI whenever student views the page (if they can see points)
if ( !$USER->instructor && $overall_points > 0 ) {
    sendGradeToLTI($USER->id, $earned_points, $overall_points);
}

// Load submissions for review (only if student has submitted)
$review_submissions = array();
$review_page = 1;
$total_review_pages = 0;
if ( $is_submitted && !$USER->instructor ) {
    // Get allowall setting - if min_comments is 0, allowall is effectively true
    $allowall_setting = Settings::linkGet('allowall', false);
    $allowall = ($min_comments == 0) ? true : $allowall_setting;
    
    // Get page number
    $review_page = isset($_GET['review_page']) ? max(1, intval($_GET['review_page'])) : 1;
    $items_per_page = 10;
    $offset = ($review_page - 1) * $items_per_page;
    
    // First, get all submitted results from other students
    $all_submissions = $PDOX->allRowsDie(
        "SELECT r.result_id, r.user_id, r.created_at,
                u.displayname, u.email,
                ar.raw_submission, ar.ai_enhanced_submission, ar.json
         FROM {$p}lti_result r
         INNER JOIN {$p}lti_user u ON r.user_id = u.user_id
         INNER JOIN {$p}aipaper_result ar ON r.result_id = ar.result_id
         WHERE r.link_id = :LID 
           AND r.user_id != :MY_USER_ID
           AND ar.submitted = true",
        array(
            ':LID' => $LAUNCH->link->id,
            ':MY_USER_ID' => $USER->id
        )
    );
    
    // Count comments for each submission (by current user)
    $use_real_names = Settings::linkGet('userealnames', false);
    $submissions_with_counts = array();
    foreach ( $all_submissions as $sub_row ) {
        // Count how many comments current user has made on this submission
        $my_comment_count = $PDOX->rowDie(
            "SELECT COUNT(*) as cnt
             FROM {$p}aipaper_comment
             WHERE result_id = :RID AND user_id = :MY_USER_ID",
            array(
                ':RID' => $sub_row['result_id'],
                ':MY_USER_ID' => $USER->id
            )
        );
        $my_comments = $my_comment_count ? intval($my_comment_count['cnt']) : 0;
        
        // Filter logic:
        // - If min_comments == 0: show all submissions
        // - If reviewed_count < min_comments: only show submissions where user hasn't reached min_comments (to help reach minimum)
        //   BUT always include submissions user has already commented on (my_comments >= 1)
        // - If reviewed_count >= min_comments and allowall is checked: show all submissions
        // - If reviewed_count >= min_comments and allowall is not checked: only show submissions user has already reviewed (my_comments >= 1)
        $should_include = false;
        if ( $min_comments == 0 ) {
            $should_include = true;
        } else if ( $reviewed_count < $min_comments ) {
            // User is working toward minimum
            // Always show submissions they've already commented on, OR submissions that help them reach minimum
            $should_include = ($my_comments >= 1) || ($my_comments < $min_comments);
        } else if ( $reviewed_count >= $min_comments && $allowall ) {
            // User has met minimum and allowall is checked - show all submissions
            $should_include = true;
        } else if ( $reviewed_count >= $min_comments && !$allowall ) {
            // User has met minimum but allowall is not checked - only show submissions they've already reviewed
            $should_include = ($my_comments >= 1);
        } else {
            // Default: only show submissions where user hasn't reached min_comments
            $should_include = ($my_comments < $min_comments);
        }
        
        if ( $should_include ) {
            $submissions_with_counts[] = array(
                'result_id' => $sub_row['result_id'],
                'user_id' => $sub_row['user_id'],
                'display_name' => $use_real_names && !empty($sub_row['displayname']) 
                    ? $sub_row['displayname'] 
                    : FakeName::getName($sub_row['user_id']),
                'raw_submission' => $sub_row['raw_submission'],
                'ai_enhanced_submission' => $sub_row['ai_enhanced_submission'],
                'comment_count' => $my_comments,
                'submission_date' => $sub_row['created_at']
            );
        }
    }
    
    // Sort: submissions where current user has >= 1 comment first, then by oldest submission, then by comment count
    usort($submissions_with_counts, function($a, $b) {
        // Prioritize submissions where current user has made >= 1 comment
        $a_has_comments = $a['comment_count'] >= 1;
        $b_has_comments = $b['comment_count'] >= 1;
        
        if ( $a_has_comments && !$b_has_comments ) return -1;
        if ( !$a_has_comments && $b_has_comments ) return 1;
        
        // If both have comments or both don't, sort by oldest submission first, then by comment count
        $date_cmp = strcmp($a['submission_date'], $b['submission_date']);
        if ( $date_cmp != 0 ) return $date_cmp;
        return $a['comment_count'] - $b['comment_count'];
    });
    
    // If allowall is not checked and min_comments > 0, limit to min_comments submissions
    // But always include submissions the user has already commented on
    if ( !$allowall && $min_comments > 0 ) {
        // Separate submissions: ones they've started vs ones they haven't
        $started = array();
        $not_started = array();
        foreach ( $submissions_with_counts as $sub ) {
            if ( $sub['comment_count'] >= 1 ) {
                $started[] = $sub;
            } else {
                $not_started[] = $sub;
            }
        }
        
        // Combine: all started ones + enough not_started to reach min_comments total
        $max_to_show = max($min_comments, count($started));
        $needed_from_not_started = max(0, $max_to_show - count($started));
        $submissions_with_counts = array_merge($started, array_slice($not_started, 0, $needed_from_not_started));
    }
    
    // Paginate
    $total_review_count = count($submissions_with_counts);
    $total_review_pages = ceil($total_review_count / $items_per_page);
    $review_submissions = array_slice($submissions_with_counts, $offset, $items_per_page);
}

// Load comments for this submission (if submitted)
$comments = array();
if ( $is_submitted ) {
    $use_real_names = Settings::linkGet('userealnames', false);
    // For students, hide soft-deleted comments. For instructors, show them with indication.
    $deleted_filter = $USER->instructor ? '' : 'AND c.deleted = 0';
    $comment_rows = $PDOX->allRowsDie(
        "SELECT c.comment_id, c.comment_text, c.comment_type, c.created_at, c.user_id, c.deleted,
                c.flagged, c.flagged_by,
                u.displayname, u.email
         FROM {$p}aipaper_comment c
         LEFT JOIN {$p}lti_user u ON c.user_id = u.user_id
         WHERE c.result_id = :RID $deleted_filter
         ORDER BY c.created_at DESC",
        array(':RID' => $result_id)
    );
    
    foreach ( $comment_rows as $comment_row ) {
        $comment = array(
            'comment_id' => $comment_row['comment_id'],
            'comment_text' => $comment_row['comment_text'],
            'comment_type' => $comment_row['comment_type'],
            'created_at' => $comment_row['created_at'],
            'user_id' => $comment_row['user_id'],
            'deleted' => isset($comment_row['deleted']) && ($comment_row['deleted'] == 1 || $comment_row['deleted'] == true),
            'flagged' => isset($comment_row['flagged']) && ($comment_row['flagged'] == 1 || $comment_row['flagged'] == true)
        );
        
        // Get display name based on comment type and settings
        if ( $comment_row['comment_type'] == 'AI' ) {
            $comment['display_name'] = 'AI';
        } else if ( $comment_row['comment_type'] == 'instructor' ) {
            $comment['display_name'] = 'Staff';
        } else {
            // Student comment
            if ( $use_real_names && !empty($comment_row['displayname']) ) {
                $comment['display_name'] = $comment_row['displayname'];
            } else {
                $comment['display_name'] = FakeName::getName($comment_row['user_id']);
            }
        }
        
        $comments[] = $comment;
    }
}

// Load instructions from settings
$instructions = Settings::linkGet('instructions', '');

// Load current settings values for instructor (to check if defaults needed)
$current_submitpoints = Settings::linkGet('submitpoints', '');
$current_instructorpoints = Settings::linkGet('instructorpoints', '');
$current_commentpoints = Settings::linkGet('commentpoints', '');
$current_mincomments = Settings::linkGet('mincomments', '');
$current_userealnames = Settings::linkGet('userealnames', false);
$current_allowall = Settings::linkGet('allowall', false);
$current_resubmit = Settings::linkGet('resubmit', false);
$current_ai_api_url = Settings::linkGet('ai_api_url', '');
$current_ai_api_key = Settings::linkGet('ai_api_key', '');
$current_auto_timeout = Settings::linkGet('auto_instructor_grade_timeout', '');
// Convert seconds to days and hours for display
$auto_timeout_days = 0;
$auto_timeout_hours = 0;
if ( !empty($current_auto_timeout) && is_numeric($current_auto_timeout) ) {
    $total_seconds = intval($current_auto_timeout);
    $auto_timeout_days = floor($total_seconds / 86400); // 86400 seconds per day
    $auto_timeout_hours = floor(($total_seconds % 86400) / 3600); // 3600 seconds per hour
}

// Determine if settings need defaults (all point values empty)
$needs_defaults = empty($current_submitpoints) && empty($current_instructorpoints) && 
                 empty($current_commentpoints) && empty($current_mincomments);

// Determine if instructions will be truncated in preview (for students)
$instructions_will_be_truncated = false;
if ( !$USER->instructor && is_string($instructions) && U::strlen($instructions) > 0 ) {
    $instructions_plain = strip_tags($instructions);
    // Check if likely to be truncated: > 300 chars OR has multiple paragraphs
    if ( strlen($instructions_plain) > 300 ) {
        $instructions_will_be_truncated = true;
    } else {
        // Check for multiple paragraphs in HTML
        if ( preg_match('/<p[^>]*>.*?<\/p>/s', $instructions) ) {
            preg_match_all('/<p[^>]*>.*?<\/p>/s', $instructions, $matches);
            if ( count($matches[0]) > 1 ) {
                $instructions_will_be_truncated = true;
            }
        }
    }
}

// Load AI prompt from settings (per link)
$ai_prompt = Settings::linkGet('ai_prompt', '');

// Handle toggle comment (soft delete/un-delete)
if ( count($_POST) > 0 && isset($_POST['toggle_comment']) && $USER->instructor ) {
    $comment_id = intval($_POST['toggle_comment']);
    $new_deleted = intval($_POST['comment_deleted']);
    
    // Verify comment exists and belongs to this link
    $comment_check = $PDOX->rowDie(
        "SELECT c.comment_id 
         FROM {$p}aipaper_comment c
         INNER JOIN {$p}lti_result r ON c.result_id = r.result_id
         WHERE c.comment_id = :CID AND r.link_id = :LID",
        array(':CID' => $comment_id, ':LID' => $LAUNCH->link->id)
    );
    
    if ( $comment_check ) {
        $PDOX->queryDie(
            "UPDATE {$p}aipaper_comment 
             SET deleted = :DELETED, updated_at = NOW()
             WHERE comment_id = :CID",
            array(':DELETED' => $new_deleted, ':CID' => $comment_id)
        );
        $_SESSION['success'] = $new_deleted ? 'Comment hidden' : 'Comment shown';
    } else {
        $_SESSION['error'] = 'Comment not found';
    }
    
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Handle reset submission (check before flag to avoid interference)
if ( count($_POST) > 0 && isset($_POST['reset_submission']) ) {
    // Allow reset if instructor, or if resubmit is allowed, or if submission has been submitted
    // (Since the button only shows when submitted, this check ensures reset is allowed)
    if ( !$USER->instructor && !$resubmit_allowed && !$is_submitted ) {
        $_SESSION['error'] = 'Reset not allowed';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }
    
    // Reset submission status (keep content, just make it editable again)
    // Reset submitted column but keep all content (paper, AI enhanced, comments)
    // Soft delete all comments on this submission
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET submitted = false, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RID' => $result_id
        )
    );
    
    // Soft delete all comments on this submission (for points calculation, but hidden from students)
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_comment 
         SET deleted = 1, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RID' => $result_id
        )
    );
    
    $_SESSION['success'] = 'Submission has been reset. Your paper and AI enhanced content are now editable again.';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Handle toggle flag (anyone can flag and unflag)
if ( count($_POST) > 0 && isset($_POST['toggle_flag']) ) {
    $comment_id = intval($_POST['toggle_flag']);
    $new_flagged = intval($_POST['comment_flagged']);
    
    // Verify comment exists and belongs to this link
    $comment_check = $PDOX->rowDie(
        "SELECT c.comment_id, c.flagged, c.flagged_by
         FROM {$p}aipaper_comment c
         INNER JOIN {$p}lti_result r ON c.result_id = r.result_id
         WHERE c.comment_id = :CID AND r.link_id = :LID",
        array(':CID' => $comment_id, ':LID' => $LAUNCH->link->id)
    );
    
    if ( $comment_check ) {
        // Anyone can flag and unflag
        $flagged_by = $new_flagged == 1 ? $USER->id : null;
        $PDOX->queryDie(
            "UPDATE {$p}aipaper_comment 
             SET flagged = :FLAGGED, flagged_by = :FLAGGED_BY, updated_at = NOW()
             WHERE comment_id = :CID",
            array(
                ':FLAGGED' => $new_flagged,
                ':FLAGGED_BY' => $flagged_by,
                ':CID' => $comment_id
            )
        );
        // No flash message - visual feedback (icon color change) is sufficient
    } else {
        $_SESSION['error'] = 'Comment not found';
    }
    
    header( 'Location: '.addSession('index.php') ) ;
    return;
}
if ( count($_POST) > 0 && isset($_POST['reset_submission']) ) {
    // Allow reset if instructor, or if resubmit is allowed, or if submission has been submitted
    // (Since the button only shows when submitted, this check ensures reset is allowed)
    if ( !$USER->instructor && !$resubmit_allowed && !$is_submitted ) {
        $_SESSION['error'] = 'Reset not allowed';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }
    
    // Reset submission status (keep content, just make it editable again)
    // Reset submitted column but keep all content (paper, AI enhanced, comments)
    // Soft delete all comments on this submission
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET submitted = false, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RID' => $result_id
        )
    );
    
    // Soft delete all comments on this submission (for points calculation, but hidden from students)
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_comment 
         SET deleted = 1, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RID' => $result_id
        )
    );
    
    $_SESSION['success'] = 'Submission has been reset. Your paper and AI enhanced content are now editable again.';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Handle POST submission
if ( count($_POST) > 0 && (isset($_POST['submit_paper']) || isset($_POST['save_paper']) || isset($_POST['save_instructions'])) ) {
    $is_submit = isset($_POST['submit_paper']);
    
    if ( !$can_edit && !$USER->instructor ) {
        $_SESSION['error'] = 'Submission is locked';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }

    $raw_submission = U::get($_POST, 'raw_submission', '');
    $ai_enhanced = U::get($_POST, 'ai_enhanced_submission', '');
    
    // Validate that paper is not blank when submitting (not when saving draft)
    if ( $is_submit && !$USER->instructor && U::isEmpty($raw_submission) ) {
        $_SESSION['error'] = 'Your paper cannot be blank. Please write your paper before submitting.';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }
    
    // For instructors, save instructions, AI prompt, and all settings
    if ( $USER->instructor ) {
        $instructions = U::get($_POST, 'instructions', '');
        Settings::linkSet('instructions', $instructions);
        
        // Save AI prompt to settings
        $ai_prompt = U::get($_POST, 'ai_prompt', '');
        // If AI prompt is blank, reset to default
        if ( empty(trim(strip_tags($ai_prompt))) ) {
            // Create default prompt with HTML formatting (for CKEditor)
            $ai_prompt = "<p>You are reviewing a student's paper submission.</p>\n\n<p>Provide a brief paragraph (approximately 200 words or less) with specific, actionable feedback. Focus on:</p>\n<ul>\n<li>Strengths of the submission</li>\n<li>Areas for improvement</li>\n<li>Specific suggestions for revision</li>\n</ul>\n\n<p>Be encouraging but honest, and reference specific parts of the paper when possible.</p>\n\n<p>The following are the instructions for the assignment:</p>\n\n<p>-- Instructions Included Here --</p>\n\n";
        }
        Settings::linkSet('ai_prompt', $ai_prompt);
        
        // Save all settings fields
        Settings::linkSet('submitpoints', U::get($_POST, 'submitpoints', ''));
        Settings::linkSet('instructorpoints', U::get($_POST, 'instructorpoints', ''));
        Settings::linkSet('commentpoints', U::get($_POST, 'commentpoints', ''));
        Settings::linkSet('mincomments', U::get($_POST, 'mincomments', ''));
        Settings::linkSet('userealnames', U::get($_POST, 'userealnames', false) ? true : false);
        Settings::linkSet('allowall', U::get($_POST, 'allowall', false) ? true : false);
        Settings::linkSet('resubmit', U::get($_POST, 'resubmit', false) ? true : false);
        Settings::linkSet('ai_api_url', U::get($_POST, 'ai_api_url', ''));
        Settings::linkSet('ai_api_key', U::get($_POST, 'ai_api_key', ''));
        Settings::linkSet('auto_instructor_grade_timeout', U::get($_POST, 'auto_instructor_grade_timeout', ''));
        
        // Save due date
        $due_date = U::get($_POST, 'due_date', '');
        if ( !empty($due_date) ) {
            Settings::linkSet('due_date', $due_date);
        } else {
            Settings::linkSet('due_date', '');
        }
    }

    // Check if was already submitted (MySQL returns 0/1, not boolean)
    $was_submitted = isset($paper_row['submitted']) && ($paper_row['submitted'] == true || $paper_row['submitted'] == 1);
    
    // Mark as submitted only if Submit button was clicked (not Save)
    $new_submitted = $was_submitted;
    if ( $is_submit && !$was_submitted && U::isNotEmpty($raw_submission) ) {
        $new_submitted = true;
        $RESULT->notifyReadyToGrade();
    }

    // Update aipaper_result
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET raw_submission = :RAW, ai_enhanced_submission = :AI, 
             submitted = :SUBMITTED, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RAW' => $raw_submission,
            ':AI' => $ai_enhanced,
            ':SUBMITTED' => $new_submitted ? 1 : 0,
            ':RID' => $result_id
        )
    );
    
    // Set success message first (before AI processing)
    if ( $USER->instructor ) {
        $_SESSION['success'] = 'Instructions updated';
    } else {
        if ( $is_submit ) {
            $success_msg = 'Paper submitted';
            if ( $resubmit_allowed ) {
                $success_msg .= '. You can reset your submission if you need to make changes.';
            } else {
                $success_msg .= '. Your submission is now locked and cannot be edited.';
            }
            $_SESSION['success'] = $success_msg;
        } else {
            $_SESSION['success'] = 'Draft saved';
        }
    }

    // Generate AI comment if paper was just submitted (first time or resubmission)
    // Note: On resubmission, previous comments (including AI) are soft-deleted, so we need a new AI comment
    // Generate whenever Submit button is clicked (not Save) and paper is not empty
    if ( $is_submit && U::isNotEmpty($raw_submission) ) {
        // Use AI prompt if set, otherwise fall back to instructions
        $instructions = Settings::linkGet('instructions', '');
        $prompt_to_use = $instructions;
        $ai_prompt = Settings::linkGet('ai_prompt', '');
        if ( !empty($ai_prompt) ) {
            $prompt_to_use = $ai_prompt;
            // Replace placeholder with actual instructions
            // Use regex to find the placeholder in various HTML contexts and preserve formatting
            // Match the placeholder text regardless of surrounding HTML tags
            $placeholder_pattern = '/--\s*Instructions\s+Included\s+Here\s*--/i';
            // Find all matches and replace, preserving the HTML structure around it
            $prompt_to_use = preg_replace($placeholder_pattern, $instructions, $prompt_to_use);
        } else {
            // Fallback to instructions if no AI prompt is set
            $prompt_to_use = $instructions;
        }
        $api_info = getAIApiUrl();
        
        // Only generate AI comment if AI is configured
        if ( $api_info['configured'] ) {
            // Pass URL if available, otherwise let function use test endpoint
            $ai_result = generateAIComment($prompt_to_use, $raw_submission, $api_info['url']);
            
            if ( $ai_result['success'] ) {
                // Insert AI comment (user_id is NULL for AI comments)
                $PDOX->queryDie(
                    "INSERT INTO {$p}aipaper_comment (result_id, user_id, comment_text, comment_type, created_at)
                     VALUES (:RID, NULL, :TEXT, 'AI', NOW())",
                    array(
                        ':RID' => $result_id,
                        ':TEXT' => $ai_result['comment']
                    )
                );
                $user_id = $LAUNCH->user->id ?? 'unknown';
                $user_email = $LAUNCH->user->email ?? 'unknown';
                error_log("AI Comment: Comment successfully added to database - result_id: {$result_id}, user_id: {$user_id}, email: {$user_email}, comment_length: " . strlen($ai_result['comment']));
            } else {
                // Log error to server logs
                $user_id = $LAUNCH->user->id ?? 'unknown';
                $error_message = $ai_result['error'] ?? 'Unknown error';
                $error_log_entry = "AI Comment: Generation failed - result_id: {$result_id}, user_id: {$user_id}, error: {$error_message}";
                if ( isset($ai_result['error_log']) ) {
                    $error_log_entry .= "\nFull error log: " . $ai_result['error_log'];
                }
                error_log($error_log_entry);
                
                // Store error details for console.log (will be output in JavaScript)
                $error_for_console = array(
                    'message' => 'Unable to contact AI for review',
                    'error' => $error_message,
                    'error_log' => $ai_result['error_log'] ?? null
                );
                $_SESSION['ai_error_console'] = json_encode($error_for_console);
                
                // Show error message to user
                $_SESSION['error'] = 'Unable to contact AI for review. Check browser console for details.';
            }
        }
    }
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

$menu = new \Tsugi\UI\MenuSet();

if ( $LAUNCH->user->instructor ) {
    $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
    $menu->addLeft(__('Settings'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="settings" style="cursor: pointer;"');
    // Only show AI Prompt if AI is configured
    if ( isAIConfigured() ) {
        $menu->addLeft(__('AI Prompt'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_prompt" style="cursor: pointer;"');
    }
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Student Data'), 'grades');
    if ( $CFG->launchactivity ) {
        $submenu->addLink(__('Analytics'), 'analytics');
    }
    // Only show test data generator if key is '12345'
    $key = $LAUNCH->key->key ?? '';
    if ( $key === '12345' ) {
        $submenu->addLink(__('Generate Test Data'), 'testdata.php');
    }
    $menu->addRight(__('Save'), '#', /* push */ false, 'id="menu-save-instructor-btn" style="cursor: pointer; font-weight: bold;"');
    $menu->addRight(__('Documentation'), 'documentation.html', /* push */ false, 'target="_blank"');
    $menu->addRight(__('Instructor'), $submenu, /* push */ false);
} else {
    // Add navigation items to menu
    if ( !$is_submitted ) {
        // When not submitted: Paper, Paper+AI, Instructions (if truncated) on left; Save Draft, Submit Paper on right
        $menu->addLeft(__('Paper'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="submission" style="cursor: pointer;"');
        $menu->addLeft(__('Paper+AI'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_enhanced" style="cursor: pointer;"');
        // Only show Instructions tab if instructions will be truncated
        if ( $instructions_will_be_truncated ) {
            $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
        }
        // Show Peer Review menu item (disabled) if peer reviews are required
        if ( $min_comments > 0 ) {
            $menu->addLeft(__('Peer Review'), '#', /* push */ false, 'class="tsugi-nav-link" style="cursor: not-allowed; color: #999; opacity: 0.6;" title="Available after you submit your paper"');
        }
        // Add Save and Submit buttons to menu if student can edit
        if ( $can_edit ) {
            $menu->addRight(__('Save Draft'), '#', /* push */ false, 'id="menu-save-btn" style="cursor: pointer;"');
            $submit_text = __('Submit Paper');
            $menu->addRight($submit_text, '#', /* push */ false, 'id="menu-submit-btn" style="cursor: pointer; font-weight: bold;"');
        }
    } else {
        // When submitted: Main, Instructions (if truncated), Peer Review on left
        $menu->addLeft(__('Main'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="main" style="cursor: pointer;"');
        // Only show Instructions tab if instructions were truncated
        if ( $instructions_will_be_truncated ) {
            $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
        }
        $menu->addLeft(__('Peer Review'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="review" style="cursor: pointer;" title="Review and comment on other students\' submissions"');
    }
    
    if ( U::strlen($inst_note) > 0 ) $menu->addRight(__('Note'), '#', /* push */ false, 'data-toggle="modal" data-target="#noteModal"');
    // Add Reset Submission button if submitted and resubmit is allowed
    if ( $is_submitted && $resubmit_allowed ) {
        $menu->addRight(__('Reset Submission'), '#', /* push */ false, 'id="menu-reset-btn" style="cursor: pointer; color: #f0ad4e;"');
    }
}

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

// Student settings form (keep as modal)
if ( !$USER->instructor ) {
    SettingsForm::start();
    SettingsForm::checkbox('allowall', __('Allow students to see and comment on all submissions after the minimum has been met'));
    SettingsForm::dueDate();
    SettingsForm::done();
    SettingsForm::end();
}

if ( U::strlen($inst_note) > 0 ) {
    echo($OUTPUT->modalString(__("Instructor Note"), htmlentities($inst_note ?? ''), "noteModal"));
}

?>
<style>
.student-section {
    display: none;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 25em;
}
.student-section.active {
    display: block;
}
.ckeditor-container { min-height: 25em; }
.ckeditor-display {
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #f9f9f9;
    padding: 15px;
    min-height: 10em;
}
.ckeditor-display .ck-editor__editable {
    border: none;
    background: transparent;
    box-shadow: none;
}
.tsugi-nav-link.active {
    font-weight: bold;
    text-decoration: underline;
}
</style>

<?php if ( $USER->instructor ) { ?>
    <!-- Instructor: Tabbed interface -->
    <?php if ( $dueDate->message ) { ?>
        <p style="color:red;"><?= htmlentities($dueDate->message) ?></p>
    <?php } ?>
    
    <form method="post" id="instructor_form">
        <!-- Progress indicator -->
        <div style="margin-bottom: 20px; padding: 15px; background-color: #f0f0f0; border-radius: 4px;">
            <strong>Setup Progress:</strong> 
            <span style="color: #5cb85c;">Step 1: Instructions</span> → 
            <span style="color: <?= $needs_defaults ? '#f0ad4e' : '#5cb85c' ?>;">Step 2: Settings</span>
        </div>
        
        <!-- Instructions section -->
        <div class="student-section active" id="section-instructions">
            <h3 style="margin-top: 0;">Step 1: Assignment Instructions</h3>
            <p>Please enter the instructions for the assignment here. This will be used to generate feedback for the students.</p>
            <div class="ckeditor-container">
                <textarea name="instructions" id="editor_instructions"><?= htmlentities($instructions ?? '') ?></textarea>
            </div>
        </div>
        
        <!-- Settings section -->
        <div class="student-section" id="section-settings" style="margin-top: 30px;">
            <h3 style="margin-top: 0;">Step 2: Assignment Settings</h3>
            <p>Configure how points are awarded and other assignment options. You can use the preset options below or customize manually.</p>
            
            <!-- Assignment Type Preset Dropdown -->
            <div style="margin-bottom: 25px; padding: 15px; background-color: #e8f4f8; border-radius: 4px;">
                <label for="assignment_type_preset" style="font-weight: bold; display: block; margin-bottom: 8px;">
                    Assignment Type (Optional - select to auto-fill recommended settings):
                </label>
                <select id="assignment_type_preset" style="width: 100%; max-width: 500px; padding: 8px;">
                    <option value="">-- Select a preset (optional) --</option>
                    <option value="peer_review">Peer Review Focused</option>
                    <option value="instructor_graded">Instructor Graded</option>
                    <option value="completion">Completion-Based</option>
                    <option value="hybrid">Hybrid Peer Review + Instructor Grading</option>
                    <option value="anonymous">Anonymous Peer Review</option>
                    <option value="scaffolded">Scaffolded Peer Review</option>
                </select>
                <p style="margin-top: 8px; margin-bottom: 0; font-size: 0.9em; color: #666;">
                    <em>Selecting a preset will populate the fields below with recommended values. You can still modify them before saving.</em>
                </p>
            </div>
            
            <!-- Points Settings -->
            <div style="margin-bottom: 25px;">
                <h4 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Points Configuration</h4>
                
                <div style="margin-bottom: 15px;">
                    <label for="submitpoints" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Submit Points
                        <span class="info-icon" data-help="submitpoints" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="number" name="submitpoints" id="submitpoints" 
                           value="<?= htmlentities($current_submitpoints) ?>" 
                           placeholder=""
                           min="0" step="1" 
                           style="width: 100px; padding: 5px;">
                    <span class="help-text" id="help-submitpoints" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Points students earn for submitting their paper. Can be zero. Typical range: 10-20 points.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="instructorpoints" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Instructor Grade Points
                        <span class="info-icon" data-help="instructorpoints" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="number" name="instructorpoints" id="instructorpoints" 
                           value="<?= htmlentities($current_instructorpoints) ?>" 
                           placeholder=""
                           min="0" step="1" 
                           style="width: 100px; padding: 5px;">
                    <span class="help-text" id="help-instructorpoints" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Points you will award based on your evaluation. Can be zero. Typical range: 0-80 points depending on assignment type.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="commentpoints" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Points per Comment
                        <span class="info-icon" data-help="commentpoints" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="number" name="commentpoints" id="commentpoints" 
                           value="<?= htmlentities($current_commentpoints) ?>" 
                           placeholder=""
                           min="0" step="1" 
                           style="width: 100px; padding: 5px;">
                    <span class="help-text" id="help-commentpoints" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Points students earn for each peer review comment they make. Can be zero. If minimum comments is 0, this should also be 0. Typical range: 5-10 points.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="mincomments" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Minimum Comments Required
                        <span class="info-icon" data-help="mincomments" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="number" name="mincomments" id="mincomments" 
                           value="<?= htmlentities($current_mincomments) ?>" 
                           placeholder=""
                           min="0" step="1" 
                           style="width: 100px; padding: 5px;">
                    <span class="help-text" id="help-mincomments" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Required number of peer review comments each student must make. If zero, peer review is optional. Typical: 3-5 for classes of 20+, 2-3 for smaller classes.
                    </span>
                </div>
                
                <!-- Live Total Points Display -->
                <div style="margin-top: 20px; padding: 15px; background-color: #f9f9f9; border: 2px solid #5cb85c; border-radius: 4px;">
                    <strong>Total Points:</strong> 
                    <span id="total-points-display" style="font-size: 1.2em; font-weight: bold; color: #5cb85c;">0</span>
                    <div style="margin-top: 8px; font-size: 0.9em; color: #666;">
                        Formula: <code>Total = Instructor Points + Submit Points + (Comment Points × Min Comments)</code>
                    </div>
                    <div id="points-warning" style="margin-top: 8px; color: #d9534f; font-weight: bold; display: none;">
                        ⚠️ Warning: Total points is 0. Grades will not be sent to the LMS.
                    </div>
                </div>
            </div>
            
            <!-- Other Settings -->
            <div style="margin-bottom: 25px;">
                <h4 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Other Settings</h4>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; display: inline-block; min-width: 200px;">
                        <input type="checkbox" name="userealnames" id="userealnames" value="1" 
                               <?= $current_userealnames ? 'checked' : '' ?>>
                        Use Actual Student Names
                        <span class="info-icon" data-help="userealnames" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <span class="help-text" id="help-userealnames" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        When unchecked, students see generated names (e.g., "Purple Elephant") for anonymity. Instructors always see real names.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; display: inline-block; min-width: 200px;">
                        <input type="checkbox" name="allowall" id="allowall" value="1" 
                               <?= $current_allowall ? 'checked' : '' ?>>
                        Allow students to see all submissions after minimum is met
                        <span class="info-icon" data-help="allowall" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <span class="help-text" id="help-allowall" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        When checked, students who meet the minimum comment requirement can see and comment on all submissions. When unchecked, they only see submissions they've already reviewed.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; display: inline-block; min-width: 200px;">
                        <input type="checkbox" name="resubmit" id="resubmit" value="1" 
                               <?= $current_resubmit ? 'checked' : '' ?>>
                        Allow Students to Reset and Resubmit
                        <span class="info-icon" data-help="resubmit" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <span class="help-text" id="help-resubmit" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        When enabled, students can reset their submission to make it editable again. Comments are hidden but still count for points.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Auto Grade Timeout
                        <span class="info-icon" data-help="auto_timeout" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <div style="display: inline-block;">
                        <select id="auto_timeout_days" style="width: 100px; padding: 5px; margin-right: 5px;">
                            <option value="0">0 days</option>
                            <?php for ($i = 1; $i <= 30; $i++): ?>
                                <option value="<?= $i ?>" <?= $auto_timeout_days == $i ? 'selected' : '' ?>><?= $i ?> day<?= $i == 1 ? '' : 's' ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="auto_timeout_hours" style="width: 100px; padding: 5px;">
                            <option value="0">0 hours</option>
                            <?php for ($i = 1; $i <= 23; $i++): ?>
                                <option value="<?= $i ?>" <?= $auto_timeout_hours == $i ? 'selected' : '' ?>><?= $i ?> hour<?= $i == 1 ? '' : 's' ?></option>
                            <?php endfor; ?>
                        </select>
                        <input type="hidden" name="auto_instructor_grade_timeout" id="auto_instructor_grade_timeout" value="<?= htmlentities($current_auto_timeout) ?>">
                        <span id="auto_timeout_display" style="margin-left: 10px; color: #666; font-size: 0.9em;"></span>
                    </div>
                    <span class="help-text" id="help-auto_timeout" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em; clear: both; display: block; margin-top: 5px;">
                        Automatically send grade of 100% after this time since submission. Ensures students get credit even if they can't complete reviews or if the instructor does not do their grading. Typically left at 0 days and 0 hours exept for courses with little regular instructor supervision.
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="ai_api_url" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        AI API URL (optional)
                        <span class="info-icon" data-help="ai_api_url" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="text" name="ai_api_url" id="ai_api_url" 
                           value="<?= htmlentities($current_ai_api_url) ?>" 
                           placeholder="https://api.openai.com/v1/chat/completions"
                           style="width: 400px; padding: 5px;">
                    <span class="help-text" id="help-ai_api_url" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Endpoint URL for generating AI comments. Leave empty to use test endpoint. For OpenAI, use: https://api.openai.com/v1/chat/completions
                    </span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="ai_api_key" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        AI API Key (optional)
                        <span class="info-icon" data-help="ai_api_key" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="password" name="ai_api_key" id="ai_api_key" 
                           value="<?= htmlentities($current_ai_api_key) ?>" 
                           placeholder="Your API key"
                           style="width: 300px; padding: 5px;">
                    <span class="help-text" id="help-ai_api_key" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Authentication key for the AI API. Required if using an external AI service like OpenAI.
                    </span>
                </div>
                
                <?php
                // Due date handling - need to integrate SettingsForm::dueDate() functionality
                $due_date = Settings::linkGet('due_date', '');
                ?>
                <div style="margin-bottom: 15px;">
                    <label for="due_date" style="font-weight: bold; display: inline-block; min-width: 200px;">
                        Due Date (optional)
                        <span class="info-icon" data-help="due_date" style="cursor: help; color: #337ab7; margin-left: 5px;">ℹ️</span>
                    </label>
                    <input type="datetime-local" name="due_date" id="due_date" 
                           value="<?= $due_date ? date('Y-m-d\TH:i', strtotime($due_date)) : '' ?>" 
                           style="padding: 5px;">
                    <span class="help-text" id="help-due_date" style="display: none; margin-left: 10px; color: #666; font-size: 0.9em;">
                        Optional due date for the assignment. Students will see a warning if the due date has passed.
                    </span>
                </div>
            </div>
            
            <!-- LMS Integration Info -->
            <div style="margin-top: 25px; padding: 15px; background-color: #e8f4f8; border-left: 4px solid #337ab7; border-radius: 4px;">
                <strong>About LMS Grade Integration:</strong>
                <p style="margin-top: 8px; margin-bottom: 0; font-size: 0.9em;">
                    The tool sends grades to your LMS as a percentage (0.0 to 1.0). For example, if a student earns 7 out of 20 points, 
                    the tool sends 0.35 (35%) to the LMS. The LMS then scales this percentage based on the point value you set in the LMS assignment.
                    If your LMS assignment is worth 100 points, 0.35 becomes 35 points in the LMS gradebook.
                </p>
            </div>
        </div>
        
        <!-- AI Prompt section (only shown if AI is configured) -->
        <?php if ( isAIConfigured() ) { ?>
        <div class="student-section" id="section-ai_prompt">
            <h3 style="margin-top: 0;">AI Prompt Configuration (Optional)</h3>
            <?php 
            // Get or create default AI prompt (use HTML formatting for CKEditor)
            if ( empty($ai_prompt) ) {
                // Default prompt format with placeholder (HTML formatted)
                $ai_prompt = "<p>You are reviewing a student's paper submission.</p>\n\n<p>Provide a brief paragraph (approximately 200 words or less) with specific, actionable feedback. Focus on:</p>\n<ul>\n<li>Strengths of the submission</li>\n<li>Areas for improvement</li>\n<li>Specific suggestions for revision</li>\n</ul>\n\n<p>Be encouraging but honest, and reference specific parts of the paper when possible.</p>\n\n<p>The following are the instructions for the assignment:</p>\n\n<p>-- Instructions Included Here --</p>\n\n";
            }
            ?>
            <p><em>This prompt is sent to the AI service when generating feedback comments. The text shown below is the default prompt. Feel free to improve it. Use <strong>-- Instructions Included Here --</strong> as a placeholder where you want the assignment instructions to be inserted automatically.</em></p>
            <div class="ckeditor-container">
                <textarea name="ai_prompt" id="editor_ai_prompt"><?= htmlentities($ai_prompt) ?></textarea>
            </div>
        </div>
        <?php } ?>
        
        <!-- Save button in content area -->
        <div id="instructor-save-section" style="margin-top: 30px; padding: 20px; background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <button type="button" id="content-save-instructor-btn" class="btn btn-primary" style="font-weight: bold; padding: 10px 20px;">
                <span id="save-button-text">Save All Settings</span>
            </button>
            <p id="save-button-help" style="margin-top: 10px; margin-bottom: 0; font-size: 0.9em; color: #666;">
                <em>Saves both instructions and settings. Make sure to configure settings before students begin submitting.</em>
            </p>
        </div>
        
        <!-- Hidden submit button for form submission -->
        <input type="submit" name="save_instructions" id="hidden-save-instructor-btn" style="display: none;">
    </form>
<?php } else { ?>
    <!-- Student: Sections with Tsugi menu navigation -->
    <form method="post" id="paper_form">
    <!-- Show instructions prominently at top if not submitted -->
    <?php if ( !$is_submitted && is_string($instructions) && U::strlen($instructions) > 0 ) { ?>
        <div class="alert alert-success" style="margin-bottom: 20px; border-left: 4px solid #5cb85c;">
            <h4 style="margin-top: 0;">Assignment Instructions</h4>
            <div id="instructions-preview"><?= htmlentities($instructions) ?></div>
            <?php if ( $instructions_will_be_truncated ) { ?>
                <p style="margin-top: 10px; margin-bottom: 0;" id="instructions-view-full-link">
                    <a href="#" class="tsugi-nav-link" data-section="instructions" style="font-weight: bold;">View full instructions →</a>
                </p>
            <?php } ?>
        </div>
        <?php if ( $min_comments > 0 ) { ?>
            <div class="alert alert-warning" style="margin-bottom: 20px; border-left: 4px solid #f0ad4e;">
                <h4 style="margin-top: 0;">📝 Peer Review Required</h4>
                <p style="margin-bottom: 0;"><strong>After you submit your paper, you'll need to complete <?= $min_comments ?> peer review<?= $min_comments == 1 ? '' : 's' ?>.</strong> This is part of the assignment. The "Peer Review" tab will become available after submission.</p>
            </div>
        <?php } ?>
    <?php } ?>
    <div class="student-section <?= $is_submitted ? 'active' : '' ?>" id="section-main">
        <?php if ( $dueDate->message ) { ?>
            <p style="color:red;"><?= htmlentities($dueDate->message) ?></p>
        <?php } ?>
        <?php if ( $is_submitted ) { ?>
            <div style="margin-top: 20px;">
                <p><strong>Review count:</strong> 
                <?php if ( $min_comments == 0 ) { ?>
                    <?= $reviewed_count ?>
                <?php } else if ( $reviewed_count < $min_comments ) { ?>
                    <?= $reviewed_count ?>/<?= $min_comments ?>
                <?php } else { ?>
                    <?= $reviewed_count ?>
                <?php } ?>
                </p>
                <?php if ( $overall_points > 0 ) { ?>
                    <p><strong>Points:</strong> <?= $earned_points ?>/<?= $overall_points ?></p>
                <?php } ?>
            </div>
            
            <div style="margin-top: 30px;">
                <h4>
                    Your Paper
                    <button type="button" class="btn btn-sm btn-default toggle-paper" data-target="paper-content" style="margin-left: 10px;">
                        <span class="toggle-text">Show</span>
                    </button>
                </h4>
                <div id="paper-content-wrapper" style="display: none; margin-top: 10px;">
                    <div id="paper-content" class="ckeditor-display"></div>
                </div>
            </div>
            
            <?php if ( U::isNotEmpty($paper_row['ai_enhanced_submission'] ?? '') ) { ?>
                <div style="margin-top: 30px;">
                    <h4>
                        Paper+AI
                        <button type="button" class="btn btn-sm btn-default toggle-paper" data-target="ai-content" style="margin-left: 10px;">
                            <span class="toggle-text">Show</span>
                        </button>
                    </h4>
                    <div id="ai-content-wrapper" style="display: none; margin-top: 10px;">
                        <div id="ai-content" class="ckeditor-display"></div>
                    </div>
                </div>
            <?php } ?>
            
            <?php if ( $min_comments > 0 && $reviewed_count < $min_comments ) { ?>
                <div class="alert alert-warning" style="margin-top: 30px;">
                    <h4 style="margin-top: 0;">Next Step: Complete Peer Reviews</h4>
                    <p>You have completed <?= $reviewed_count ?> of <?= $min_comments ?> required peer review<?= $min_comments == 1 ? '' : 's' ?>.</p>
                    <p style="margin-bottom: 0;">
                        <a href="#" class="tsugi-nav-link btn btn-primary" data-section="review">Go to Peer Review →</a>
                    </p>
                </div>
            <?php } ?>
            
            <?php if ( count($comments) > 0 ) { ?>
                <div style="margin-top: 40px;">
                    <h4>Comments</h4>
                    <div class="comments-section" style="margin-top: 15px;">
                        <?php foreach ( $comments as $comment ) { 
                            $badge_class = '';
                            $badge_text = '';
                            if ( $comment['comment_type'] == 'AI' ) {
                                $badge_class = 'label-info';
                                $badge_text = 'AI';
                            } else if ( $comment['comment_type'] == 'instructor' ) {
                                $badge_class = 'label-primary';
                                $badge_text = 'Staff';
                            } else {
                                $badge_class = 'label-default';
                                $badge_text = 'Student';
                            }
                            
                            $comment_date = new DateTime($comment['created_at']);
                            $formatted_date = $comment_date->format('M j, Y g:i A');
                        ?>
                            <div class="comment-item" id="comment-<?= $comment['comment_id'] ?>" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background-color: <?= isset($comment['deleted']) && $comment['deleted'] ? '#ffe6e6' : '#f9f9f9' ?>;">
                                <div style="margin-bottom: 10px;">
                                    <span class="label <?= $badge_class ?>" style="margin-right: 8px;"><?= htmlentities($badge_text) ?></span>
                                    <strong><?= htmlentities($comment['display_name']) ?></strong>
                                    <span style="color: #666; font-size: 0.9em; margin-left: 10px;"><?= htmlentities($formatted_date) ?></span>
                                    <?php if ( isset($comment['deleted']) && $comment['deleted'] ) { ?>
                                        <span class="label label-warning" style="margin-left: 10px;">Soft Deleted</span>
                                    <?php } ?>
                                    <?php if ( $USER->instructor ) { ?>
                                        <?php 
                                        $trash_color = isset($comment['deleted']) && $comment['deleted'] ? '#d9534f' : '#999';
                                        $trash_size = isset($comment['deleted']) && $comment['deleted'] ? '18px' : '16px';
                                        $trash_style = isset($comment['deleted']) && $comment['deleted'] ? 'font-weight: bold; border: 1px solid #d9534f; border-radius: 3px; padding: 2px;' : '';
                                        $trash_alt = isset($comment['deleted']) && $comment['deleted'] ? 'Comment is hidden (click to show)' : 'Comment is visible (click to hide)';
                                        ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('<?= isset($comment['deleted']) && $comment['deleted'] ? 'Show' : 'Hide' ?> this comment?');">
                                            <input type="hidden" name="toggle_comment" value="<?= $comment['comment_id'] ?>">
                                            <input type="hidden" name="comment_deleted" value="<?= isset($comment['deleted']) && $comment['deleted'] ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-xs" style="background: none; border: none; padding: 0; margin: 0 5px;" aria-label="<?= htmlentities($trash_alt) ?>" title="<?= htmlentities($trash_alt) ?>">
                                                <span class="glyphicon glyphicon-trash" style="color: <?= $trash_color ?>; font-size: <?= $trash_size ?>; <?= $trash_style ?>"></span>
                                            </button>
                                        </form>
                                    <?php } ?>
                                    <!-- Flag/unflag button (anyone can flag and unflag) -->
                                    <?php 
                                    $is_flagged = isset($comment['flagged']) && $comment['flagged'];
                                    $flag_color = $is_flagged ? '#d9534f' : '#999';
                                    $flag_size = $is_flagged ? '18px' : '16px';
                                    $flag_style = $is_flagged ? 'font-weight: bold; border: 1px solid #d9534f; border-radius: 3px; padding: 2px;' : '';
                                    $flag_alt = $is_flagged ? 'Comment is flagged (click to unflag)' : 'Comment is not flagged (click to flag)';
                                    ?>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?= $is_flagged ? 'Unflag' : 'Flag' ?> this comment?');">
                                        <input type="hidden" name="toggle_flag" value="<?= $comment['comment_id'] ?>">
                                        <input type="hidden" name="comment_flagged" value="<?= $is_flagged ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-xs" style="background: none; border: none; padding: 0; margin: 0 5px;" aria-label="<?= htmlentities($flag_alt) ?>" title="<?= htmlentities($flag_alt) ?>">
                                            <span class="glyphicon glyphicon-flag" style="color: <?= $flag_color ?>; font-size: <?= $flag_size ?>; <?= $flag_style ?>"></span>
                                        </button>
                                    </form>
                                </div>
                                <div class="comment-text" style="line-height: 1.6;">
                                    <div class="comment-html-<?= $comment['comment_id'] ?>"></div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-info" style="margin-top: 20px;">
                <strong>Status:</strong> Your paper has not been submitted yet. Use the <strong>Paper</strong> section to write your original work, and optionally use <strong>Paper+AI</strong> if you used AI to enhance your paper.
            </div>
        <?php } ?>
    </div>
    
    <?php if ( $instructions_will_be_truncated ) { ?>
    <div class="student-section" id="section-instructions">
        <?php if ( is_string($instructions) && U::strlen($instructions) > 0 ) { ?>
            <div class="ckeditor-container">
                <div id="display_instructions"><?= htmlentities($instructions ?? 'Instructions not yet available') ?></div>
            </div>
            <?php if ( $min_comments > 0 && !$is_submitted ) { ?>
                <div class="alert alert-warning" style="margin-top: 20px; border-left: 4px solid #f0ad4e;">
                    <h4 style="margin-top: 0;">📝 Important: Peer Review Required</h4>
                    <p style="margin-bottom: 8px;"><strong>After you submit your paper, you'll need to complete <?= $min_comments ?> peer review<?= $min_comments == 1 ? '' : 's' ?>.</strong></p>
                    <p style="margin-bottom: 0;">Peer review is part of this assignment. Once you submit your paper, the "Peer Review" tab will become available in the menu, where you can review and comment on other students' submissions.</p>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-info">Instructions not yet available</div>
        <?php } ?>
    </div>
    <?php } ?>
    
    <div class="student-section <?= !$is_submitted ? 'active' : '' ?>" id="section-submission">
        <h4 style="margin-top: 0;">Paper (My Original Work)</h4>
        <div class="alert alert-info" style="margin-bottom: 15px;">
            <p><strong>Write your original paper here.</strong> You may use AI tools for research and brainstorming, but the content you submit should be your own original writing. Minor spelling and grammar errors are acceptable. If you used AI to generate, rewrite, or significantly enhance any portion of your paper, please include that AI-enhanced version in the <strong>Paper+AI</strong> section below.<?php if ( $min_comments > 0 ) { ?> <strong>After submitting:</strong> You'll need to complete <?= $min_comments ?> peer review<?= $min_comments == 1 ? '' : 's' ?>.<?php } ?></p>
        </div>
        <?php if ( $min_comments > 0 ) { ?>
            <p style="color: #666; font-size: 0.9em; margin-bottom: 15px;">
                <em>Note: After you submit your paper, you'll be able to review other students' submissions. This is part of the assignment.  Sometimes if you are one of the first submitters, you may need to wait some time for other students to submit their papers before you can review them.</em>
            </p>
        <?php } ?>
        <?php if ( !$can_edit ) { ?>
            <div class="alert alert-info">Your submission has been submitted and cannot be edited.</div>
            <div class="ckeditor-container">
                <div id="display_submission"><?= htmlentities($paper_row['raw_submission'] ?? '') ?></div>
            </div>
        <?php } else { ?>
            <div class="ckeditor-container">
                <textarea name="raw_submission" id="editor_submission"><?= htmlentities($paper_row['raw_submission'] ?? '') ?></textarea>
            </div>
        <?php } ?>
    </div>
    
    <div class="student-section" id="section-ai_enhanced">
        <h4 style="margin-top: 0;">Paper+AI (Optional)</h4>
        <div class="alert alert-warning" style="margin-bottom: 15px;">
            <p><strong>If you used AI to generate, rewrite, or significantly enhance any portion of your paper,</strong> please include that AI-enhanced version here. This is optional—only include it if you used AI assistance beyond research and brainstorming.</p>
            <p style="margin-bottom: 0;">Reviewers and graders will evaluate both versions. AI feedback (if configured) will be generated on your original paper above.</p>
        </div>
        <?php if ( !$can_edit ) { ?>
            <div class="alert alert-info">Your AI enhanced submission cannot be edited.</div>
            <div class="ckeditor-container">
                <div id="display_ai_enhanced"><?= htmlentities($paper_row['ai_enhanced_submission'] ?? '') ?></div>
            </div>
        <?php } else { ?>
            <div class="ckeditor-container">
                <textarea name="ai_enhanced_submission" id="editor_ai_enhanced"><?= htmlentities($paper_row['ai_enhanced_submission'] ?? '') ?></textarea>
            </div>
            <p><em>This field is optional. You can enhance your submission using AI tools.</em></p>
        <?php } ?>
    </div>
    
    <?php if ( $is_submitted ) { ?>
    <div class="student-section" id="section-review">
        <h4 style="margin-top: 0;">Peer Review</h4>
        <div class="alert alert-info" style="margin-bottom: 20px;">
            <p><strong>Review and comment on other students' submissions.</strong> This is part of the assignment.  Sometimes if you are one of the first submitters, you may need to wait some time for other students to submit their papers before you can review them.</p>
            <p style="margin-bottom: 0;"><strong>Your progress:</strong> 
            <?php if ( $min_comments == 0 ) { ?>
                <?= $reviewed_count ?> review<?= $reviewed_count == 1 ? '' : 's' ?> completed
            <?php } else if ( $reviewed_count < $min_comments ) { ?>
                <?= $reviewed_count ?> of <?= $min_comments ?> required review<?= $min_comments == 1 ? '' : 's' ?> completed
            <?php } else { ?>
                ✓ <?= $reviewed_count ?> review<?= $reviewed_count == 1 ? '' : 's' ?> completed (minimum met)
            <?php } ?>
            </p>
        </div>
        <p>Submissions are sorted by oldest first, prioritizing those that need more comments.</p>
        
        <?php if ( count($review_submissions) > 0 ) { ?>
            <div class="review-list" style="margin-top: 20px;">
                <?php foreach ( $review_submissions as $sub ) { 
                    $sub_date = new DateTime($sub['submission_date']);
                    $formatted_sub_date = $sub_date->format('M j, Y g:i A');
                ?>
                    <div class="review-item" style="margin-bottom: 15px; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?= htmlentities($sub['display_name']) ?></strong>
                            <span style="color: #666; font-size: 0.9em; margin-left: 15px;">Submitted: <?= htmlentities($formatted_sub_date) ?></span>
                            <?php if ( $sub['comment_count'] > 0 ) { ?>
                                <span style="color: #5cb85c; font-size: 0.9em; margin-left: 15px; font-weight: bold;">
                                    ✓ You have <?= $sub['comment_count'] ?> comment<?= $sub['comment_count'] == 1 ? '' : 's' ?>
                                </span>
                            <?php } ?>
                        </div>
                        <div>
                            <a href="review.php?result_id=<?= $sub['result_id'] ?>&review_page=<?= $review_page ?>" class="btn btn-primary btn-sm">
                                <?= $sub['comment_count'] > 0 ? 'Review Again' : 'Review' ?>
                            </a>
                        </div>
                    </div>
                <?php } ?>
            </div>
            
            <?php if ( $total_review_pages > 1 ) { ?>
                <div class="pagination" style="margin-top: 20px; text-align: center;">
                    <?php if ( $review_page > 1 ) { ?>
                        <a href="<?= addSession('index.php?review_page=' . ($review_page - 1)) ?>" class="btn btn-default">Previous</a>
                    <?php } ?>
                    <span style="margin: 0 15px;">
                        Page <?= $review_page ?> of <?= $total_review_pages ?>
                    </span>
                    <?php if ( $review_page < $total_review_pages ) { ?>
                        <a href="<?= addSession('index.php?review_page=' . ($review_page + 1)) ?>" class="btn btn-default">Next</a>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-info" style="margin-top: 20px;">
                <p>No submissions available for review at this time, or you have already reviewed all available submissions.</p>
            </div>
        <?php } ?>
    </div>
    <?php } ?>
    
    <?php if ( $can_edit ) { ?>
        <!-- Action buttons in content area -->
        <div style="margin-top: 30px; padding: 20px; background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <p style="margin-bottom: 15px;"><strong>Save your work:</strong></p>
            <button type="button" id="content-save-btn" class="btn btn-default" style="margin-right: 10px;">
                Save Draft
            </button>
            <button type="button" id="content-submit-btn" class="btn btn-primary" style="font-weight: bold;">
                Submit Paper
            </button>
            <p style="margin-top: 10px; margin-bottom: 0; font-size: 0.9em; color: #666;">
                <em>Save Draft saves both your Paper and Paper+AI content. Once submitted, your paper will be locked unless your instructor allows resubmission.</em>
            </p>
        </div>
        <!-- Hidden submit buttons for form submission -->
        <input type="submit" name="save_paper" id="hidden-save-btn" style="display: none;">
        <input type="submit" name="submit_paper" id="hidden-submit-btn" style="display: none;">
    <?php } ?>
    <!-- Hidden submit button for reset -->
    <input type="submit" name="reset_submission" id="hidden-reset-btn" style="display: none;">
    </form>
    
    <!-- Spinner overlay for AI submission -->
    <div id="submit-spinner-overlay">
        <div class="spinner-container">
            <div class="spinner-border" role="status">
                <span class="sr-only">Loading...</span>
            </div>
            <p style="margin-top: 20px; font-size: 16px; color: #333;">Submitting paper and generating AI feedback...</p>
        </div>
    </div>
<?php } ?>

<?php
$OUTPUT->footerStart();
?>
<style>
#submit-spinner-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    flex-direction: column;
}

#submit-spinner-overlay .spinner-container {
    background-color: white;
    padding: 30px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
}

#submit-spinner-overlay .spinner-border {
    width: 3rem;
    height: 3rem;
    border: 0.3em solid #337ab7;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spinner-border 0.75s linear infinite;
    display: inline-block;
}

@keyframes spinner-border {
    to {
        transform: rotate(360deg);
    }
}
</style>
<script src="https://cdn.jsdelivr.net/gh/jitbit/HtmlSanitizer@master/HtmlSanitizer.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/16.0.0/classic/ckeditor.js"></script>
<script type="text/javascript">

ClassicEditor.defaultConfig = {
    toolbar: {
        items: [
            'heading',
            '|',
            'bold',
            'italic',
            'link',
            'bulletedList',
            'numberedList',
            'blockQuote',
            'insertTable',
            'mediaEmbed',
            'undo',
            'redo'
        ]
    }
};

var editors = {};
var spinnerTimeout = null;

// Helper function to show spinner overlay after 1 second delay
function showSpinnerOverlay() {
    // Clear any existing timeout
    if ( spinnerTimeout ) {
        clearTimeout(spinnerTimeout);
    }
    // Show spinner after 1 second (in case AI responds quickly)
    spinnerTimeout = setTimeout(function() {
        $('#submit-spinner-overlay').css('display', 'flex');
    }, 1000);
}

if (typeof jQuery === 'undefined' && typeof $ === 'undefined') {
    console.error('ERROR: jQuery is not loaded!');
    // Try loading jQuery
    var script = document.createElement('script');
    script.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
    document.head.appendChild(script);
    script.onload = function() {
        initializeNavigation();
    };
} else {
    $(document).ready( function () {
        initializeNavigation();
    });
}

function initializeNavigation() {
    // Log AI error to console if present
    <?php if ( isset($_SESSION['ai_error_console']) ) { 
        $error_data = json_decode($_SESSION['ai_error_console'], true);
        if ( $error_data ) {
            $error_json = json_encode($error_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
        try {
            var aiError = <?= $error_json ?>;
            console.error('AI Comment Generation Failed:', aiError.message);
            console.error('Error:', aiError.error);
            if ( aiError.error_log ) {
                console.error('Error Log:', aiError.error_log);
            }
        } catch (e) {
            console.error('Error parsing AI error details:', e);
        }
    <?php 
        }
        // Clear the session variable so it doesn't show again on refresh
        unset($_SESSION['ai_error_console']);
    } ?>
    <?php if ( $USER->instructor ) { ?>
        // Instructor: Instructions editor
        ClassicEditor
            .create( document.querySelector( '#editor_instructions' ), ClassicEditor.defaultConfig )
            .then(editor => {
                editors['instructions'] = editor;
            })
            .catch( error => {
                console.error( error );
            });
        // Instructor: AI Prompt editor - will be initialized lazily when tab is clicked
        
        // Assignment Type Presets (all total 100 points)
        var assignmentPresets = {
            'peer_review': {
                submitpoints: 20,
                instructorpoints: 0,
                commentpoints: 10,
                mincomments: 8,  // 20 + 0 + (10 × 8) = 100
                userealnames: false,
                allowall: true,
                resubmit: false
            },
            'instructor_graded': {
                submitpoints: 20,
                instructorpoints: 80,
                commentpoints: 0,
                mincomments: 0,  // 20 + 80 + 0 = 100
                userealnames: true,
                allowall: true,
                resubmit: false
            },
            'completion': {
                submitpoints: 100,
                instructorpoints: 0,
                commentpoints: 0,
                mincomments: 0,  // 100 + 0 + 0 = 100
                userealnames: true,
                allowall: true,
                resubmit: true
            },
            'hybrid': {
                submitpoints: 10,
                instructorpoints: 50,
                commentpoints: 10,
                mincomments: 4,  // 10 + 50 + (10 × 4) = 100
                userealnames: true,
                allowall: true,
                resubmit: false
            },
            'anonymous': {
                submitpoints: 20,
                instructorpoints: 20,
                commentpoints: 10,
                mincomments: 6,  // 20 + 20 + (10 × 6) = 100
                userealnames: false,
                allowall: true,
                resubmit: false
            },
            'scaffolded': {
                submitpoints: 20,
                instructorpoints: 30,
                commentpoints: 10,
                mincomments: 5,  // 20 + 30 + (10 × 5) = 100
                userealnames: true,
                allowall: true,
                resubmit: true
            }
        };
        
        $('#assignment_type_preset').on('change', function() {
            var preset = $(this).val();
            if ( preset && assignmentPresets[preset] ) {
                var values = assignmentPresets[preset];
                $('#submitpoints').val(values.submitpoints);
                $('#instructorpoints').val(values.instructorpoints);
                $('#commentpoints').val(values.commentpoints);
                $('#mincomments').val(values.mincomments);
                $('#userealnames').prop('checked', values.userealnames);
                $('#allowall').prop('checked', values.allowall);
                $('#resubmit').prop('checked', values.resubmit);
                // Trigger calculation update
                calculateTotalPoints();
            }
        });
        
        // Live Total Points Calculation
        function calculateTotalPoints() {
            var instructorPoints = parseInt($('#instructorpoints').val() || 0);
            var submitPoints = parseInt($('#submitpoints').val() || 0);
            var commentPoints = parseInt($('#commentpoints').val() || 0);
            var minComments = parseInt($('#mincomments').val() || 0);
            
            var total = instructorPoints + submitPoints + (commentPoints * minComments);
            $('#total-points-display').text(total);
            
            // Show warning if total is 0
            if ( total === 0 ) {
                $('#points-warning').show();
            } else {
                $('#points-warning').hide();
            }
        }
        
        // Attach calculation to point input fields
        $('#submitpoints, #instructorpoints, #commentpoints, #mincomments').on('input change', function() {
            calculateTotalPoints();
        });
        
        // Initial calculation
        calculateTotalPoints();
        
        // Auto Grade Timeout: Calculate seconds from days and hours
        function calculateAutoTimeout() {
            var days = parseInt($('#auto_timeout_days').val() || 0);
            var hours = parseInt($('#auto_timeout_hours').val() || 0);
            var totalSeconds = (days * 86400) + (hours * 3600);
            
            // Update hidden field
            $('#auto_instructor_grade_timeout').val(totalSeconds);
            
            // Update display
            if ( totalSeconds === 0 ) {
                $('#auto_timeout_display').text('(No timeout)');
            } else {
                var displayText = '';
                if ( days > 0 ) {
                    displayText += days + ' day' + (days === 1 ? '' : 's');
                }
                if ( hours > 0 ) {
                    if ( displayText ) displayText += ' and ';
                    displayText += hours + ' hour' + (hours === 1 ? '' : 's');
                }
                $('#auto_timeout_display').text('(' + displayText + ' = ' + totalSeconds.toLocaleString() + ' seconds)');
            }
        }
        
        // Attach calculation to dropdowns
        $('#auto_timeout_days, #auto_timeout_hours').on('change', function() {
            calculateAutoTimeout();
        });
        
        // Initial calculation
        calculateAutoTimeout();
        
        // Info Icon Help Text Toggle
        $('.info-icon').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var helpId = 'help-' + $(this).data('help');
            var $helpText = $('#' + helpId);
            if ( $helpText.length ) {
                $helpText.toggle();
            }
        });
        
        // Content area Save button handler
        $('#content-save-instructor-btn').on('click', function(e) {
            e.preventDefault();
            // Update editor content before submitting
            if ( editors['instructions'] ) {
                $('#editor_instructions').val(editors['instructions'].getData());
            }
            if ( editors['ai_prompt'] ) {
                $('#editor_ai_prompt').val(editors['ai_prompt'].getData());
            }
            // Trigger the hidden submit button
            $('#hidden-save-instructor-btn').click();
        });
        
        // Keep menu Save button working for backward compatibility
        $('#menu-save-instructor-btn').on('click', function(e) {
            e.preventDefault();
            $('#content-save-instructor-btn').click();
        });
        
        // Update save button text based on active section
        function updateSaveButtonText() {
            var activeSection = $('.student-section.active').attr('id');
            var buttonText = $('#save-button-text');
            var helpText = $('#save-button-help');
            
            if ( activeSection === 'section-instructions' ) {
                buttonText.text('Save Instructions');
                helpText.html('<em>Saves the assignment instructions.</em>');
            } else if ( activeSection === 'section-settings' ) {
                buttonText.text('Save Settings');
                helpText.html('<em>Saves all assignment settings including points, peer review requirements, and other options.</em>');
            } else if ( activeSection === 'section-ai_prompt' ) {
                buttonText.text('Save AI Prompt');
                helpText.html('<em>Saves the AI prompt configuration.</em>');
            } else {
                buttonText.text('Save All Settings');
                helpText.html('<em>Saves both instructions and settings. Make sure to configure settings before students begin submitting.</em>');
            }
        }
        
        // Update button text when section changes
        $(document).on('click', '.tsugi-nav-link', function() {
            setTimeout(function() {
                updateSaveButtonText();
            }, 100);
        });
        
        // Initial button text update
        updateSaveButtonText();
    <?php } else { ?>
        // Student: Submission and AI Enhanced editors (if editable)
        <?php if ( $can_edit ) { ?>
            ClassicEditor
                .create( document.querySelector( '#editor_submission' ), ClassicEditor.defaultConfig )
                .then(editor => {
                    editors['submission'] = editor;
                })
                .catch( error => {
                    console.error( error );
                });

            ClassicEditor
                .create( document.querySelector( '#editor_ai_enhanced' ), ClassicEditor.defaultConfig )
                .then(editor => {
                    editors['ai_enhanced'] = editor;
                })
                .catch( error => {
                    console.error( error );
                });
        <?php } else { ?>
            // Display mode - sanitize HTML
            var submissionHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($paper_row['raw_submission'] ?? '') ?>);
            $('#display_submission').html(submissionHtml);
            
            var aiHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($paper_row['ai_enhanced_submission'] ?? '') ?>);
            $('#display_ai_enhanced').html(aiHtml);
        <?php } ?>
        
        // Sanitize and display comment HTML
        <?php if ( $is_submitted && count($comments) > 0 ) { ?>
            <?php foreach ( $comments as $comment ) { ?>
                var commentHtml<?= $comment['comment_id'] ?> = HtmlSanitizer.SanitizeHtml(<?= json_encode($comment['comment_text']) ?>);
                $('.comment-html-<?= $comment['comment_id'] ?>').html(commentHtml<?= $comment['comment_id'] ?>);
            <?php } ?>
        <?php } ?>
        
        // Initialize readonly CKEditor for submitted papers
        <?php if ( $is_submitted ) { ?>
            var paperHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($paper_row['raw_submission'] ?? '') ?>);
            ClassicEditor
                .create( document.querySelector( '#paper-content' ), {
                    ...ClassicEditor.defaultConfig,
                    toolbar: { items: [] }, // No toolbar
                    isReadOnly: true
                } )
                .then(editor => {
                    editor.setData(paperHtml);
                    editors['paper-display'] = editor;
                })
                .catch( error => {
                    console.error( error );
                    // Fallback to plain HTML if CKEditor fails
                    $('#paper-content').html(paperHtml);
                });
            
            <?php if ( U::isNotEmpty($paper_row['ai_enhanced_submission'] ?? '') ) { ?>
                var aiHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($paper_row['ai_enhanced_submission'] ?? '') ?>);
                ClassicEditor
                    .create( document.querySelector( '#ai-content' ), {
                        ...ClassicEditor.defaultConfig,
                        toolbar: { items: [] }, // No toolbar
                        isReadOnly: true
                    } )
                    .then(editor => {
                        editor.setData(aiHtml);
                        editors['ai-display'] = editor;
                    })
                    .catch( error => {
                        console.error( error );
                        // Fallback to plain HTML if CKEditor fails
                        $('#ai-content').html(aiHtml);
                    });
            <?php } ?>
        <?php } ?>
        
        // Instructions display
        var instructionsHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($instructions ?? '') ?>);
        $('#display_instructions').html(instructionsHtml);
        
        // Instructions preview at top (if shown)
        <?php if ( !$is_submitted && is_string($instructions) && U::strlen($instructions) > 0 ) { ?>
            var instructionsPreviewHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($instructions ?? '') ?>);
            var fullInstructionsHtml = instructionsPreviewHtml;
            // Show first paragraph or truncate if very long
            var previewText = instructionsPreviewHtml;
            var wasTruncated = false;
            
            // If HTML, try to extract first paragraph
            var tempDiv = $('<div>').html(previewText);
            var allParagraphs = tempDiv.find('p');
            
            if ( allParagraphs.length > 0 ) {
                // Get first paragraph
                previewText = allParagraphs.first().html();
                // Check if there are more paragraphs OR if first paragraph is very long
                if ( allParagraphs.length > 1 ) {
                    wasTruncated = true;
                } else {
                    // Check if the single paragraph is very long (plain text > 300 chars)
                    var plainText = $('<div>').html(previewText).text();
                    if ( plainText.length > 300 ) {
                        previewText = plainText.substring(0, 300) + '...';
                        wasTruncated = true;
                    }
                }
            } else {
                // No paragraph structure - strip HTML tags and check length
                var plainText = $('<div>').html(previewText).text();
                if ( plainText.length > 300 ) {
                    previewText = plainText.substring(0, 300) + '...';
                    wasTruncated = true;
                } else {
                    previewText = plainText;
                }
            }
            
            $('#instructions-preview').html(previewText);
            
            // Only show "View full instructions" link if content was actually truncated
            // Compare the preview with the full instructions to be sure
            var previewPlain = $('<div>').html(previewText).text().replace(/\s+/g, ' ').trim();
            var fullPlain = $('<div>').html(fullInstructionsHtml).text().replace(/\s+/g, ' ').trim();
            
            if ( !wasTruncated || previewPlain === fullPlain || previewPlain.length >= fullPlain.length ) {
                $('#instructions-view-full-link').hide();
                // Also hide the Instructions menu item and section if no truncation occurred
                $('.tsugi-nav-link[data-section="instructions"]').hide();
                $('#section-instructions').hide();
            }
        <?php } ?>
    <?php } ?>
    
    // Handle Tsugi menu navigation clicks (use event delegation in case menu is rendered dynamically)
    // This runs for both instructors and students
    $(document).on('click', '.tsugi-nav-link', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var section = $(this).data('section');
            
            if ( !section ) {
                console.error('No section data found on clicked link');
                return;
            }
            
            // Remove active class from all navigation links and sections
            $('.tsugi-nav-link').removeClass('active');
            $('.student-section').removeClass('active');
            
            // Add active class to clicked link and corresponding section
            $(this).addClass('active');
            var targetSection = $('#section-' + section);
            
            if ( targetSection.length === 0 ) {
                console.error('Section not found: #section-' + section);
                return;
            }
            
            targetSection.addClass('active');
            
            // Initialize AI Prompt editor if switching to that tab and it's not initialized
            <?php if ( $USER->instructor ) { ?>
                if ( section === 'ai_prompt' ) {
                    if ( !editors['ai_prompt'] ) {
                        // Wait for the section to be visible before initializing CKEditor
                        setTimeout(function() {
                            var aiPromptElement = document.querySelector( '#editor_ai_prompt' );
                            var sectionElement = document.querySelector( '#section-ai_prompt' );
                            if ( aiPromptElement && sectionElement && sectionElement.classList.contains('active') ) {
                                ClassicEditor
                                    .create( aiPromptElement, ClassicEditor.defaultConfig )
                                    .then(editor => {
                                        editors['ai_prompt'] = editor;
                                    })
                                    .catch( error => {
                                        console.error( 'AI Prompt editor initialization error:', error );
                                    });
                            } else {
                                console.error( 'AI Prompt editor element not found or section not active' );
                            }
                        }, 150);
                    }
                }
            <?php } ?>
        });
        
        // Set default active section
        <?php if ( $USER->instructor ) { ?>
            // Instructor: Set Instructions as active by default
            $('.tsugi-nav-link[data-section="instructions"]').addClass('active');
        <?php } else { ?>
            // Student: Set default active section
            <?php if ( isset($_GET['review_page']) ) { ?>
                // Auto-navigate to Review section if review_page parameter is present
                $('.tsugi-nav-link').removeClass('active');
                $('.student-section').removeClass('active');
                $('.tsugi-nav-link[data-section="review"]').addClass('active');
                $('#section-review').addClass('active');
            <?php } else if ( !$is_submitted ) { ?>
                // When paper is not submitted, default to Paper tab
                $('.tsugi-nav-link[data-section="submission"]').addClass('active');
                $('#section-submission').addClass('active');
            <?php } else { ?>
                // When paper is submitted, default to Main tab
                $('.tsugi-nav-link[data-section="main"]').addClass('active');
            <?php } ?>
        <?php } ?>
        
        // Handle menu Save button click (student) - keep for backward compatibility
        $('#menu-save-btn').on('click', function(e) {
            e.preventDefault();
            $('#content-save-btn').click();
        });
        
        // Handle content area Save button click (student)
        $('#content-save-btn').on('click', function(e) {
            e.preventDefault();
            <?php if ( $can_edit ) { ?>
                // Update form fields with editor content before submitting
                if ( editors['submission'] ) {
                    $('#editor_submission').val(editors['submission'].getData());
                }
                if ( editors['ai_enhanced'] ) {
                    $('#editor_ai_enhanced').val(editors['ai_enhanced'].getData());
                }
                // Trigger the hidden save button
                $('#hidden-save-btn').click();
            <?php } ?>
        });
        
        
        // Handle menu Submit button click - keep for backward compatibility
        $('#menu-submit-btn').on('click', function(e) {
            e.preventDefault();
            $('#content-submit-btn').click();
        });
        
        // Handle content area Submit button click
        $('#content-submit-btn').on('click', function(e) {
            e.preventDefault();
            <?php if ( $can_edit ) { ?>
                <?php if ( !$is_submitted ) { ?>
                    if ( !confirm('Are you sure you want to submit your paper? Once submitted, neither your submission nor your AI enhanced submission will be editable unless your instructor resets your submission.') ) {
                        return;
                    }
                <?php } ?>
                <?php if ( isAIConfigured() ) { ?>
                    // Show spinner overlay after 2 second delay (only if AI is configured)
                    showSpinnerOverlay();
                <?php } ?>
                // Update form fields with editor content before submitting
                if ( editors['submission'] ) {
                    $('#editor_submission').val(editors['submission'].getData());
                }
                if ( editors['ai_enhanced'] ) {
                    $('#editor_ai_enhanced').val(editors['ai_enhanced'].getData());
                }
                // Trigger the hidden submit button
                $('#hidden-submit-btn').click();
            <?php } ?>
        });
        
        // Handle Reset Submission button click (from menu)
        $('#menu-reset-btn').on('click', function(e) {
            e.preventDefault();
            if ( confirm('Are you sure you want to reset your submission? This will make your paper editable again. Comments on your submission will be hidden but will still count for points.') ) {
                // Submit form directly with reset_submission parameter
                var $form = $('#paper_form');
                // Create a temporary input to submit with reset_submission
                var $resetInput = $('<input>').attr({
                    type: 'hidden',
                    name: 'reset_submission',
                    value: '1'
                });
                $form.append($resetInput);
                // Submit the form
                $form[0].submit();
            }
        });
        
        // Handle show/hide toggle for submitted papers
        $('.toggle-paper').on('click', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            var wrapper = $('#' + target + '-wrapper');
            var toggleText = $(this).find('.toggle-text');
            
            if ( wrapper.is(':visible') ) {
                wrapper.slideUp();
                toggleText.text('Show');
            } else {
                wrapper.slideDown();
                toggleText.text('Hide');
            }
        });
    
    // Handle instructor form (instructions and AI prompt)
    <?php if ( $USER->instructor ) { ?>
        $('#instructor_form').on('submit', function(e) {
            // Update editor content before submitting
            if ( editors['instructions'] ) {
                $('#editor_instructions').val(editors['instructions'].getData());
            }
            if ( editors['ai_prompt'] ) {
                $('#editor_ai_prompt').val(editors['ai_prompt'].getData());
            }
        });
    <?php } ?>

    // Handle form submission - get data from editors
    $('#paper_form').on('submit', function(e) {
        <?php if ( !$USER->instructor && $can_edit ) { ?>
            // Don't update editors if this is a reset submission
            var isReset = $('input[name="reset_submission"]').length > 0;
            if ( isReset ) {
                // Allow reset to proceed without updating editors
                return true;
            }
            // Check if this is a submit (not save) - show spinner if not already shown and AI is configured
            var isSubmit = $(document.activeElement).attr('name') === 'submit_paper' || 
                          ($(e.originalEvent?.submitter).length > 0 && $(e.originalEvent?.submitter).attr('name') === 'submit_paper') ||
                          $('#hidden-submit-btn').is(':focus');
            <?php if ( isAIConfigured() ) { ?>
                if ( isSubmit && !$('#submit-spinner-overlay').is(':visible') ) {
                    // Show spinner after 2 second delay (fallback) - only if AI is configured
                    showSpinnerOverlay();
                }
            <?php } ?>
            // Update form fields with editor content
            if ( editors['submission'] ) {
                $('#editor_submission').val(editors['submission'].getData());
            }
            if ( editors['ai_enhanced'] ) {
                $('#editor_ai_enhanced').val(editors['ai_enhanced'].getData());
            }
        <?php } ?>
    });
    
    // Handle Submit button click (direct button, not menu)
    $('input[name="submit_paper"]').on('click', function(e) {
        <?php if ( !$USER->instructor && $can_edit ) { ?>
            <?php if ( isAIConfigured() ) { ?>
                // Show spinner overlay after 2 second delay (only if AI is configured)
                showSpinnerOverlay();
            <?php } ?>
            // Update form fields with editor content before submitting
            if ( editors['submission'] ) {
                $('#editor_submission').val(editors['submission'].getData());
            }
            if ( editors['ai_enhanced'] ) {
                $('#editor_ai_enhanced').val(editors['ai_enhanced'].getData());
            }
        <?php } ?>
    });
    
    
    // Handle Save button click
    $('input[name="save_paper"]').on('click', function(e) {
        <?php if ( !$USER->instructor && $can_edit ) { ?>
            // Update form fields with editor content before submitting
            if ( editors['submission'] ) {
                $('#editor_submission').val(editors['submission'].getData());
            }
            if ( editors['ai_enhanced'] ) {
                $('#editor_ai_enhanced').val(editors['ai_enhanced'].getData());
            }
        <?php } ?>
    });
} // End initializeNavigation function
</script>
<?php
$OUTPUT->footerEnd();
