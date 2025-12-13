<?php
require_once "../config.php";

use \Tsugi\Core\LTIX;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();

$menu = new \Tsugi\UI\MenuSet();
$menu->addLeft(__('Back'), 'index.php');

// Render view
$OUTPUT->header();
$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

$OUTPUT->welcomeUserCourse();

if ( $USER->instructor ) {
    ?>
    <h2>MiniPaper Tool - Instructor Guide</h2>
    
    <h4>Settings</h4>
    <p>Configure the assignment settings including:</p>
    <ul>
        <li><strong>Instructor grade points:</strong> Points you will award for the assignment (can be zero)</li>
        <li><strong>Points earned for each comment:</strong> Points students earn per comment on other submissions (can be zero)</li>
        <li><strong>Minimum number of comments per student:</strong> Required comments each student must make (can be zero)</li>
        <li><strong>Use actual student names:</strong> Display real names instead of generated names</li>
        <li><strong>Allow students to see and comment on all submissions after the minimum has been met:</strong> Controls visibility of all submissions</li>
        <li><strong>Allow students to reset and resubmit their papers:</strong> Enables the Reset Submission feature</li>
    </ul>
    
    <h4>Instructions / Rubric</h4>
    <p>Edit the assignment instructions and rubric that students will see. Use the rich text editor to format your content.</p>
    
    <h4>Student Data</h4>
    <p>View all student submissions, grades, and comments from the Student Data menu.</p>
    
    <h4>Grading</h4>
    <p>Overall points = instructor_points + (comment_points × min_comments). Grades will only be sent if overall_points > 0.</p>
    <?php
} else {
    ?>
    <h2>MiniPaper Tool</h2>
    <p>You can edit and submit your paper using this tool. Your teacher can review your submission and provide feedback through comments.</p>
    <?php
}

$OUTPUT->footerStart();
$OUTPUT->footerEnd();

