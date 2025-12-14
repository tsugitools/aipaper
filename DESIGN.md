MiniPaper Design
================

This tool is designed to collect and grade short papers that were written with the assistance of AI. It is similar to the `ckpaper` application but uses comment streams for feedback instead of annotations.

## Current Implementation

### User Interface

The tool uses a menu-based navigation system (not tabbed dialogs) with the following sections:
- **Main**: Overview with points, review count, and submission status
- **Instructions**: Assignment instructions/rubric (editable by instructor, viewable by students)
- **Paper**: CKEditor for writing/editing the original paper (editable until submitted)
- **AI Enhanced**: CKEditor for AI-enhanced version (optional, editable until submitted)
- **Review**: List of other students' submissions available for review

### Paper Submission

- Students write papers using CKEditor 5.0 with rich text editing
- Papers can be saved as drafts without submitting
- Once submitted, papers are locked from editing (unless reset is enabled)
- Submitted papers are displayed in readonly CKEditor instances
- Both original paper and AI-enhanced version (if available) are shown with show/hide toggles

### Comment System

- Comments are displayed in reverse chronological order
- Comment types: student, instructor (marked as "Staff"), and AI (marked as "AI")
- All comments are visible to all users (no filtering by type in current implementation)
- Comments are soft-deleted when a submission is reset (hidden from students, visible to instructors with indication)
- Soft-deleted comments still count for points for the student who made them
- **Comment Moderation**: Instructors can hide/show individual comments using trash can icon (soft-delete toggle)
- **Flagging**: Anyone can flag comments or submissions; only instructors can unflag
- Flagged items are indicated with red flag icons (accessible design with size, bold, border cues for color-blind users)

### Peer Review Workflow

- Students can review other students' submissions after submitting their own paper
- Review list is paginated (10 submissions per page)
- Review list prioritization:
  1. Submissions the student has already commented on (my_comments >= 1)
  2. Then by oldest submission date
  3. Then by fewest total comments
- Review list filtering logic:
  - If min_comments == 0: show all submissions
  - If reviewed_count < min_comments: show submissions where user hasn't reached min_comments OR submissions they've already commented on
  - If reviewed_count >= min_comments and allowall is checked: show all submissions
  - If reviewed_count >= min_comments and allowall is NOT checked: only show submissions user has already commented on
- Students can click "Review" to view a submission and add comments
- Review page preserves navigation context (returns to grade-detail.php if accessed from there, or index.php with review_page if accessed from student view)

### Grading System

**Point Calculation:**
- Overall points = instructor_points + submit_points + (comment_points × min_comments)
- Earned points include:
  - Submit points (if paper is submitted)
  - Comment points (capped at min_comments - no extra points for exceeding minimum)
  - Instructor points (assigned by instructor as integer from 0 to instructor_points)

**Grade Sending:**
- Grades are automatically sent to LMS whenever:
  - A student views their main page (if overall_points > 0)
  - A student submits a comment
  - An instructor assigns or updates instructor points
- Grades are only sent if overall_points > 0
- Grade is calculated as earned_points / overall_points (0.0 to 1.0 for LTI)

### Reset Submission

- When enabled, students can reset their submission
- Reset makes the paper editable again
- All comments on the submission are soft-deleted (deleted = 1)
- Soft-deleted comments are hidden from students but visible to instructors with "Soft Deleted" badge
- Soft-deleted comments still count for points for the comment author
- Paper content is preserved (not deleted)

### Instructor Features

- **Settings**: Configure all assignment settings including points, comment requirements, and visibility options
- **Instructions Editor**: Edit assignment instructions/rubric using CKEditor
- **Student Data**: View all student submissions, grades, and analytics with:
  - Pagination (20 students per page)
  - Sortable columns (Name, Email, Grade, Updated, Comments Given, Comments Received, Flags, Deleted Comments)
  - Search by name/email
  - Search by generated name (when userealnames is false)
  - Default sort: Flags DESC, then Name ASC
  - Displays: Comments Given, Comments Received, Flags (includes submission flags), Deleted Comments
- **Grade Detail**: View individual student details including:
  - Points earned / points possible
  - Review count (number of students reviewed)
  - Flag information (flagged comments count and submission flag status, if any)
  - Assign instructor points (integer from 0 to instructor_points)
  - Review Submission button (links to review.php)
  - Reset Submission button
