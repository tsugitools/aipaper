<?php
require_once "../config.php";
require_once "points-util.php";

use \Tsugi\Util\U;
use \Tsugi\Util\FakeName;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

// Get result_id to review
$review_result_id = U::get($_GET, 'result_id');
if ( !$review_result_id || !is_numeric($review_result_id) ) {
    $_SESSION['error'] = 'Invalid submission to review';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}
$review_result_id = intval($review_result_id);

// Get review_page parameter to preserve it
$review_page = U::get($_GET, 'review_page', '');

// Get from parameter to know where to return to
$from_page = U::get($_GET, 'from', '');
$from_user_id = U::get($_GET, 'user_id', '');

// Get pagination and sorting parameters from grades.php (for instructor navigation)
$grades_page = U::get($_GET, 'page', '');
$grades_sort = U::get($_GET, 'sort', '');
$grades_dir = U::get($_GET, 'dir', '');

// Verify current user has submitted their own paper (unless instructor)
if ( !$USER->instructor ) {
    $my_result_id = $RESULT->id;
    $my_paper_row = $PDOX->rowDie(
        "SELECT submitted FROM {$p}aipaper_result WHERE result_id = :RID",
        array(':RID' => $my_result_id)
    );

    if ( !$my_paper_row || !($my_paper_row['submitted'] == 1 || $my_paper_row['submitted'] == true) ) {
        $_SESSION['error'] = 'You must submit your own paper before reviewing others';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }
}

// Verify the submission to review exists and is submitted
$review_result = $PDOX->rowDie(
    "SELECT r.result_id, r.user_id, r.created_at,
            u.displayname, u.email,
            ar.raw_submission, ar.ai_enhanced_submission, ar.submitted,
            ar.flagged, ar.flagged_by
     FROM {$p}lti_result r
     INNER JOIN {$p}lti_user u ON r.user_id = u.user_id
     INNER JOIN {$p}aipaper_result ar ON r.result_id = ar.result_id
     WHERE r.result_id = :RID AND r.link_id = :LID AND ar.submitted = 1",
    array(
        ':RID' => $review_result_id,
        ':LID' => $LAUNCH->link->id
    )
);

