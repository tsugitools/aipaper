<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\UI\SettingsForm;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();
$p = $CFG->dbprefix;

// If settings were updated
if ( SettingsForm::handleSettingsPost() ) {
    $_SESSION["success"] = "Settings updated.";
    header( 'Location: '.addSession('edit.php') ) ;
    return;
}

// Get result_id for this user
$result_id = $RESULT->id;
if ( ! $result_id ) {
    $_SESSION['error'] = 'No result record found';
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

// Load or create aipaper_result record
$paper_row = $PDOX->rowDie(
    "SELECT raw_submission, ai_enhanced_submission, flagged, flagged_by
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
        'flagged' => false,
        'flagged_by' => null
    );
}

// Check if submitted (has raw_submission content)
$is_submitted = U::isNotEmpty($paper_row['raw_submission']);

// Check if resubmit is allowed
$resubmit_allowed = Settings::linkGet('resubmit', false);
$can_edit = !$is_submitted || $resubmit_allowed || $USER->instructor;

// Load instructions from settings
$instructions = Settings::linkGet('instructions', '');

// Handle POST submission
if ( count($_POST) > 0 && isset($_POST['submit_paper']) ) {
    if ( !$can_edit && !$USER->instructor ) {
        $_SESSION['error'] = 'Submission is locked';
        header( 'Location: '.addSession('edit.php') ) ;
        return;
    }

    $raw_submission = U::get($_POST, 'raw_submission', '');
    $ai_enhanced = U::get($_POST, 'ai_enhanced_submission', '');
    
    // For instructors, save instructions
    if ( $USER->instructor ) {
        $instructions = U::get($_POST, 'instructions', '');
        Settings::linkSet('instructions', $instructions);
    }

    // Update aipaper_result
    $PDOX->queryDie(
        "UPDATE {$p}aipaper_result 
         SET raw_submission = :RAW, ai_enhanced_submission = :AI, 
             updated_at = NOW()
         WHERE result_id = :RID",
        array(
            ':RAW' => $raw_submission,
            ':AI' => $ai_enhanced,
            ':RID' => $result_id
        )
    );

    if ( !$is_submitted && U::isNotEmpty($raw_submission) ) {
        // First submission - notify ready to grade
        $RESULT->notifyReadyToGrade();
    }

    $_SESSION['success'] = $USER->instructor ? 'Instructions updated' : 'Paper submitted';
    header( 'Location: '.addSession('index.php') ) ;
    return;
} 

$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('Main'), 'index');

if ( $USER->instructor ) {
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Student Data'), 'grades');
    $submenu->addLink(__('Settings'), '#', /* push */ false, SettingsForm::attr());
    if ( $CFG->launchactivity ) {
        $submenu->addLink(__('Analytics'), 'analytics');
    }
    $menu->addRight(__('Instructor'), $submenu);
} else {
    $menu->addRight(__('Settings'), '#', /* push */ false, SettingsForm::attr());
}

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

?>
<style>
.nav-tabs { margin-bottom: 20px; }
.tab-content { padding: 20px; border: 1px solid #ddd; border-top: none; min-height: 400px; }
.ckeditor-container { min-height: 400px; }
</style>

<?php if ( $USER->instructor ) { ?>
    <!-- Instructor: Instructions tab only -->
    <ul class="nav nav-tabs" role="tablist">
        <li role="presentation" class="active">
            <a href="#instructions" aria-controls="instructions" role="tab" data-toggle="tab">Instructions</a>
        </li>
    </ul>
    <div class="tab-content">
        <div role="tabpanel" class="tab-pane active" id="instructions">
    <form method="post">
                <div class="ckeditor-container">
                    <textarea name="instructions" id="editor_instructions"><?= htmlentities($instructions ?? '') ?></textarea>
                </div>
                <p><input type="submit" name="submit_paper" value="Save Instructions" class="btn btn-primary"></p>
            </form>
        </div>
    </div>
<?php } else { ?>
    <!-- Student: Three tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li role="presentation" class="active">
            <a href="#submission" aria-controls="submission" role="tab" data-toggle="tab">Submission</a>
        </li>
        <li role="presentation">
            <a href="#ai_enhanced" aria-controls="ai_enhanced" role="tab" data-toggle="tab">AI Enhanced Submission</a>
        </li>
        <li role="presentation">
            <a href="#instructions" aria-controls="instructions" role="tab" data-toggle="tab">Instructions</a>
        </li>
    </ul>
    <div class="tab-content">
        <form method="post" id="paper_form">
        <div role="tabpanel" class="tab-pane active" id="submission">
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
        <div role="tabpanel" class="tab-pane" id="ai_enhanced">
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
        <div role="tabpanel" class="tab-pane" id="instructions">
            <div class="ckeditor-container">
                <div id="display_instructions"><?= htmlentities($instructions ?? '') ?></div>
            </div>
            <p><em>Read-only instructions from your instructor.</em></p>
        </div>
        <?php if ( $can_edit ) { ?>
            <p style="margin-top: 20px;">
                <input type="submit" name="submit_paper" value="<?= $is_submitted ? 'Update Submission' : 'Submit Paper' ?>" class="btn btn-primary">
            </p>
        <?php } ?>
    </form>
    </div>
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
});
</script>
<?php
$OUTPUT->footerEnd();