- **Review Interface**: Instructors can review any student submission from grade-detail.php or review.php
- **Comments**: Instructor comments are marked as "Staff" type
- **Comment Moderation**: Hide/show individual comments (soft-delete toggle) with trash can icon
- **Flag Management**: Flag/unflag comments and submissions; view flag counts in Student Data

### Data Model

**`aipaper_result` table:**
- One-to-one relationship with `lti_result`
- Columns:
  - `result_id` (foreign key to `lti_result`)
  - `raw_submission` (TEXT) - original paper submission
  - `ai_enhanced_submission` (TEXT) - AI-enhanced version (optional)
  - `submitted` (TINYINT(1)) - submission status
  - `flagged` (TINYINT(1)) - flag status
  - `flagged_by` (INTEGER) - user_id who flagged the submission
  - `json` (TEXT) - additional JSON data

**`aipaper_comment` table:**
- Many-to-many relationship with `lti_result` (through `result_id`)
- Columns:
  - `comment_id` (primary key)
  - `result_id` (foreign key to `lti_result`)
  - `user_id` (foreign key to `lti_user`)
  - `comment_text` (TEXT) - comment content
  - `comment_type` (ENUM: 'student', 'instructor', 'AI')
  - `created_at` (DATETIME)
  - `deleted` (TINYINT(1)) - soft delete flag
  - `flagged` (TINYINT(1)) - flag status
  - `flagged_by` (INTEGER) - user_id who flagged the comment

**Settings stored in `lti_link` settings:**
- `instructions` - assignment instructions/rubric
- `submitpoints` - points for submitting paper
- `instructorpoints` - points instructor can award
- `commentpoints` - points per comment
- `mincomments` - minimum comments required
- `userealnames` - use actual student names (if false, students see generated names, instructors see real names with generated names in parentheses)
- `allowall` - allow students to see all submissions after minimum met
- `resubmit` - allow students to reset and resubmit
- `due_date` - optional due date

## Configuration Options

- **Instructions / Rubric**: Editable by instructor in the main interface, viewable by students
- **Submit points**: Points awarded for submitting a paper (can be zero)
- **Instructor grade points**: Points awarded by instructor (can be zero, assigned as integer 0 to instructor_points)
- **Points earned for each comment**: Points earned per comment on another student's submission (can be zero)
- **Minimum number of comments per student**: Required number of comments each student must make (can be zero). If zero, students can comment on any submission.
- **Use actual student names**: Checkbox to use actual student names instead of generated names
- **Allow students to see and comment on all submissions after the minimum has been met**: Checkbox to control visibility after minimum is met
- **Allow students to reset and resubmit their papers**: Checkbox to enable resubmission capability
- **Due date**: Optional due date for the assignment

**Note**: Overall points = instructor_points + submit_points + (comment_points × min_comments). Grades will only be sent for this activity if overall_points > 0.

## Not Yet Implemented

The following features from the original design are not yet implemented:
- Like capability for comments (database table exists but not used)
- AI API integration (automatic AI comment generation on submission)
- Auto-grading after maximum duration (time-based auto-grade feature)
- Tabbed dialog interface (currently uses menu-based navigation)

## Recently Implemented Features

- **Flag System**: Comments and submissions can be flagged by anyone; only instructors can unflag. Flagged items are displayed with red flag icons and included in flag counts.
- **Comment Moderation**: Instructors can hide/show individual comments using trash can icons (soft-delete toggle).
- **Generated Name Search**: When `userealnames` is false, instructors can search by generated name. Results appear in the main Student Data table with sorting disabled.
- **Enhanced Student Data Page**: Pagination (20 per page), sortable columns, search by name/email, search by generated name, flag counts, deleted comment counts.
- **Navigation Context Preservation**: Pagination, sorting, and search state preserved when navigating between grades.php, grade-detail.php, and review.php.

## Technical Details

- Uses CKEditor 5.0 for rich text editing
- Uses HtmlSanitizer.js for safe HTML display
- Point calculations centralized in `points-util.php`
- LTI grade sending handled automatically via `sendGradeToLTI()` function
- Supports basic launch analytics like other Tsugi tools






