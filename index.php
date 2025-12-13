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
    "SELECT raw_submission, ai_enhanced_submission, student_comment, flagged, flagged_by, json
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
        'flagged' => false,
        'flagged_by' => null,
        'json' => null
    );
}

// Check submission status from JSON field
$paper_json = json_decode($paper_row['json'] ?? '{}');
if ( !is_object($paper_json) ) $paper_json = new \stdClass();
$is_submitted = isset($paper_json->submitted) && $paper_json->submitted === true;

// Check if resubmit is allowed
$resubmit_allowed = Settings::linkGet('resubmit', false);
// Can edit only if not submitted (or if instructor)
// Resubmit setting only controls Reset button visibility, not editability
$can_edit = !$is_submitted || $USER->instructor;

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
    $paper_json = json_decode($paper_row['json'] ?? '{}');
    if ( !is_object($paper_json) ) $paper_json = new \stdClass();
    unset($paper_json->submitted);
    
    $json_str = json_encode($paper_json);
    
    // Reset submitted status but keep all content (paper, AI enhanced, comments)
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET json = :JSON, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':JSON' => $json_str,
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
    
    // For instructors, save instructions
    if ( $USER->instructor ) {
        $instructions = U::get($_POST, 'instructions', '');
        Settings::linkSet('instructions', $instructions);
    }

    // Update submission status in JSON
    $paper_json = json_decode($paper_row['json'] ?? '{}');
    if ( !is_object($paper_json) ) $paper_json = new \stdClass();
    $was_submitted = isset($paper_json->submitted) && $paper_json->submitted === true;
    
    // Mark as submitted only if Submit button was clicked (not Save)
    if ( $is_submit && !$was_submitted && U::isNotEmpty($raw_submission) ) {
        $paper_json->submitted = true;
        $RESULT->notifyReadyToGrade();
    }
    
    $json_str = json_encode($paper_json);

    // Update aipaper_result
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET raw_submission = :RAW, ai_enhanced_submission = :AI, 
             student_comment = :COMMENT, json = :JSON, updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RAW' => $raw_submission,
            ':AI' => $ai_enhanced,
            ':COMMENT' => $student_comment,
            ':JSON' => $json_str,
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
    $menu->addRight(__('Help'), 'help.php');
    $menu->addRight(__('Instructor'), $submenu, /* push */ false);
} else {
    // Add navigation items to menu
    $menu->addLeft(__('Main'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="main" style="cursor: pointer;"');
    $menu->addLeft(__('Instructions'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="instructions" style="cursor: pointer;"');
    $menu->addLeft(__('Paper'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="submission" style="cursor: pointer;"');
    $menu->addLeft(__('Paper+AI'), '#', /* push */ false, 'class="tsugi-nav-link" data-section="ai_enhanced" style="cursor: pointer;"');
    
    if ( U::strlen($inst_note) > 0 ) $menu->addRight(__('Note'), '#', /* push */ false, 'data-toggle="modal" data-target="#noteModal"');
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
    SettingsForm::text('instructorpoints', __('Instructor grade points (can be zero)'));
    SettingsForm::text('commentpoints', __('Points earned for each comment (can be zero)'));
    SettingsForm::text('mincomments', __('Minimum number of comments per student (can be zero)'));
    SettingsForm::note(__('overall_points = instructor_points + (comment_points * min_comments). Grades will only be sent for this activity if overall_points > 0.'));
    SettingsForm::checkbox('userealnames', __('Use actual student names instead of generated names'));
    SettingsForm::checkbox('allowall', __('Allow students to see and comment on all submissions after the minimum has been met'));
    SettingsForm::checkbox('resubmit', __('Allow students to reset and resubmit their papers'));
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
        <p>Use the menu at the top to navigate between sections:</p>
        <ul>
            <li><strong>Main</strong> - This overview page</li>
            <li><strong>Instructions</strong> - Read the assignment instructions and rubric</li>
            <li><strong>Paper</strong> - Write and edit your paper submission</li>
            <li><strong>AI Enhanced</strong> - Optionally add an AI-enhanced version of your submission</li>
        </ul>
        <?php if ( $is_submitted ) { ?>
            <div class="alert alert-success" style="margin-top: 20px;">
                <strong>Status:</strong> Your paper has been submitted.
            </div>
        <?php } else { ?>
            <div class="alert alert-info" style="margin-top: 20px;">
                <strong>Status:</strong> Your paper has not been submitted yet. Use the Paper and AI Enhanced sections to write and submit your paper.
            </div>
        <?php } ?>
        
        <?php if ( $is_submitted && $resubmit_allowed ) { ?>
            <div style="margin-top: 20px;">
                <button type="button" class="btn btn-warning" id="reset-submission-btn">Reset Submission</button>
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
        
        // Set Main as active by default
        $('.tsugi-nav-link[data-section="main"]').addClass('active');
        
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
        
        // Handle Reset Submission button click
        $('#reset-submission-btn').on('click', function(e) {
            e.preventDefault();
            if ( confirm('Are you sure you want to reset your submission? This will delete all your submission content, AI enhanced content, and all comments. This action cannot be undone.') ) {
                $('#hidden-reset-btn').click();
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
