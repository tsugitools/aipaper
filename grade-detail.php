<?php
require_once "../config.php";
\Tsugi\Core\LTIX::getConnection();

use \Tsugi\Util\U;
use \Tsugi\Util\LTI13;
use \Tsugi\UI\Table;
use \Tsugi\Core\Annotate;
use \Tsugi\Core\Result;
use \Tsugi\Core\LTIX;
use \Tsugi\Grades\GradeUtil;

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

$annotations = Annotate::loadAnnotations($LAUNCH, $user_id);
$content = $LAUNCH->result->getJsonKeyForUser('content', '', $user_id);

// Load and parse the old JSON
$json = $LAUNCH->result->getJsonForUser($user_id);
if ( is_string($json) ) $json = json_decode($json);
if ( ! is_object($json) ) $json = new \stdClass();

$old_lock = isset($json->lock) && $json->lock;

$old_grade = $row ? $row['grade'] : 0;
$old_percent = (int) ($old_grade * 100);

$inst_note = $LAUNCH->result->getNote($user_id);

$gradeurl = Table::makeUrl('grade-detail.php', $getparms);
$gradesurl = Table::makeUrl('grades.php', $getparms);

// Handle incoming post to set the instructor points and update the grade
if ( isset($_POST['instSubmit']) || isset($_POST['instSubmitAdvance']) ) {

    $percent = U::get($_POST, 'percent');
    if ( U::strlen($percent) == 0 || $percent === null ) {
        $percent = null;
    } else if ( is_numeric($percent) ) {
        $percent = $percent + 0;
    } else {
        $_SESSION['error'] = "Points must either by a number or blank.";
        header( 'Location: '.addSession($gradeurl) ) ;
        return;
    }
    $computed_grade = $percent / 100.0;

    $success = '';

    $inst_note = U::get($_POST, 'inst_note');

    $result = Result::lookupResultBypass($user_id);
    $result['grade'] = -1; // Force resend
    $debug_log = array();
    $extra13 = array(
        LTI13::ACTIVITY_PROGRESS => LTI13::ACTIVITY_PROGRESS_COMPLETED,
        LTI13::GRADING_PROGRESS => LTI13::GRADING_PROGRESS_FULLYGRADED,
    );
    if ( is_string($inst_note) && strlen($inst_note) > 1 ) {
        $extra13[LTI13::LINEITEM_COMMENT] = $inst_note;
    }

    $status = $LAUNCH->result->gradeSend($computed_grade, $result, $debug_log, $extra13);
    if ( $status === true ) {
        if ( U::strlen($success) > 0 ) $success .= ', ';
        $success .= 'Grade submitted to server';
    } else {
        error_log("Problem sending grade ".$status);
        $_SESSION['error'] = 'Error sending grade to: '.$status;
        $_SESSION['debug_log'] = $debug_log;
    }

    $update_json = false;
    if ( U::get($_POST, 'reset') == 'on' ) {
        $json->annotations = array();
        if ( U::strlen($success) > 0 ) $success .= ', ';
        $success .= 'Annotations reset';
        $update_json = true;
    }

    $new_lock = U::get($_POST, 'lock') == 'on';
    if ( $new_lock != $old_lock ) {
        $json->lock = $new_lock;
        if ( U::strlen($success) > 0 ) $success .= ', ';
        $success .= $new_lock ? 'Assignment locked' : 'Assignment unlocked';
        $update_json = true;
    }

    if ( $update_json ) {
        $json = json_encode($json);
        $LAUNCH->result->setJsonForUser($json, $user_id);
    }

    $inst_note = U::get($_POST, 'inst_note');
    $LAUNCH->result->setNote(U::get($_POST, 'inst_note'), $user_id );

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

// Show the basic info for this user
GradeUtil::gradeShowInfo($row, false);

echo("<p>Annotation count: ".count($annotations)."</p>\n");

if ( U::strlen($content) > 0 ) {
    $next = Table::makeUrl('grade-detail.php', $getparms);
    echo('<p><a href="index.php?user_id='.$user_id.'&next='.urlencode($next).'">');
    echo(__('View / Annotate Submission'));
    echo("</a><p>\n");
}

$next_user_id_ungraded = false;

$inst_note = $LAUNCH->result->getNote($user_id);

echo('<form method="post">
      <input type="hidden" name="user_id" value="'.$user_id.'">');

if ( $next_user_id_ungraded !== false ) {
      echo('<input type="hidden" name="next_user_id_ungraded" value="'.$next_user_id_ungraded.'">');
}

echo('<label for="percent">Percentage (0-100)</label>
      <input type="number" name="percent" id="grade" min="0" max="100" value="'.$old_percent.'"/><br/>');

echo('<label for="lock">Student Submission Locked:</label>
      <input type="checkbox" name="lock" id="lock"'.
      ($old_lock ? ' checked ' : '')
      .'/><br/>');

echo('<label for="reset">Reset Annotations:</label>
      <input type="checkbox" name="reset" id="reset"
      onclick="return confirm(\'Are you sure you want to reset the annotations?\');" /><br/>');

echo('<label for="inst_note">Instructor Note To Student</label><br/>
      <textarea name="inst_note" id="inst_note" style="width:60%" rows="5">');
echo(htmlentities($inst_note??''));
echo('</textarea><br/>
      <input type="submit" name="instSubmit" value="Update" class="btn btn-primary">');

if ( $next_user_id_ungraded !== false ) {
    echo(' <input type="submit" name="instSubmitAdvance" value="Update and Go To Next Ungraded Student" class="btn btn-primary">');
}
echo('</form>');




$OUTPUT->footer();
