<?php
require_once "../config.php";

// The Tsugi PHP API Documentation is available at:
// http://do1.dr-chuck.com/tsugi/phpdoc/

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\UI\SettingsForm;
use \Tsugi\UI\Annotate;
use \Tsugi\Core\Annotate as AnnotateModel;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();

// If settings were updated
if ( SettingsForm::handleSettingsPost() ) {
    $_SESSION["success"] = "Settings updated.";
    header( 'Location: '.addSession('index.php') ) ;
    return;
}

$next = U::safe_href(U::get($_GET, 'next', 'edit.php'));
$user_id = U::safe_href(U::get($_GET, 'user_id'));
if ( $user_id && ! $LAUNCH->user->instructor ) {
    http_response_code(403);
    die('Not authorized');
}
if ( ! $user_id ) $user_id = $LAUNCH->user->id;

$inst_note = $LAUNCH->result->getNote($user_id );
$annotations = AnnotateModel::loadAnnotations($LAUNCH, $user_id);

// Load and parse the old JSON
$json = $LAUNCH->result->getJsonForUser($user_id);
$json = json_decode($json);
if ( $json == null ) $json = new \stdClass();
$lock = isset($json->lock) && $json->lock;

$edit_text = __('Edit');
if ( $next != 'edit.php' ) $edit_text = __('Back');
$load_url = $user_id ? 'load_text.php?user_id=' . $user_id : 'load_text.php';

$menu = new \Tsugi\UI\MenuSet();
if ( $lock && ! $LAUNCH->user->instructor ) {
    $menu->addLeft(__('Entry Locked'), false);
} else {
   $menu->addLeft($edit_text, $next);
}
if ( count($annotations) > 0 ) {
    $menu->addRight(__('Annotations:').' '.count($annotations), false);
}

if ( $LAUNCH->user->instructor ) {
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Student Data'), 'grades');
    $submenu->addLink(__('Settings'), '#', /* push */ false, SettingsForm::attr());
    if ( $CFG->launchactivity ) {
        $submenu->addLink(__('Analytics'), 'analytics');
    }
    $menu->addRight(__('Help'), '#', /* push */ false, 'data-toggle="modal" data-target="#helpModal"');
    $menu->addRight(__('Instructor'), $submenu, /* push */ false);
} else {
    if ( U::strlen($inst_note) > 0 ) $menu->addRight(__('Note'), '#', /* push */ false, 'data-toggle="modal" data-target="#noteModal"');
    $menu->addRight(__('Help'), '#', /* push */ false, 'data-toggle="modal" data-target="#helpModal"');
    $menu->addRight(__('Settings'), '#', /* push */ false, SettingsForm::attr());
}

$old_content = $LAUNCH->result->getJsonKeyForUser('content', '', $user_id);

// Render view
$OUTPUT->header();
echo(Annotate::header());
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

SettingsForm::start();
// SettingsForm::checkbox('sendgrade',__('Send a grade'));
?>
<p>This tool uses technology from the
<a href="http://annotatorjs.org/" target="_blank">Annotator JS</a> project and
<a href="https://ckeditor.com/" target="_blank">CKEditor 5.0</a>.
</p>
<?php
SettingsForm::done();
SettingsForm::end();

$OUTPUT->helpModal("Annotation Tool",
    "You can edit and annotate formatted text with this tool.  Your teacher can also annotate your document.
    To annotate, simply highlight text and an edit dialog will pop up so you can add, edit, or delete a comment.");

if ( U::strlen($inst_note) > 0 ) {
    echo($OUTPUT->modalString(__("Instructor Note"), htmlentities($inst_note), "noteModal"));
}

if ( U::strlen($old_content) < 1 ) {
    $OUTPUT->welcomeUserCourse();
    echo("<p>Please edit your submission.</p>\n");
    $OUTPUT->footer();
    return;
}
?>
    <div id="spinner"><img src="<?= $OUTPUT->getSpinnerUrl() ?>"/></div>
    <div id="output_div" style="display: none;">
    </div>
<?php
$OUTPUT->footerStart();
echo(Annotate::footer($user_id));
// https://github.com/jitbit/HtmlSanitizer
?>
<script src="https://cdn.jsdelivr.net/gh/jitbit/HtmlSanitizer@master/HtmlSanitizer.js"></script>
<script type="text/javascript">
$(document).ready( function () {
    $.get('<?= addSession($load_url) ?>', function(data) {
      var html = HtmlSanitizer.SanitizeHtml(data);
      $('#output_div').html(html);
      $('#spinner').hide();
      $('#output_div').show();
      tsugiStartAnnotation('#output_div');
    })
  }
);
</script>
<?php
$OUTPUT->footerEnd();