if ( !$review_result ) {
    $_SESSION['error'] = 'Submission not found or not available for review';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Don't allow students to review their own submission (instructors can review anyone)
if ( !$USER->instructor && $review_result['user_id'] == $USER->id ) {
    $_SESSION['error'] = 'You cannot review your own submission';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Handle toggle submission flag
if ( count($_POST) > 0 && isset($_POST['toggle_submission_flag']) ) {
    // Get navigation parameters
    $review_page = U::get($_POST, 'review_page', U::get($_GET, 'review_page', ''));
    $from_page = U::get($_POST, 'from', U::get($_GET, 'from', ''));
    $from_user_id = U::get($_POST, 'user_id', U::get($_GET, 'user_id', ''));
    $grades_page = U::get($_POST, 'page', U::get($_GET, 'page', ''));
    $grades_sort = U::get($_POST, 'sort', U::get($_GET, 'sort', ''));
    $grades_dir = U::get($_POST, 'dir', U::get($_GET, 'dir', ''));
    
    $new_flagged = intval($_POST['submission_flagged']);
    
    // Verify submission exists and belongs to this link
    $submission_check = $PDOX->rowDie(
        "SELECT ar.result_id, ar.flagged
         FROM {$p}aipaper_result ar
         INNER JOIN {$p}lti_result r ON ar.result_id = r.result_id
         WHERE ar.result_id = :RID AND r.link_id = :LID",
        array(':RID' => $review_result_id, ':LID' => $LAUNCH->link->id)
    );
    
    if ( $submission_check ) {
        // Anyone can flag, but only instructors can unflag
        if ( $new_flagged == 1 || $USER->instructor ) {
            $flagged_by = $new_flagged == 1 ? $USER->id : null;
            $PDOX->queryDie(
                "UPDATE {$p}aipaper_result 
                 SET flagged = :FLAGGED, flagged_by = :FLAGGED_BY, updated_at = NOW()
                 WHERE result_id = :RID",
                array(
                    ':FLAGGED' => $new_flagged,
                    ':FLAGGED_BY' => $flagged_by,
                    ':RID' => $review_result_id
                )
            );
            $_SESSION['success'] = $new_flagged ? 'Submission flagged' : 'Submission unflagged';
        } else {
            $_SESSION['error'] = 'Only instructors can unflag submissions';
        }
    } else {
        $_SESSION['error'] = 'Submission not found';
    }
    
    // Redirect back to review.php with preserved parameters
    $redirect_params = array('result_id' => $review_result_id);
    if ( !empty($review_page) ) {
        $redirect_params['review_page'] = intval($review_page);
    }
    if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
        $redirect_params['from'] = 'grade-detail';
        $redirect_params['user_id'] = $from_user_id;
        if ( !empty($grades_page) ) $redirect_params['page'] = intval($grades_page);
        if ( !empty($grades_sort) ) $redirect_params['sort'] = $grades_sort;
        if ( !empty($grades_dir) ) $redirect_params['dir'] = $grades_dir;
    }
    header( 'Location: '.addSession('review.php?' . http_build_query($redirect_params)) ) ;
    return;
}

// Handle toggle flag
if ( count($_POST) > 0 && isset($_POST['toggle_flag']) ) {
    $comment_id = intval($_POST['toggle_flag']);
    $new_flagged = intval($_POST['comment_flagged']);
    
    // Get navigation parameters
    $review_page = U::get($_POST, 'review_page', U::get($_GET, 'review_page', ''));
    $from_page = U::get($_POST, 'from', U::get($_GET, 'from', ''));
    $from_user_id = U::get($_POST, 'user_id', U::get($_GET, 'user_id', ''));
    $grades_page = U::get($_POST, 'page', U::get($_GET, 'page', ''));
    $grades_sort = U::get($_POST, 'sort', U::get($_GET, 'sort', ''));
    $grades_dir = U::get($_POST, 'dir', U::get($_GET, 'dir', ''));
    
    // Verify comment exists and belongs to this link
    $comment_check = $PDOX->rowDie(
        "SELECT c.comment_id, c.flagged, c.flagged_by
         FROM {$p}aipaper_comment c
         INNER JOIN {$p}lti_result r ON c.result_id = r.result_id
         WHERE c.comment_id = :CID AND r.link_id = :LID",
        array(':CID' => $comment_id, ':LID' => $LAUNCH->link->id)
    );
    
    if ( $comment_check ) {
        // Anyone can flag, but only instructors can unflag
        if ( $new_flagged == 1 || $USER->instructor ) {
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
            $_SESSION['success'] = $new_flagged ? 'Comment flagged' : 'Comment unflagged';
        } else {
            $_SESSION['error'] = 'Only instructors can unflag comments';
        }
    } else {
        $_SESSION['error'] = 'Comment not found';
    }
    
    // Redirect back to review.php with preserved parameters
    $redirect_params = array('result_id' => $review_result_id);
    if ( !empty($review_page) ) {
        $redirect_params['review_page'] = intval($review_page);
    }
    if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
        $redirect_params['from'] = 'grade-detail';
        $redirect_params['user_id'] = $from_user_id;
        if ( !empty($grades_page) ) $redirect_params['page'] = intval($grades_page);
        if ( !empty($grades_sort) ) $redirect_params['sort'] = $grades_sort;
        if ( !empty($grades_dir) ) $redirect_params['dir'] = $grades_dir;
    }
    header( 'Location: '.addSession('review.php?' . http_build_query($redirect_params)) ) ;
    return;
}

