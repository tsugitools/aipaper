<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Util\FakeName;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
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
    "SELECT raw_submission, ai_enhanced_submission, student_comment, submitted, flagged, flagged_by, json
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
        'student_comment' => '',
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

// Load submissions for review (only if student has submitted)
$review_submissions = array();
$review_page = 1;
$total_review_pages = 0;
if ( $is_submitted && !$USER->instructor ) {
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
        
        // Only include if current user hasn't reached minimum comments yet
        // If min_comments is 0, show all submissions (no minimum requirement)
        if ( $min_comments == 0 || $my_comments < $min_comments ) {
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
    
    // Sort by oldest submission first, then by comment count (fewer comments first)
    usort($submissions_with_counts, function($a, $b) {
        $date_cmp = strcmp($a['submission_date'], $b['submission_date']);
        if ( $date_cmp != 0 ) return $date_cmp;
        return $a['comment_count'] - $b['comment_count'];
    });
    
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
            'deleted' => isset($comment_row['deleted']) && ($comment_row['deleted'] == 1 || $comment_row['deleted'] == true)
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

// Handle reset submission
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
if ( count($_POST) > 0 && (isset($_POST['submit_paper']) || isset($_POST['save_paper'])) ) {
    $is_submit = isset($_POST['submit_paper']);
    
    if ( !$can_edit && !$USER->instructor ) {
        $_SESSION['error'] = 'Submission is locked';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }

    $raw_submission = U::get($_POST, 'raw_submission', '');
    $ai_enhanced = U::get($_POST, 'ai_enhanced_submission', '');
    $student_comment = U::get($_POST, 'student_comment', '');
    
    // Validate that paper is not blank when submitting (not when saving draft)
    if ( $is_submit && !$USER->instructor && U::isEmpty($raw_submission) ) {
        $_SESSION['error'] = 'Your paper cannot be blank. Please write your paper before submitting.';
        header( 'Location: '.addSession('index.php') ) ;
        return;
    }
    
    // For instructors, save instructions
    if ( $USER->instructor ) {
        $instructions = U::get($_POST, 'instructions', '');
        Settings::linkSet('instructions', $instructions);
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
             student_comment = :COMMENT, submitted = :SUBMITTED, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RAW' => $raw_submission,
            ':AI' => $ai_enhanced,
            ':COMMENT' => $student_comment,
            ':SUBMITTED' => $new_submitted ? 1 : 0,
            ':RID' => $result_id
        )
    );

    if ( $USER->instructor ) {
        $_SESSION['success'] = 'Instructions updated';
    } else {
        if ( $is_submit ) {
            $success_msg = 'Paper submitted';
            if ( $resubmit_allowed ) {
                $success_msg .= '. You can reset your submission from the Main page if you need to make changes.';
            } else {
                $success_msg .= '. Your submission is now locked and cannot be edited.';
            }
            $_SESSION['success'] = $success_msg;
        } else {
            $_SESSION['success'] = 'Draft saved';
        }
    }
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

$menu = new \Tsugi\UI\MenuSet();

if ( $LAUNCH->user->instructor ) {
    $menu->addLeft(__('Student Data'), 'grades');
    if ( $CFG->launchactivity ) {
        $menu->addLeft(__('Analytics'), 'analytics');
    }
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Settings'), '#', /* push */ false, SettingsForm::attr());
    // Only show test data generator if key is '12345'
    $key = $LAUNCH->key->key ?? '';
    if ( $key === '12345' ) {
        $submenu->addLink(__('Generate Test Data'), 'testdata.php');
    }
    $menu->addRight(__('Help'), 'help.php');
    $menu->addRight(__('Instructor'), $submenu, /* push */ false);
} else {
    // Add navigation items to menu
    $menu->addLeft(__('Main'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="main" style="cursor: pointer;"');
    $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
    // Only show Paper and Paper+AI if not submitted
    if ( !$is_submitted ) {
        $menu->addLeft(__('Paper'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="submission" style="cursor: pointer;"');
        $menu->addLeft(__('Paper+AI'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_enhanced" style="cursor: pointer;"');
    }
    // Show Review if submitted
    if ( $is_submitted ) {
        $menu->addLeft(__('Review'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="review" style="cursor: pointer;"');
    }
    
    if ( U::strlen($inst_note) > 0 ) $menu->addRight(__('Note'), '#', /* push */ false, 'data-toggle="modal" data-target="#noteModal"');
    // Add Reset Submission button if submitted and resubmit is allowed
    if ( $is_submitted && $resubmit_allowed ) {
        $menu->addRight(__('Reset Submission'), '#', /* push */ false, 'id="menu-reset-btn" style="cursor: pointer; color: #f0ad4e;"');
    }
    // Add Save and Submit buttons to menu if student can edit
    if ( $can_edit ) {
        $menu->addRight(__('Save Draft'), '#', /* push */ false, 'id="menu-save-btn" style="cursor: pointer;"');
        $submit_text = __('Submit Paper');
        $menu->addRight($submit_text, '#', /* push */ false, 'id="menu-submit-btn" style="cursor: pointer; font-weight: bold;"');
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
    SettingsForm::checkbox('userealnames', __('Use actual student names instead of generated names'));
    SettingsForm::checkbox('allowall', __('Allow students to see and comment on all submissions after the minimum has been met'));
    SettingsForm::checkbox('resubmit', __('Allow students to reset and resubmit their papers'));
    // TODO: Add an auto-grade ellapsed time (might not need ths - but think about it)
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
    <!-- Instructor: Instructions editor (no tabs) -->
    <?php $OUTPUT->welcomeUserCourse(); ?>
    <?php if ( $dueDate->message ) { ?>
        <p style="color:red;"><?= htmlentities($dueDate->message) ?></p>
    <?php } ?>
    <h3>Instructions / Rubric</h3>
    <form method="post">
        <div class="ckeditor-container">
            <textarea name="instructions" id="editor_instructions"><?= htmlentities($instructions ?? '') ?></textarea>
        </div>
        <p><input type="submit" name="submit_paper" value="Save Instructions" class="btn btn-primary"></p>
    </form>
<?php } else { ?>
    <!-- Student: Sections with Tsugi menu navigation -->
    <form method="post" id="paper_form">
    <div class="student-section active" id="section-main">
        <h3>
        <?php $OUTPUT->welcomeUserCourse(); ?>
</h3>
        <?php if ( $dueDate->message ) { ?>
            <p style="color:red;"><?= htmlentities($dueDate->message) ?></p>
        <?php } ?>
        <?php if ( $is_submitted ) { ?>
            <div class="alert alert-success" style="margin-top: 20px;">
                <strong>Status:</strong> Your paper has been submitted.
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
                            <div class="comment-item" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background-color: <?= isset($comment['deleted']) && $comment['deleted'] ? '#ffe6e6' : '#f9f9f9' ?>;">
                                <div style="margin-bottom: 10px;">
                                    <span class="label <?= $badge_class ?>" style="margin-right: 8px;"><?= htmlentities($badge_text) ?></span>
                                    <strong><?= htmlentities($comment['display_name']) ?></strong>
                                    <span style="color: #666; font-size: 0.9em; margin-left: 10px;"><?= htmlentities($formatted_date) ?></span>
                                    <?php if ( isset($comment['deleted']) && $comment['deleted'] ) { ?>
                                        <span class="label label-warning" style="margin-left: 10px;">Soft Deleted</span>
                                    <?php } ?>
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
        <h3>Instructions / Rubric</h3>
        <div class="ckeditor-container">
            <div id="display_instructions"><?= htmlentities($instructions ?? '') ?></div>
        </div>
        <p><em>Read-only instructions from your instructor.</em></p>
    </div>
    
    <div class="student-section" id="section-submission">
        <p>Write your paper here. You can use AI to research and write your paper.  Do not use AI to write, rewrite or enhance your paper in any way. Spelling errors, grammar errors, and other minor mistakes are OK. This is your original work.</p>
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
        <p>Optionally, you can use AI to enhance your paper and include the AI Enhanced version of your paper here.  If both are submitted reviewrs and graders will look at both.</p>
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
        <h3>Review Other Submissions</h3>
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
                                    <?php if ( $min_comments > 0 ) { ?>
                                        (<?= $sub['comment_count'] ?>/<?= $min_comments ?> required)
                                    <?php } ?>
                                </span>
                            <?php } else if ( $min_comments > 0 ) { ?>
                                <span style="color: #666; font-size: 0.9em; margin-left: 15px;">Your comments: 0/<?= $min_comments ?></span>
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
<?php } ?>

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
        
        // Handle Tsugi menu navigation clicks
        $('.tsugi-nav-link').on('click', function(e) {
            e.preventDefault();
            var section = $(this).data('section');
            
            // Remove active class from all navigation links and sections
            $('.tsugi-nav-link').removeClass('active');
            $('.student-section').removeClass('active');
            
            // Add active class to clicked link and corresponding section
            $(this).addClass('active');
            $('#section-' + section).addClass('active');
        });
        
        // Set Main as active by default, unless review_page is in URL
        <?php if ( isset($_GET['review_page']) ) { ?>
            // Auto-navigate to Review section if review_page parameter is present
            $('.tsugi-nav-link').removeClass('active');
            $('.student-section').removeClass('active');
            $('.tsugi-nav-link[data-section="review"]').addClass('active');
            $('#section-review').addClass('active');
        <?php } else { ?>
            $('.tsugi-nav-link[data-section="main"]').addClass('active');
        <?php } ?>
        
        // Handle menu Save button click
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
        
        // Handle menu Submit button click
        $('#menu-submit-btn').on('click', function(e) {
            e.preventDefault();
            <?php if ( $can_edit ) { ?>
                <?php if ( !$is_submitted ) { ?>
                    if ( !confirm('Are you sure you want to submit your paper? Once submitted, neither your submission nor your AI enhanced submission will be editable unless your instructor resets your submission.') ) {
                        return;
                    }
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
                $('#hidden-reset-btn').click();
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
    <?php } ?>

    // Handle form submission - get data from editors
    $('#paper_form').on('submit', function(e) {
        <?php if ( !$USER->instructor && $can_edit ) { ?>
            // Update form fields with editor content
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
});
</script>
<?php
$OUTPUT->footerEnd();
