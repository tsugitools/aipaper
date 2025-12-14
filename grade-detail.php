<?php
require_once "../config.php";
\Tsugi\Core\LTIX::getConnection();
require_once "points-util.php";

use \Tsugi\Util\U;
use \Tsugi\Util\LTI13;
use \Tsugi\UI\Table;
use \Tsugi\Core\Result;
use \Tsugi\Core\LTIX;
use \Tsugi\Grades\GradeUtil;
use \Tsugi\Util\FakeName;

$LAUNCH = LTIX::requireData();

$user_id = U::safe_href(U::get($_REQUEST, 'user_id'));

$for_user = false;
if ( ! $user_id && isset($LAUNCH->for_user) ) {
    $for_user = true;
    $user_id = $LAUNCH->for_user->id;
    error_log("Direct instructor launch to grade user ".$user_id);
}

if ( ! $user_id ) {
    die('user_id is required');
}

// Set up the GET Params that we want to carry around.
$getparms = $_GET;
unset($getparms['delete']);
unset($getparms['resend']);

$self_url = addSession('grade.php?user_id='.$user_id);

// Get the user's grade data also checks session
$row = GradeUtil::gradeLoad($user_id);

$content = $LAUNCH->result->getJsonKeyForUser('content', '', $user_id);

$old_grade = $row ? $row['grade'] : 0;

// Get instructor_points setting
use \Tsugi\Core\Settings;
$instructor_points = Settings::linkGet('instructorpoints', 0);
$instructor_points = intval($instructor_points);

// Calculate old points from grade (if instructor_points > 0)
$old_points = 0;
if ( $instructor_points > 0 && $old_grade > 0 ) {
    $old_points = (int) ($old_grade * $instructor_points);
}

$gradeurl = Table::makeUrl('grade-detail.php', $getparms);
// Build grades URL preserving pagination/sort/search params but excluding user_id
$grades_params = array();
if (isset($_GET['page'])) $grades_params['page'] = intval($_GET['page']);
if (isset($_GET['sort'])) $grades_params['sort'] = U::get($_GET, 'sort');
if (isset($_GET['dir'])) $grades_params['dir'] = U::get($_GET, 'dir');
if (isset($_GET['search']) && !empty($_GET['search'])) $grades_params['search'] = U::get($_GET, 'search');
if (isset($_GET['fake_name_search']) && !empty($_GET['fake_name_search'])) $grades_params['fake_name_search'] = U::get($_GET, 'fake_name_search');
$gradesurl = Table::makeUrl('grades.php', $grades_params);

// Handle reset submission
if ( isset($_POST['resetSubmission']) ) {
    $p = $CFG->dbprefix;
    
    // Get result_id for this user
    $result_row = $PDOX->rowDie(
        "SELECT result_id FROM {$p}lti_result WHERE user_id = :UID AND link_id = :LID",
        array(':UID' => $user_id, ':LID' => $LAUNCH->link->id)
    );
    
    if ( $result_row ) {
        $result_id = $result_row['result_id'];
        
        // Get current JSON to update submission status
        $paper_row = $PDOX->rowDie(
            "SELECT json FROM {$p}aipaper_result WHERE result_id = :RID",
            array(':RID' => $result_id)
        );
        
        $paper_json = json_decode($paper_row['json'] ?? '{}');
        if ( !is_object($paper_json) ) $paper_json = new \stdClass();
        $paper_json->submitted = false; // Mark as not submitted
        $json_str = json_encode($paper_json);
        
        // Update submission status (keep the text, just mark as not submitted)
        $PDOX->queryDie(
            "UPDATE {$p}aipaper_result 
             SET json = :JSON, updated_at = NOW()
             WHERE result_id = :RID",
            array(':JSON' => $json_str, ':RID' => $result_id)
        );
        
        // Soft delete all comments on this submission (for points calculation, but hidden from students)
        $PDOX->queryDie(
            "UPDATE {$p}aipaper_comment 
             SET deleted = 1, updated_at = NOW()
             WHERE result_id = :RID",
            array(':RID' => $result_id)
        );
        
        $_SESSION['success'] = 'Submission reset. Student can now edit their submission.';
    } else {
        $_SESSION['error'] = 'No submission found to reset.';
    }
    
    header( 'Location: '.addSession($gradeurl) ) ;
    return;
}

