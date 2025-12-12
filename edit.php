<?php
require_once "../config.php";

// The Tsugi PHP API Documentation is available at:
// http://do1.dr-chuck.com/tsugi/phpdoc/

use \Tsugi\Util\U;
use \Tsugi\Util\Net;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\UI\SettingsForm;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();

// If settings were updated
if ( SettingsForm::handleSettingsPost() ) {
    $_SESSION["success"] = "Settings updated.";
    header( 'Location: '.addSession('edit.php') ) ;
    return;
}

// Handle Post Data
$p = $CFG->dbprefix;
$old_content = $LAUNCH->result->getJsonKey('content', '');

if ( count($_POST) > 0 ) {
    $LAUNCH->result->setJsonKey('content', U::get($_POST, 'content') );
    $LAUNCH->result->notifyReadyToGrade();
    $_SESSION['success'] = 'Updated';
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
// https://github.com/jitbit/HtmlSanitizer

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

?>
    <div id="spinner"><img src="<?= $OUTPUT->getSpinnerUrl() ?>"/></div>
    <div id="editor_div" style="display: none;">
    <form method="post">
        <textarea name="content" id="editor">
        </textarea>
        <p><input type="submit" value="Submit"></p>
    </form>
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
            // 'imageUpload',
            'blockQuote',
            'insertTable',
            'mediaEmbed',
            'undo',
            'redo'
        ]
    },
    
}

$(document).ready( function () {
    $.get('<?= addSession('load_text.php') ?>', function(data) {
      console.log(data);
      var html = HtmlSanitizer.SanitizeHtml(data);
      console.log(html);
      $('#editor').html(html);
      $('#output_div').html(html);
      ClassicEditor
            .create( document.querySelector( '#editor' ) ,
                {
                }
            ).then(editor => { 
                // editor.isReadOnly = true;
                $('#spinner').hide();
                $('#editor_div').show();
            } ).catch( error => {
                console.error( error );
            } );
    })
  }
);
</script>
<?php
$OUTPUT->footerEnd();

