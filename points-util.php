<?php
/**
 * Utility functions for calculating and sending grades
 */

use \Tsugi\Core\Settings;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Result;
use \Tsugi\Util\LTI13;

/**
 * Calculate earned points and overall points for a student
 * Returns array with 'earned_points' and 'overall_points'
 * @param int $user_id User ID
 * @param int $link_id Link ID
 * @param int $result_id Optional result ID (will be looked up if not provided)
 * @param int $instructor_earned Optional instructor points to use instead of reading from grade
 */
function calculatePoints($user_id, $link_id, $result_id = null, $instructor_earned = null) {
    global $PDOX, $CFG, $LAUNCH;
    $p = $CFG->dbprefix;
    
    // Get settings
    $instructor_points = Settings::linkGet('instructorpoints', 0);
    $instructor_points = intval($instructor_points);
    $submit_points = Settings::linkGet('submitpoints', 0);
    $submit_points = intval($submit_points);
    $comment_points = Settings::linkGet('commentpoints', 0);
    $comment_points = intval($comment_points);
    $min_comments = Settings::linkGet('mincomments', 0);
    $min_comments = intval($min_comments);
    
    $overall_points = $instructor_points + $submit_points + ($comment_points * $min_comments);
    $earned_points = 0;
    
    // Get result_id if not provided
    if ( !$result_id ) {
        $result_row = $PDOX->rowDie(
            "SELECT result_id FROM {$p}lti_result WHERE user_id = :UID AND link_id = :LID",
            array(':UID' => $user_id, ':LID' => $link_id)
        );
        if ( $result_row ) {
            $result_id = $result_row['result_id'];
        }
    }
    
    if ( $result_id ) {
        // Check if submitted
        $paper_row = $PDOX->rowDie(
            "SELECT submitted FROM {$p}aipaper_result WHERE result_id = :RID",
            array(':RID' => $result_id)
        );
        if ( $paper_row && ($paper_row['submitted'] == 1 || $paper_row['submitted'] == true) ) {
            $earned_points += $submit_points;
        }
        
        // Count comments made by student
        $total_comments_row = $PDOX->rowDie(
            "SELECT COUNT(*) as cnt FROM {$p}aipaper_comment WHERE user_id = :UID",
            array(':UID' => $user_id)
        );
        $total_comments_made = $total_comments_row ? intval($total_comments_row['cnt']) : 0;
        
        // Comment points (limited to min_comments)
        if ( $min_comments > 0 ) {
            $comments_that_count = min($total_comments_made, $min_comments);
            $earned_points += ($comment_points * $comments_that_count);
        }
        
        // Get instructor points from grade (0.0-1.0 stored in LTI) or use provided value
        if ( $instructor_earned !== null ) {
            // Use provided instructor points
            $earned_points += intval($instructor_earned);
        } else {
            // Read from grade
            $result = Result::lookupResultBypass($user_id);
            if ( isset($result['grade']) && $result['grade'] !== null && $instructor_points > 0 ) {
                $grade = floatval($result['grade']);
                $instructor_earned_from_grade = round($grade * $instructor_points);
                $earned_points += $instructor_earned_from_grade;
            }
        }
    }
    
    return array(
        'earned_points' => intval($earned_points),
        'overall_points' => intval($overall_points)
    );
}

/**
 * Send grade to LTI based on calculated points
 */
function sendGradeToLTI($user_id, $earned_points, $overall_points) {
    global $LAUNCH;
    
    // Calculate grade as earned_points / overall_points (0.0 to 1.0 for LTI)
    if ( $overall_points > 0 ) {
        $computed_grade = floatval($earned_points) / floatval($overall_points);
    } else {
        $computed_grade = 0.0;
    }
    
    $result = Result::lookupResultBypass($user_id);
    $result['grade'] = -1; // Force resend
    $debug_log = array();
    $extra13 = array(
        LTI13::ACTIVITY_PROGRESS => LTI13::ACTIVITY_PROGRESS_COMPLETED,
        LTI13::GRADING_PROGRESS => LTI13::GRADING_PROGRESS_FULLYGRADED,
    );
    
    $status = $LAUNCH->result->gradeSend($computed_grade, $result, $debug_log, $extra13);
    return $status;
}