// Handle incoming post to set the instructor points and update the grade
if ( isset($_POST['instSubmit']) || isset($_POST['instSubmitAdvance']) ) {
    // Get instructor_points setting
    $instructor_points = Settings::linkGet('instructorpoints', 0);
    $instructor_points = intval($instructor_points);

    $points = U::get($_POST, 'points');
    if ( U::strlen($points) == 0 || $points === null ) {
        $points = null;
        $instructor_earned = 0;
    } else if ( is_numeric($points) ) {
        $points = intval($points);
        if ( $instructor_points > 0 ) {
            // Validate points are within range
            if ( $points < 0 || $points > $instructor_points ) {
                $_SESSION['error'] = "Points must be between 0 and {$instructor_points}.";
                header( 'Location: '.addSession($gradeurl) ) ;
                return;
            }
            $instructor_earned = $points;
        } else {
            $_SESSION['error'] = "Instructor points not configured. Cannot assign points.";
            header( 'Location: '.addSession($gradeurl) ) ;
            return;
        }
    } else {
        $_SESSION['error'] = "Points must be a number or blank.";
        header( 'Location: '.addSession($gradeurl) ) ;
        return;
    }

    // Calculate overall grade using shared function with new instructor points
    $points_data = calculatePoints($user_id, $LAUNCH->link->id, null, $instructor_earned);
    $earned_points = $points_data['earned_points'];
    $overall_points = $points_data['overall_points'];
    
    // Calculate grade as earned_points / overall_points (0.0 to 1.0 for LTI)
    if ( $overall_points > 0 ) {
        $computed_grade = floatval($earned_points) / floatval($overall_points);
    } else {
        $computed_grade = 0.0;
    }

    $success = '';

    $result = Result::lookupResultBypass($user_id);
    $result['grade'] = -1; // Force resend
    $debug_log = array();
    $extra13 = array(
        LTI13::ACTIVITY_PROGRESS => LTI13::ACTIVITY_PROGRESS_COMPLETED,
        LTI13::GRADING_PROGRESS => LTI13::GRADING_PROGRESS_FULLYGRADED,
    );

    $status = $LAUNCH->result->gradeSend($computed_grade, $result, $debug_log, $extra13);
    if ( $status === true ) {
        if ( U::strlen($success) > 0 ) $success .= ', ';
        $success .= 'Grade submitted to server';
    } else {
        error_log("Problem sending grade ".$status);
        $_SESSION['error'] = 'Error sending grade to: '.$status;
        $_SESSION['debug_log'] = $debug_log;
    }


    if ( U::strlen($success) > 0 ) $_SESSION['success'] = $success;

    header( 'Location: '.addSession($gradeurl) ) ;
    return;
}


$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft('Back to all grades', $gradesurl);

// View
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

// Get result_id for this user to link to review page
$p = $CFG->dbprefix;
$result_row = $PDOX->rowDie(
    "SELECT result_id FROM {$p}lti_result WHERE user_id = :UID AND link_id = :LID",
    array(':UID' => $user_id, ':LID' => $LAUNCH->link->id)
);

$paper_row = null;
$result_id = null;
if ( $result_row ) {
    $result_id = $result_row['result_id'];
    $paper_row = $PDOX->rowDie(
        "SELECT submitted FROM {$p}aipaper_result WHERE result_id = :RID",
        array(':RID' => $result_id)
    );
    
    // Calculate points and review count for this student
    $points_data = calculatePoints($user_id, $LAUNCH->link->id, $result_id);
    $earned_points = $points_data['earned_points'];
    $overall_points = $points_data['overall_points'];
    
    // Count reviews made by this student
    $reviewed_row = $PDOX->rowDie(
        "SELECT COUNT(DISTINCT result_id) as cnt FROM {$p}aipaper_comment WHERE user_id = :UID",
        array(':UID' => $user_id)
    );
    $reviewed_count = $reviewed_row ? intval($reviewed_row['cnt']) : 0;
    
    // Get min_comments setting
    $min_comments = Settings::linkGet('mincomments', 0);
    $min_comments = intval($min_comments);
    
    // Count flagged comments for this student
    $flagged_comments_row = $PDOX->rowDie(
        "SELECT COUNT(*) as cnt FROM {$p}aipaper_comment WHERE user_id = :UID AND flagged = 1",
        array(':UID' => $user_id)
    );
    $flagged_comments_count = $flagged_comments_row ? intval($flagged_comments_row['cnt']) : 0;
    
    // Check if submission is flagged
    $submission_flagged = false;
    if ( $result_id ) {
        $submission_row = $PDOX->rowDie(
            "SELECT flagged FROM {$p}aipaper_result WHERE result_id = :RID",
            array(':RID' => $result_id)
        );
        $submission_flagged = $submission_row && ($submission_row['flagged'] == 1 || $submission_row['flagged'] == true);
    }
    
    // Get user info for name display
    $user_row = $PDOX->rowDie(
        "SELECT displayname FROM {$p}lti_user WHERE user_id = :UID",
        array(':UID' => $user_id)
    );
    $real_displayname = $user_row ? ($user_row['displayname'] ?? '') : '';
    $fake_displayname = FakeName::getName($user_id);
    $use_real_names = Settings::linkGet('userealnames', false);
    
    // Determine display name based on setting and user role
    if ( $use_real_names ) {
        $display_name = htmlentities($real_displayname);
    } else {
        // If userealnames is false: students see generated name, instructors see real name (generated name in parentheses)
        if ( $LAUNCH->user->instructor ) {
            $display_name = htmlentities($real_displayname);
            if ( !empty($fake_displayname) ) {
                $display_name .= ' (' . htmlentities($fake_displayname) . ')';
            }
        } else {
            $display_name = htmlentities($fake_displayname);
        }
    }
    
    // Display student name
    echo('<h3>Student: ' . $display_name . '</h3>');
    
    // Display points and review count
    if ( $overall_points > 0 ) {
        echo('<p><strong>Points:</strong> '.$earned_points.'/'.$overall_points.'</p>');
    }
    echo('<p><strong>Review count:</strong> ');
    if ( $min_comments == 0 ) {
        echo($reviewed_count);
    } else if ( $reviewed_count < $min_comments ) {
        echo($reviewed_count.'/'.$min_comments);
    } else {
        echo($reviewed_count);
    }
    echo('</p>');
    
    // Display flag information if any flags exist
    if ( $flagged_comments_count > 0 || $submission_flagged ) {
        echo('<p style="color: #d9534f;"><strong>Flags:</strong> ');
        $flag_parts = array();
        if ( $flagged_comments_count > 0 ) {
            $flag_parts[] = $flagged_comments_count . ' flagged comment' . ($flagged_comments_count == 1 ? '' : 's');
        }
        if ( $submission_flagged ) {
            $flag_parts[] = 'submission flagged';
        }
        echo(implode(', ', $flag_parts));
        echo('</p>');
    }
    
    if ( $paper_row && ($paper_row['submitted'] == 1 || $paper_row['submitted'] == true) ) {
        // Preserve pagination, sorting, and search when linking to review.php
        $review_params = array(
            'result_id' => $result_id,
            'from' => 'grade-detail',
            'user_id' => $user_id
        );
        // Add pagination/sort/search params if they exist
        if (isset($_GET['page'])) $review_params['page'] = intval($_GET['page']);
        if (isset($_GET['sort'])) $review_params['sort'] = U::get($_GET, 'sort');
        if (isset($_GET['dir'])) $review_params['dir'] = U::get($_GET, 'dir');
        if (isset($_GET['search']) && !empty($_GET['search'])) $review_params['search'] = U::get($_GET, 'search');
        if (isset($_GET['fake_name_search']) && !empty($_GET['fake_name_search'])) $review_params['fake_name_search'] = U::get($_GET, 'fake_name_search');
        $review_url = 'review.php?' . http_build_query($review_params);
        echo('<p><a href="'.$review_url.'" class="btn btn-primary">');
        echo(__('Review Submission'));
        echo("</a></p>\n");
    }
}