// Handle toggle comment (soft delete/un-delete)
if ( count($_POST) > 0 && isset($_POST['toggle_comment']) && $USER->instructor ) {
    $comment_id = intval($_POST['toggle_comment']);
    $new_deleted = intval($_POST['comment_deleted']);
    
    // Get review_page and from parameters to preserve navigation
    $review_page = U::get($_POST, 'review_page', U::get($_GET, 'review_page', ''));
    $from_page = U::get($_POST, 'from', U::get($_GET, 'from', ''));
    $from_user_id = U::get($_POST, 'user_id', U::get($_GET, 'user_id', ''));
    $grades_page = U::get($_POST, 'page', U::get($_GET, 'page', ''));
    $grades_sort = U::get($_POST, 'sort', U::get($_GET, 'sort', ''));
    $grades_dir = U::get($_POST, 'dir', U::get($_GET, 'dir', ''));
    
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
    
    // Redirect back to review.php with preserved parameters
    $redirect_params = array('result_id' => $review_result_id);
    if ( !empty($review_page) ) {
        $redirect_params['review_page'] = intval($review_page);
    }
    if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
        $redirect_params['from'] = 'grade-detail';
        $redirect_params['user_id'] = $from_user_id;
        // Preserve grades.php pagination/sort params
        if ( !empty($grades_page) ) $redirect_params['page'] = intval($grades_page);
        if ( !empty($grades_sort) ) $redirect_params['sort'] = $grades_sort;
        if ( !empty($grades_dir) ) $redirect_params['dir'] = $grades_dir;
    }
    header( 'Location: '.addSession('review.php?' . http_build_query($redirect_params)) ) ;
    return;
}

// Handle comment submission
if ( count($_POST) > 0 && isset($_POST['submit_comment']) ) {
    // Get review_page from POST (form) or GET (URL)
    $review_page = U::get($_POST, 'review_page', U::get($_GET, 'review_page', ''));
    // Get from parameters from POST (form) or GET (URL)
    $from_page = U::get($_POST, 'from', U::get($_GET, 'from', ''));
    $from_user_id = U::get($_POST, 'user_id', U::get($_GET, 'user_id', ''));
    // Get grades.php pagination/sort params
    $grades_page = U::get($_POST, 'page', U::get($_GET, 'page', ''));
    $grades_sort = U::get($_POST, 'sort', U::get($_GET, 'sort', ''));
    $grades_dir = U::get($_POST, 'dir', U::get($_GET, 'dir', ''));
    
    $comment_text = U::get($_POST, 'comment_text', '');
    
    if ( U::isEmpty($comment_text) ) {
        $_SESSION['error'] = 'Comment cannot be blank';
        $redirect_params = array('result_id' => $review_result_id);
        if ( !empty($review_page) ) {
            $redirect_params['review_page'] = intval($review_page);
        }
        if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
            $redirect_params['from'] = 'grade-detail';
            $redirect_params['user_id'] = $from_user_id;
            if ( !empty($grades_page) ) $redirect_params['page'] = intval($grades_page);
            if ( !empty($grades_sort) ) $redirect_params['sort'] = $grades_sort;
            if ( !empty($grades_dir) ) $redirect_params['dir'] = $grades_dir;
        }
        header( 'Location: '.addSession('review.php?' . http_build_query($redirect_params)) ) ;
        return;
    }
    
    // Determine comment type based on user role
    $comment_type = $USER->instructor ? 'instructor' : 'student';
    
    // Insert comment
    $PDOX->queryDie(
        "INSERT INTO {$p}aipaper_comment (result_id, user_id, comment_text, comment_type, created_at)
         VALUES (:RID, :UID, :TEXT, :TYPE, NOW())",
        array(
            ':RID' => $review_result_id,
            ':UID' => $USER->id,
            ':TEXT' => $comment_text,
            ':TYPE' => $comment_type
        )
    );
    
    // Recalculate and send grade to LTI if student made comment
    if ( !$USER->instructor ) {
        // Get current user's result_id for consistency with index.php
        $my_result_id = $RESULT->id;
        $points_data = calculatePoints($USER->id, $LAUNCH->link->id, $my_result_id);
        if ( $points_data['overall_points'] > 0 ) {
            sendGradeToLTI($USER->id, $points_data['earned_points'], $points_data['overall_points']);
        }
    }
    
    $_SESSION['success'] = 'Comment added successfully';
    
    // Redirect back to grade-detail.php if that's where we came from
    if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
        $redirect_params = array('user_id' => $from_user_id);
        // Preserve grades.php pagination/sort params
        if ( !empty($grades_page) ) $redirect_params['page'] = intval($grades_page);
        if ( !empty($grades_sort) ) $redirect_params['sort'] = $grades_sort;
        if ( !empty($grades_dir) ) $redirect_params['dir'] = $grades_dir;
        header( 'Location: '.addSession('grade-detail.php?' . http_build_query($redirect_params)) ) ;
        return;
    }
    
    // Otherwise redirect back to review.php (for students from index.php)
    $redirect_params = array('result_id' => $review_result_id);
    if ( !empty($review_page) ) {
        $redirect_params['review_page'] = intval($review_page);
    }
    header( 'Location: '.addSession('review.php?' . http_build_query($redirect_params)) ) ;
    return;
}

