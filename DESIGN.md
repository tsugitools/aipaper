MiniPaper Design
================

This tool is designed to collect and grade short papers that were written with the assistance of AI.  It will be loke the `ckpaper` application except no annotation.   The feedback will be in the form of comment streams.

There will be a tabbed dialog box with three CKEditor placements: (1) Original paper, (2) AI Enhanced paper (optional),
(3) Comments from the author of the paper (optional), (4) the instructions for the assignment - editable by the instructor and viewable by the student.

The comments area will also have an option to show all comments, comments form other students (if enabled), commments from the instructor, 
and comments from AI if enabled.  The defaukt will be to show all comments.  There will be a simple "like capablity".  There wll also be a flag
capability both for the original submission and for any comment.  If a reviewer / commentor is flagging the submission it is not shown to other students - just to instructors.  If a submittor is flagging a comment it is shown to the submittor and instructor but not the person
being flagged.

There are several ways to grade.   The instructor can grade a certain number of points including zero.  The submittor can earn some points
up to a maximum by commenting on other students submissions.  To earn review points, the student must comment on some number of other student
submissions.  

The tool will have an optional integration with an AI API if a URL, key, and protocol /  server type used.  If the API integration is 
set up - as soon as the sudent submits the paper, it will be sent to AI and AI wil generate a comment.

The tool will have an option to let the student reset and resubmit their paper.  The option can be enabled by the instructor.
If this option is used, the students resets theirsubmission to a blank state.  All comments (including AI comments) on their submission
are deleted and the process restarts.  They keep any points they have earned from comments they have made on other students papers.

The tool will have a maximum duration that if instructor grading is enabled and sufficient time has passed.  When the student comes
into the tool, they are presented with a button to complete gradering which gives the student the instructor grade.  This will be used
for situations where the instructor might no get there in time.

In terms of data model, we will make a table called `aipaper_result` which will have a one-to-one relationship with `lti_result`
and a foreign key relationship that deletes the row in `aipaper_result` if the `lti_result` row is ever deleted.  This way instead
of storing all the text boxes in JSON in `lti_result` we can do queries on the additional columns needed for this application.
Columns will include text fields for the raw submission, AI-Enhanced submission, and the students comment / reflection.  The
`aipaper_result` table will also have a JSON column, for who nows what over time

There will be a many to many table between `lti_result` and `aipaper_comment` - each comment will have text, type (student, instructor, AI),
and a flag field.

We will store the configuration choices made by the instructor in the `lti_tool` settings area.

This tool will support the basic launch analytics like other tools.

Here are the configuration options:

- **Instructions / Rubric**: Editable by instructor in the main interface, viewable by students
- **Instructor grade points**: Points awarded by instructor (can be zero)
- **Points earned for each comment**: Points earned per comment on another student's submission (can be zero)
- **Minimum number of comments per student**: Required number of comments each student must make (can be zero)
- **Note**: Overall points = instructor_points + (comment_points * min_comments). Grades will only be sent for this activity if overall_points > 0.
- **Use actual student names**: Checkbox to use actual student names instead of generated names
- **Allow students to see and comment on all submissions after the minimum has been met**: Checkbox to allow students to see and comment on all submissions after meeting the minimum comment requirement
- **Allow students to reset and resubmit their papers**: Checkbox to enable resubmission capability
- **Due date**: Optional due date for the assignment






