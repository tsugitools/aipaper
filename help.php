<?php
require_once "../config.php";

use \Tsugi\Core\LTIX;

// Try to get LTI session, but don't require it
$LAUNCH = LTIX::session_start();
$has_session = false;
$is_instructor = false;

if ( $LAUNCH && isset($LAUNCH->user) && isset($LAUNCH->context) && isset($LAUNCH->link) ) {
    $has_session = true;
    $is_instructor = isset($LAUNCH->user->instructor) && $LAUNCH->user->instructor;
}

$menu = false;
if ( $has_session ) {
    $menu = new \Tsugi\UI\MenuSet();
    $menu->addLeft(__('Back'), 'index.php');
}

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
if ( $menu ) {
    $OUTPUT->topNav($menu);
    $OUTPUT->flashMessages();
    $OUTPUT->welcomeUserCourse();
}

?>
<h2>MiniPaper Tool - Instructor Guide</h2>

<h4>Settings</h4>
<p>Configure the assignment settings including:</p>
<ul>
    <li><strong>Submit points:</strong> Points students earn for submitting their paper (can be zero)</li>
    <li><strong>Instructor grade points:</strong> Points you will award for the assignment (can be zero)</li>
    <li><strong>Points earned for each comment:</strong> Points students earn per comment on other submissions (can be zero)</li>
    <li><strong>Minimum number of comments per student:</strong> Required comments each student must make (can be zero). If zero, students can comment on any submission.</li>
    <li><strong>Use actual student names:</strong> Display real names instead of generated names</li>
    <li><strong>Allow students to see and comment on all submissions after the minimum has been met:</strong> When checked, students who have met the minimum comment requirement can see and comment on all submissions. When unchecked, they can only see submissions they have already commented on.</li>
    <li><strong>Allow students to reset and resubmit their papers:</strong> Enables the Reset Submission feature. When a student resets, their submission becomes editable again and comments are soft-deleted (hidden from students but visible to instructors).</li>
    <li><strong>Due date:</strong> Optional due date for the assignment</li>
</ul>

<h4>Instructions / Rubric</h4>
<p>Edit the assignment instructions and rubric that students will see. Use the rich text editor to format your content. This is displayed to students in the Instructions section.</p>

<h4>Student Workflow</h4>
<p>Students can:</p>
<ul>
    <li>Write and edit their paper using CKEditor 5.0</li>
    <li>Save drafts without submitting</li>
    <li>Submit their paper (which locks it from editing unless reset is enabled)</li>
    <li>View their submitted paper and AI-enhanced version (if available)</li>
    <li>See comments from instructors, other students, and AI</li>
    <li>Review other students' submissions and add comments</li>
    <li>View their points and review count on the main page</li>
</ul>

<h4>Peer Review System</h4>
<p>The review system prioritizes submissions to help students meet the minimum comment requirement:</p>
<ul>
    <li>Submissions the student has already commented on appear first</li>
    <li>Then submissions that need more comments (oldest submissions with fewest comments)</li>
    <li>If minimum comments is set and not met, students see submissions that help them reach the minimum</li>
    <li>After meeting the minimum, visibility depends on the "Allow students to see all submissions" setting</li>
    <li>Review list is paginated (10 submissions per page)</li>
</ul>

<h4>Grading</h4>
<p><strong>Point Calculation:</strong></p>
<p>Overall points = instructor_points + submit_points + (comment_points × min_comments)</p>
<p>Earned points include:</p>
<ul>
    <li>Submit points (if paper is submitted)</li>
    <li>Comment points (capped at min_comments - no extra points for exceeding minimum)</li>
    <li>Instructor points (assigned by instructor, 0 to instructor_points)</li>
</ul>
<p>Grades are automatically sent to the LMS whenever:</p>
<ul>
    <li>A student views their main page (if overall_points > 0)</li>
    <li>A student submits a comment</li>
    <li>An instructor assigns or updates instructor points</li>
</ul>
<p>Grades will only be sent if overall_points > 0.</p>

<h4>Instructor Features</h4>
<ul>
    <li><strong>Student Data:</strong> View all student submissions, grades, and analytics from the Student Data menu</li>
    <li><strong>Grade Detail:</strong> View individual student details including points, review count, and assign instructor points</li>
    <li><strong>Review Submission:</strong> Click "Review Submission" from grade detail to view and comment on a student's paper</li>
    <li><strong>Reset Submission:</strong> Reset a student's submission (soft-deletes comments but keeps paper content)</li>
    <li><strong>Comments:</strong> All instructor comments are marked as "Staff" and visible to students</li>
    <li><strong>Soft-Deleted Comments:</strong> When a submission is reset, comments are soft-deleted (visible to instructors with indication, hidden from students)</li>
</ul>

<h4>Test Data</h4>
<p>If your LTI key is '12345', you can access a test data generator from the Instructor menu to create 100 dummy users with submitted papers for testing the review system.</p>

<?php
if ( !$has_session ) {
    ?>
    <p><em>Note: This help page is publicly accessible. To use the tool, you need to access it through your learning management system.</em></p>
    <?php
}

$OUTPUT->footerStart();
$OUTPUT->footerEnd();