// Load existing comments
// For students, hide soft-deleted comments. For instructors, show them with indication.
$use_real_names = Settings::linkGet('userealnames', false);
$deleted_filter = $USER->instructor ? '' : 'AND c.deleted = 0';
$comment_rows = $PDOX->allRowsDie(
    "SELECT c.comment_id, c.comment_text, c.comment_type, c.created_at, c.user_id, c.deleted,
            c.flagged, c.flagged_by,
            u.displayname, u.email
     FROM {$p}aipaper_comment c
     LEFT JOIN {$p}lti_user u ON c.user_id = u.user_id
     WHERE c.result_id = :RID $deleted_filter
     ORDER BY c.created_at DESC",
    array(':RID' => $review_result_id)
);

$comments = array();
foreach ( $comment_rows as $comment_row ) {
    $comment = array(
        'comment_id' => $comment_row['comment_id'],
        'comment_text' => $comment_row['comment_text'],
        'comment_type' => $comment_row['comment_type'],
        'created_at' => $comment_row['created_at'],
        'user_id' => $comment_row['user_id'],
        'deleted' => isset($comment_row['deleted']) && ($comment_row['deleted'] == 1 || $comment_row['deleted'] == true),
        'flagged' => isset($comment_row['flagged']) && ($comment_row['flagged'] == 1 || $comment_row['flagged'] == true),
        'flagged_by' => $comment_row['flagged_by']
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

// Get display name for the submission author
$author_name = $use_real_names && !empty($review_result['displayname']) 
    ? $review_result['displayname'] 
    : FakeName::getName($review_result['user_id']);

// review_page is already set above

$menu = new \Tsugi\UI\MenuSet();
if ( $USER->instructor ) {
    // If we came from grade-detail, go back there; otherwise go to grades.php
    if ( $from_page == 'grade-detail' && !empty($from_user_id) ) {
        $back_params = array('user_id' => $from_user_id);
        // Preserve grades.php pagination/sort params
        if ( !empty($grades_page) ) $back_params['page'] = intval($grades_page);
        if ( !empty($grades_sort) ) $back_params['sort'] = $grades_sort;
        if ( !empty($grades_dir) ) $back_params['dir'] = $grades_dir;
        $menu->addLeft(__('Back'), 'grade-detail.php?' . http_build_query($back_params));
    } else {
        $menu->addLeft(__('Back to Grades'), 'grades.php');
    }
} else {
    $back_url = 'index.php';
    if ( !empty($review_page) ) {
        $back_url .= '?review_page=' . intval($review_page);
    }
    $menu->addLeft(__('Back'), $back_url);
}

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

$OUTPUT->welcomeUserCourse();

?>
<style>
.ckeditor-container { min-height: 15em; }
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
.comment-item {
    margin-bottom: 20px;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #f9f9f9;
}
</style>

<h2>Review Submission</h2>

<div style="margin-bottom: 30px;">
    <p><strong>Author:</strong> <?= htmlentities($author_name) ?></p>
    <p><strong>Submitted:</strong> <?= htmlentities(date('M j, Y g:i A', strtotime($review_result['created_at']))) ?></p>
    <?php 
    $submission_flagged = isset($review_result['flagged']) && ($review_result['flagged'] == 1 || $review_result['flagged'] == true);
    $flag_color = $submission_flagged ? '#d9534f' : '#999';
    $flag_size = $submission_flagged ? '18px' : '16px';
    $flag_style = $submission_flagged ? 'font-weight: bold; border: 1px solid #d9534f; border-radius: 3px; padding: 2px;' : '';
    $flag_alt = $submission_flagged ? 'Submission is flagged (click to unflag)' : 'Submission is not flagged (click to flag)';
    ?>
    <?php if ( !$submission_flagged || $USER->instructor ) { ?>
        <p>
            <form method="post" style="display: inline;" onsubmit="return confirm('<?= $submission_flagged ? 'Unflag' : 'Flag' ?> this submission?');">
                <input type="hidden" name="toggle_submission_flag" value="1">
                <input type="hidden" name="submission_flagged" value="<?= $submission_flagged ? '0' : '1' ?>">
                <?php if ( !empty($review_page) ) { ?>
                    <input type="hidden" name="review_page" value="<?= htmlentities($review_page) ?>">
                <?php } ?>
                <?php if ( $from_page == 'grade-detail' && !empty($from_user_id) ) { ?>
                    <input type="hidden" name="from" value="grade-detail">
                    <input type="hidden" name="user_id" value="<?= htmlentities($from_user_id) ?>">
                    <?php if ( !empty($grades_page) ) { ?>
                        <input type="hidden" name="page" value="<?= intval($grades_page) ?>">
                    <?php } ?>
                    <?php if ( !empty($grades_sort) ) { ?>
                        <input type="hidden" name="sort" value="<?= htmlentities($grades_sort) ?>">
                    <?php } ?>
                    <?php if ( !empty($grades_dir) ) { ?>
                        <input type="hidden" name="dir" value="<?= htmlentities($grades_dir) ?>">
                    <?php } ?>
                <?php } ?>
                <button type="submit" class="btn btn-xs" style="background: none; border: none; padding: 0; margin: 0 5px;" aria-label="<?= htmlentities($flag_alt) ?>" title="<?= htmlentities($flag_alt) ?>">
                    <span class="glyphicon glyphicon-flag" style="color: <?= $flag_color ?>; font-size: <?= $flag_size ?>; <?= $flag_style ?>"></span>
                    <span style="margin-left: 5px;"><?= $submission_flagged ? 'Unflag Submission' : 'Flag Submission' ?></span>
                </button>
            </form>
        </p>
    <?php } ?>
</div>

<div style="margin-bottom: 30px;">
    <h3>Paper</h3>
    <div id="review-paper-content" class="ckeditor-display"></div>
</div>

<?php if ( U::isNotEmpty($review_result['ai_enhanced_submission'] ?? '') ) { ?>
    <div style="margin-bottom: 30px;">
        <h3>Paper+AI</h3>
        <div id="review-ai-content" class="ckeditor-display"></div>
    </div>
<?php } ?>

<div style="margin-top: 40px;">
    <h3>Comments</h3>
    
    <?php if ( count($comments) > 0 ) { ?>
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
                <div class="comment-item" id="comment-<?= $comment['comment_id'] ?>" style="background-color: <?= isset($comment['deleted']) && $comment['deleted'] ? '#ffe6e6' : '#f9f9f9' ?>;">
                    <div style="margin-bottom: 10px;">
                        <span class="label <?= $badge_class ?>" style="margin-right: 8px;"><?= htmlentities($badge_text) ?></span>
                        <strong><?= htmlentities($comment['display_name']) ?></strong>
                        <span style="color: #666; font-size: 0.9em; margin-left: 10px;"><?= htmlentities($formatted_date) ?></span>
                        <span style="margin-left: 10px;">
                            <?php if ( $USER->instructor ) { ?>
                                <!-- Trash can for hide/show (instructors only) -->
                                <?php 
                                $is_deleted = isset($comment['deleted']) && $comment['deleted'];
                                $trash_color = $is_deleted ? '#d9534f' : '#999';
                                $trash_size = $is_deleted ? '18px' : '16px';
                                $trash_style = $is_deleted ? 'font-weight: bold; border: 1px solid #d9534f; border-radius: 3px; padding: 2px;' : '';
                                $trash_alt = $is_deleted ? 'Comment is hidden (click to show)' : 'Comment is visible (click to hide)';
                                ?>
                                <form method="post" style="display: inline;" onsubmit="return confirm('<?= $is_deleted ? 'Un-hide' : 'Hide' ?> this comment?');">
                                    <input type="hidden" name="toggle_comment" value="<?= $comment['comment_id'] ?>">
                                    <input type="hidden" name="comment_deleted" value="<?= $is_deleted ? '0' : '1' ?>">
                                    <?php if ( !empty($review_page) ) { ?>
                                        <input type="hidden" name="review_page" value="<?= htmlentities($review_page) ?>">
                                    <?php } ?>
                                    <?php if ( $from_page == 'grade-detail' && !empty($from_user_id) ) { ?>
                                        <input type="hidden" name="from" value="grade-detail">
                                        <input type="hidden" name="user_id" value="<?= htmlentities($from_user_id) ?>">
                                        <?php if ( !empty($grades_page) ) { ?>
                                            <input type="hidden" name="page" value="<?= intval($grades_page) ?>">
                                        <?php } ?>
                                        <?php if ( !empty($grades_sort) ) { ?>
                                            <input type="hidden" name="sort" value="<?= htmlentities($grades_sort) ?>">
                                        <?php } ?>
                                        <?php if ( !empty($grades_dir) ) { ?>
                                            <input type="hidden" name="dir" value="<?= htmlentities($grades_dir) ?>">
                                        <?php } ?>
                                    <?php } ?>
                                    <button type="submit" class="btn btn-xs" style="background: none; border: none; padding: 0; margin: 0 5px;" aria-label="<?= htmlentities($trash_alt) ?>" title="<?= htmlentities($trash_alt) ?>">
                                        <span class="glyphicon glyphicon-trash" style="color: <?= $trash_color ?>; font-size: <?= $trash_size ?>; <?= $trash_style ?>"></span>
                                    </button>
                                </form>
                            <?php } ?>
                            <!-- Flag/unflag button (anyone can flag, only instructors can unflag) -->
                            <?php if ( !isset($comment['flagged']) || !$comment['flagged'] || $USER->instructor ) { ?>
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
                                    <?php if ( !empty($review_page) ) { ?>
                                        <input type="hidden" name="review_page" value="<?= htmlentities($review_page) ?>">
                                    <?php } ?>
                                    <?php if ( $from_page == 'grade-detail' && !empty($from_user_id) ) { ?>
                                        <input type="hidden" name="from" value="grade-detail">
                                        <input type="hidden" name="user_id" value="<?= htmlentities($from_user_id) ?>">
                                        <?php if ( !empty($grades_page) ) { ?>
                                            <input type="hidden" name="page" value="<?= intval($grades_page) ?>">
                                        <?php } ?>
                                        <?php if ( !empty($grades_sort) ) { ?>
                                            <input type="hidden" name="sort" value="<?= htmlentities($grades_sort) ?>">
                                        <?php } ?>
                                        <?php if ( !empty($grades_dir) ) { ?>
                                            <input type="hidden" name="dir" value="<?= htmlentities($grades_dir) ?>">
                                        <?php } ?>
                                    <?php } ?>
                                    <button type="submit" class="btn btn-xs" style="background: none; border: none; padding: 0; margin: 0 5px;" aria-label="<?= htmlentities($flag_alt) ?>" title="<?= htmlentities($flag_alt) ?>">
                                        <span class="glyphicon glyphicon-flag" style="color: <?= $flag_color ?>; font-size: <?= $flag_size ?>; <?= $flag_style ?>"></span>
                                    </button>
                                </form>
                            <?php } ?>
                        </span>
                    </div>
                    <div class="comment-text" style="line-height: 1.6;">
                        <div class="comment-html-<?= $comment['comment_id'] ?>"></div>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
    
    <div style="margin-top: 30px;">
        <h4>Add Your Comment</h4>
        <form method="post">
            <?php if ( !empty($review_page) ) { ?>
                <input type="hidden" name="review_page" value="<?= intval($review_page) ?>">
            <?php } ?>
            <?php if ( $from_page == 'grade-detail' && !empty($from_user_id) ) { ?>
                <input type="hidden" name="from" value="grade-detail">
                <input type="hidden" name="user_id" value="<?= htmlentities($from_user_id) ?>">
                <?php if ( !empty($grades_page) ) { ?>
                    <input type="hidden" name="page" value="<?= intval($grades_page) ?>">
                <?php } ?>
                <?php if ( !empty($grades_sort) ) { ?>
                    <input type="hidden" name="sort" value="<?= htmlentities($grades_sort) ?>">
                <?php } ?>
                <?php if ( !empty($grades_dir) ) { ?>
                    <input type="hidden" name="dir" value="<?= htmlentities($grades_dir) ?>">
                <?php } ?>
            <?php } ?>
            <div class="ckeditor-container">
                <textarea name="comment_text" id="editor_comment"></textarea>
            </div>
            <p style="margin-top: 15px;">
                <input type="submit" name="submit_comment" value="Submit Comment" class="btn btn-primary">
            </p>
        </form>
    </div>
</div>

<?php
$OUTPUT->footerStart();
?>
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

$(document).ready( function () {
    // Initialize comment editor
    ClassicEditor
        .create( document.querySelector( '#editor_comment' ), ClassicEditor.defaultConfig )
        .then(editor => {
            editors['comment'] = editor;
        })
        .catch( error => {
            console.error( error );
        });
    
    // Display submission content in readonly CKEditor
    var paperHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($review_result['raw_submission'] ?? '') ?>);
    ClassicEditor
        .create( document.querySelector( '#review-paper-content' ), {
            ...ClassicEditor.defaultConfig,
            toolbar: { items: [] },
            isReadOnly: true
        } )
        .then(editor => {
            editor.setData(paperHtml);
        })
        .catch( error => {
            console.error( error );
            $('#review-paper-content').html(paperHtml);
        });
    
    <?php if ( U::isNotEmpty($review_result['ai_enhanced_submission'] ?? '') ) { ?>
        var aiHtml = HtmlSanitizer.SanitizeHtml(<?= json_encode($review_result['ai_enhanced_submission'] ?? '') ?>);
        ClassicEditor
            .create( document.querySelector( '#review-ai-content' ), {
                ...ClassicEditor.defaultConfig,
                toolbar: { items: [] },
                isReadOnly: true
            } )
            .then(editor => {
                editor.setData(aiHtml);
            })
            .catch( error => {
                console.error( error );
                $('#review-ai-content').html(aiHtml);
            });
    <?php } ?>
    
    // Sanitize and display comment HTML
    <?php foreach ( $comments as $comment ) { ?>
        var commentHtml<?= $comment['comment_id'] ?> = HtmlSanitizer.SanitizeHtml(<?= json_encode($comment['comment_text']) ?>);
        $('.comment-html-<?= $comment['comment_id'] ?>').html(commentHtml<?= $comment['comment_id'] ?>);
    <?php } ?>
    
    // Handle form submission - get data from editor
    $('form').on('submit', function(e) {
        if ( editors['comment'] ) {
            $('#editor_comment').val(editors['comment'].getData());
        }
    });
});
</script>
<?php
$OUTPUT->footerEnd();

