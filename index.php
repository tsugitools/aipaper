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

// Auto-grade logic: Check if student should be auto-awarded full instructor points
if ( !$USER->instructor && $is_submitted ) {
    $instructor_points = Settings::linkGet('instructorpoints', 0);
    $instructor_points = intval($instructor_points);
    $auto_timeout = Settings::linkGet('auto_instructor_grade_timeout', 0);
    $auto_timeout = intval($auto_timeout);
    
    // Only check if instructor points > 0 and timeout is set
    if ( $instructor_points > 0 && $auto_timeout > 0 ) {
        // Check if instructor points are already set in database
        $instructor_points_earned = isset($paper_row['instructor_points']) && $paper_row['instructor_points'] !== null 
            ? intval($paper_row['instructor_points']) 
            : null;
        
        // Only auto-grade if instructor points are not already set (i.e., instructor hasn't graded yet)
        if ( $instructor_points_earned === null ) {
            // Get submission timestamp
            $submission_timestamp = isset($paper_row['updated_at']) ? strtotime($paper_row['updated_at']) : null;
            
            if ( $submission_timestamp ) {
                $current_timestamp = time();
                $seconds_since_submission = $current_timestamp - $submission_timestamp;
                
                // If timeout has passed, auto-award full instructor points
                if ( $seconds_since_submission >= $auto_timeout ) {
                    // Set instructor points to max value
                    $instructor_points_earned = $instructor_points;
                    
                    // Store instructor points in database
                    $PDOX->queryDie(
                        "UPDATE {$p}aipaper_result 
                         SET instructor_points = :POINTS, updated_at = NOW()
                         WHERE result_id = :RID",
                        array(
                            ':POINTS' => $instructor_points_earned,
                            ':RID' => $result_id
                        )
                    );
                    
                    // Recalculate overall points using the stored instructor points
                    $points_data = calculatePoints($USER->id, $LAUNCH->link->id, $result_id, $instructor_points_earned);
                    $earned_points = $points_data['earned_points'];
                    $overall_points = $points_data['overall_points'];
                    
                    // Calculate overall grade as earned_points / overall_points (0.0 to 1.0 for LTI)
                    if ( $overall_points > 0 ) {
                        $computed_grade = floatval($earned_points) / floatval($overall_points);
                    } else {
                        $computed_grade = 0.0;
                    }
                    
                    // Send the computed grade to LTI
                    $result = Result::lookupResultBypass($USER->id);
                    $result['grade'] = -1; // Force resend
                    $debug_log = array();
                    $extra13 = array(
                        LTI13::ACTIVITY_PROGRESS => LTI13::ACTIVITY_PROGRESS_COMPLETED,
                        LTI13::GRADING_PROGRESS => LTI13::GRADING_PROGRESS_FULLYGRADED,
                    );
                    
                    $LAUNCH->result->gradeSend($computed_grade, $result, $debug_log, $extra13);
                }
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

// Load AI prompt from database (per link)
$ai_prompt = '';
if ( $USER->instructor && isset($LAUNCH->link->id) ) {
    $p = $CFG->dbprefix;
    $link_row = $PDOX->rowDie(
        "SELECT ai_prompt FROM {$p}lti_link WHERE link_id = :LID",
        array(':LID' => $LAUNCH->link->id)
    );
    if ( $link_row && isset($link_row['ai_prompt']) ) {
        $ai_prompt = $link_row['ai_prompt'];
    }
}

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
    
    // For instructors, save instructions and AI prompt
    if ( $USER->instructor ) {
        $instructions = U::get($_POST, 'instructions', '');
        Settings::linkSet('instructions', $instructions);
        
        // Save AI prompt to database
        $ai_prompt = U::get($_POST, 'ai_prompt', '');
        // If AI prompt is blank, reset to default
        if ( empty(trim(strip_tags($ai_prompt))) ) {
            // Create default prompt with HTML formatting (for CKEditor)
            $ai_prompt = "<p>You are reviewing a student's paper submission.</p>\n\n<p>Provide a brief paragraph (approximately 200 words or less) with specific, actionable feedback. Focus on:</p>\n<ul>\n<li>Strengths of the submission</li>\n<li>Areas for improvement</li>\n<li>Specific suggestions for revision</li>\n</ul>\n\n<p>Be encouraging but honest, and reference specific parts of the paper when possible.</p>\n\n<p>The following are the instructions for the assignment:</p>\n\n<p>-- Instructions Included Here --</p>\n\n";
        }
        if ( isset($LAUNCH->link->id) ) {
            $p = $CFG->dbprefix;
            $PDOX->queryDie(
                "UPDATE {$p}lti_link SET ai_prompt = :PROMPT WHERE link_id = :LID",
                array(
                    ':PROMPT' => $ai_prompt,
                    ':LID' => $LAUNCH->link->id
                )
            );
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
        if ( isset($LAUNCH->link->id) ) {
            $p = $CFG->dbprefix;
            $link_row = $PDOX->rowDie(
                "SELECT ai_prompt FROM {$p}lti_link WHERE link_id = :LID",
                array(':LID' => $LAUNCH->link->id)
            );
            if ( $link_row && !empty($link_row['ai_prompt']) ) {
                $prompt_to_use = $link_row['ai_prompt'];
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
        } else {
            // Fallback to instructions if no link_id
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
    // Only show AI Prompt if AI is configured
    if ( isAIConfigured() ) {
        $menu->addLeft(__('AI Prompt'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_prompt" style="cursor: pointer;"');
    }
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Student Data'), 'grades');
    if ( $CFG->launchactivity ) {
        $submenu->addLink(__('Analytics'), 'analytics');
    }
    $submenu->addLink(__('Settings'), '#', /* push */ false, SettingsForm::attr());
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
        // When not submitted: Paper, Paper+AI, Instructions on left; Save Draft, Submit Paper on right
        $menu->addLeft(__('Paper'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="submission" style="cursor: pointer;"');
        $menu->addLeft(__('Paper+AI'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_enhanced" style="cursor: pointer;"');
        $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
        // Add Save and Submit buttons to menu if student can edit
        if ( $can_edit ) {
            $menu->addRight(__('Save Draft'), '#', /* push */ false, 'id="menu-save-btn" style="cursor: pointer;"');
            $submit_text = __('Submit Paper');
            $menu->addRight($submit_text, '#', /* push */ false, 'id="menu-submit-btn" style="cursor: pointer; font-weight: bold;"');
        }
    } else {
        // When submitted: Main, Instructions, Review on left
        $menu->addLeft(__('Main'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="main" style="cursor: pointer;"');
        $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
        $menu->addLeft(__('Review'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="review" style="cursor: pointer;"');
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

if ( $USER->instructor ) {
    SettingsForm::start();
    SettingsForm::text('submitpoints', __('Submit points (can be zero) - points earned for submitting a paper'));
    SettingsForm::text('mincomments', __('Minimum number of comments each student must make (if zero, students can comment on any other student\'s submission)'));
    SettingsForm::text('commentpoints', __('Points earned for each comment (can be zero)'));
    SettingsForm::text('instructorpoints', __('Instructor grade points (can be zero)'));
    SettingsForm::note(__('overall_points = instructor_points + submit_points + (comment_points * min_comments). Grades will only be sent for this activity if overall_points > 0.'));
    SettingsForm::text('ai_api_url', __('AI API URL (optional) - endpoint for generating AI comments. Leave empty to use test endpoint.'));
    SettingsForm::text('ai_api_key', __('AI API Key (optional) - authentication key for AI API'));
    SettingsForm::checkbox('userealnames', __('Use actual student names instead of generated names'));
    SettingsForm::checkbox('allowall', __('Allow students to see and comment on all submissions after the minimum has been met'));
    SettingsForm::checkbox('resubmit', __('Allow students to reset and resubmit their papers'));
    SettingsForm::text('auto_instructor_grade_timeout', __('Auto Instructor Grade Timeout (seconds) - automatically award full instructor points if instructor has not graded within this time after submission (typically left 0 or blank).  Two days is 172800 seconds.'));
    SettingsForm::dueDate();
    SettingsForm::done();
    SettingsForm::end();
} else {
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
        <!-- Instructions section -->
        <div class="student-section active" id="section-instructions">
            <p>Please enter the instructions for the assignment here.  This will be used to generate feedback for the students.</p>
            <div class="ckeditor-container">
                <textarea name="instructions" id="editor_instructions"><?= htmlentities($instructions ?? '') ?></textarea>
            </div>
        </div>
        
        <!-- AI Prompt section (only shown if AI is configured) -->
        <?php if ( isAIConfigured() ) { ?>
        <div class="student-section" id="section-ai_prompt">
            <?php 
            // Get or create default AI prompt (use HTML formatting for CKEditor)
            if ( empty($ai_prompt) ) {
                // Default prompt format with placeholder (HTML formatted)
                $ai_prompt = "<p>You are reviewing a student's paper submission.</p>\n\n<p>Provide a brief paragraph (approximately 200 words or less) with specific, actionable feedback. Focus on:</p>\n<ul>\n<li>Strengths of the submission</li>\n<li>Areas for improvement</li>\n<li>Specific suggestions for revision</li>\n</ul>\n\n<p>Be encouraging but honest, and reference specific parts of the paper when possible.</p>\n\n<p>The following are the instructions for the assignment:</p>\n\n<p>-- Instructions Included Here --</p>\n\n";
            }
            ?>
            <p><em>This prompt is sent to the AI service when generating feedback comments. Use <strong>-- Instructions Included Here --</strong> as a placeholder where you want the assignment instructions to be inserted automatically.</em></p>
            <div class="ckeditor-container">
                <textarea name="ai_prompt" id="editor_ai_prompt"><?= htmlentities($ai_prompt) ?></textarea>
            </div>
        </div>
        <?php } ?>
        <!-- Hidden submit button for instructor form -->
        <input type="submit" name="save_instructions" id="hidden-save-instructor-btn" style="display: none;">
    </form>
<?php } else { ?>
    <!-- Student: Sections with Tsugi menu navigation -->
    <form method="post" id="paper_form">
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
                <strong>Status:</strong> Your paper has not been submitted yet. Use the Paper and AI Enhanced sections to write and submit your paper.
            </div>
        <?php } ?>
    </div>
    
    <div class="student-section" id="section-instructions">
        <?php if ( is_string($instructions) && U::strlen($instructions) > 0 ) { ?>
            <div class="ckeditor-container">
                <div id="display_instructions"><?= htmlentities($instructions ?? 'Instructions not yet available') ?></div>
            </div>
        <?php } else { ?>
            <div class="alert alert-info">Instructions not yet available</div>
        <?php } ?>
    </div>
    
    <div class="student-section <?= !$is_submitted ? 'active' : '' ?>" id="section-submission">
        <p>
            Write your paper here. You can use AI to research and write your paper.  Do not use AI to write,
            rewrite or enhance your paper in any way.
            Spelling errors, grammar errors, and other minor mistakes are OK. This is your original work.
            If you want to use AI to write, rewrite or enhance your paper in any way, please
            include it under Paper+AI.  Reviewers and graders will look at both.
            AI (if configured) will be used to generate feedback on the non-AI version of your paper.
        </p>
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
        <p>Optionally, you can use AI to enhance your paper and include the AI-improved version of your paper here.
        If both are submitted reviewers and graders will look at both.</p>
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
        <p><strong>Review count:</strong> 
        <?php if ( $min_comments == 0 ) { ?>
            <?= $reviewed_count ?>
        <?php } else if ( $reviewed_count < $min_comments ) { ?>
            <?= $reviewed_count ?>/<?= $min_comments ?>
        <?php } else { ?>
            <?= $reviewed_count ?>
        <?php } ?>
        </p>
        <p>Review and comment on other students' submissions. Submissions are sorted by oldest first, prioritizing those that need more comments.</p>
        
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
        <!-- Hidden submit buttons for menu triggers -->
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
console.log('Script block loaded');
console.log('jQuery available:', typeof jQuery !== 'undefined', typeof $ !== 'undefined');

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

// Test if script is running at all
console.log('=== SCRIPT STARTING ===');
console.log('jQuery available:', typeof jQuery !== 'undefined', typeof $ !== 'undefined');

if (typeof jQuery === 'undefined' && typeof $ === 'undefined') {
    console.error('ERROR: jQuery is not loaded!');
    // Try loading jQuery
    var script = document.createElement('script');
    script.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
    document.head.appendChild(script);
    script.onload = function() {
        console.log('jQuery loaded, retrying...');
        initializeNavigation();
    };
} else {
    $(document).ready( function () {
        console.log('Document ready - setting up navigation handlers');
        initializeNavigation();
    });
}

function initializeNavigation() {
    console.log('=== INITIALIZING NAVIGATION ===');
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
    <?php } ?>
    
    // Handle Tsugi menu navigation clicks (use event delegation in case menu is rendered dynamically)
    // This runs for both instructors and students
    console.log('Setting up click handler for .tsugi-nav-link');
    
    // Wait a moment for menu to render, then check links
    setTimeout(function() {
        console.log('Found links:', $('.tsugi-nav-link').length);
        $('.tsugi-nav-link').each(function() {
            console.log('Link:', $(this).text(), 'data-section:', $(this).data('section'), 'href:', $(this).attr('href'));
        });
    }, 1000);
    
    $(document).on('click', '.tsugi-nav-link', function(e) {
            console.log('CLICK DETECTED on .tsugi-nav-link');
            e.preventDefault();
            e.stopPropagation();
            var section = $(this).data('section');
            console.log('Tab clicked:', section, 'Link:', $(this).attr('href'), 'Text:', $(this).text());
            
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
            console.log('Target section found:', targetSection.length, 'ID:', targetSection.attr('id'));
            
            if ( targetSection.length === 0 ) {
                console.error('Section not found: #section-' + section);
                return;
            }
            
            targetSection.addClass('active');
            console.log('Section should now be visible:', targetSection.hasClass('active'), targetSection.css('display'));
            
            // Initialize AI Prompt editor if switching to that tab and it's not initialized
            <?php if ( $USER->instructor ) { ?>
                if ( section === 'ai_prompt' ) {
                    console.log('Switching to AI Prompt tab, editor initialized:', !!editors['ai_prompt']);
                    if ( !editors['ai_prompt'] ) {
                        // Wait for the section to be visible before initializing CKEditor
                        setTimeout(function() {
                            var aiPromptElement = document.querySelector( '#editor_ai_prompt' );
                            var sectionElement = document.querySelector( '#section-ai_prompt' );
                            console.log('Initializing AI Prompt editor:', {
                                element: !!aiPromptElement,
                                section: !!sectionElement,
                                active: sectionElement ? sectionElement.classList.contains('active') : false
                            });
                            if ( aiPromptElement && sectionElement && sectionElement.classList.contains('active') ) {
                                ClassicEditor
                                    .create( aiPromptElement, ClassicEditor.defaultConfig )
                                    .then(editor => {
                                        editors['ai_prompt'] = editor;
                                        console.log( 'AI Prompt editor initialized successfully' );
                                    })
                                    .catch( error => {
                                        console.error( 'AI Prompt editor initialization error:', error );
                                    });
                            } else {
                                console.error( 'AI Prompt editor element not found or section not active', {
                                    element: !!aiPromptElement,
                                    section: !!sectionElement,
                                    active: sectionElement ? sectionElement.classList.contains('active') : false
                                });
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
        
        // Handle menu Save button click (student)
        $('#menu-save-btn').on('click', function(e) {
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
        
        // Handle instructor Save button click
        $('#menu-save-instructor-btn').on('click', function(e) {
            e.preventDefault();
            <?php if ( $USER->instructor ) { ?>
                // Update editor content before submitting
                if ( editors['instructions'] ) {
                    $('#editor_instructions').val(editors['instructions'].getData());
                }
                if ( editors['ai_prompt'] ) {
                    $('#editor_ai_prompt').val(editors['ai_prompt'].getData());
                }
                // Trigger the hidden submit button
                $('#hidden-save-instructor-btn').click();
            <?php } ?>
        });
        
        // Handle menu Submit button click
        $('#menu-submit-btn').on('click', function(e) {
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