if ( U::strlen($content) > 0 ) {
    $next = Table::makeUrl('grade-detail.php', $getparms);
    echo('<p><a href="index.php?user_id='.$user_id.'&next='.urlencode($next).'">');
    echo(__('View Submission'));
    echo("</a><p>\n");
}

$next_user_id_ungraded = false;

$inst_note = $LAUNCH->result->getNote($user_id);

echo('<form method="post">
      <input type="hidden" name="user_id" value="'.$user_id.'">');

if ( $next_user_id_ungraded !== false ) {
      echo('<input type="hidden" name="next_user_id_ungraded" value="'.$next_user_id_ungraded.'">');
}

if ( $instructor_points > 0 ) {
    echo('<label for="points">Instructor Points (0-'.$instructor_points.')</label>
          <input type="number" name="points" id="grade" min="0" max="'.$instructor_points.'" value="'.$old_points.'"/><br/>');
} else {
    echo('<p><em>Instructor points not configured. Set instructor points in Settings to enable grading.</em></p>');
}

echo('<input type="submit" name="instSubmit" value="Update" class="btn btn-primary">');
echo('</form>');

// Reset submission button (separate form)
// Reuse result_id from above if available
if ( !isset($result_id) ) {
    $result_row = $PDOX->rowDie(
        "SELECT result_id FROM {$p}lti_result WHERE user_id = :UID AND link_id = :LID",
        array(':UID' => $user_id, ':LID' => $LAUNCH->link->id)
    );
    if ( $result_row ) {
        $result_id = $result_row['result_id'];
    }
}

if ( isset($result_id) ) {
    $paper_row = $PDOX->rowDie(
        "SELECT raw_submission, json FROM {$p}aipaper_result WHERE result_id = :RID",
        array(':RID' => $result_id)
    );
    
    if ( $paper_row ) {
        // Check if submitted from JSON
        $paper_json = json_decode($paper_row['json'] ?? '{}');
        if ( !is_object($paper_json) ) $paper_json = new \stdClass();
        $is_submitted = isset($paper_json->submitted) && $paper_json->submitted === true;
        
        if ( $is_submitted ) {
            echo('<form method="post" style="margin-top: 20px;">
                  <input type="hidden" name="user_id" value="'.$user_id.'">');
            echo('<input type="submit" name="resetSubmission" value="Reset Student Submission" class="btn btn-warning" 
                  onclick="return confirm(\'Are you sure you want to reset this submission? This will make it editable again. Comments will be hidden but will still count for points.\');">');
            echo('</form>');
        }
    }
}

if ( $next_user_id_ungraded !== false ) {
    echo(' <input type="submit" name="instSubmitAdvance" value="Update and Go To Next Ungraded Student" class="btn btn-primary">');
}
echo('</form>');




$OUTPUT->footer();
