MiniPaper
---------

This tool is designed to collect and grade short papers that were written with the assistance of AI. It is similar to the `ckpaper` application but uses comment streams for feedback instead of annotations.

## Features

- **Paper Submission**: Students create papers using CKEditor 5.0 with support for:
  - Original paper submission
  - AI-enhanced paper (optional)
  - Assignment instructions/rubric (editable by instructor)

- **Comment Streams**: Feedback is provided through comment streams with:
  - Filtering options (all comments, student comments, instructor comments, AI comments)
  - Flag capability for submissions and comments
  - Support for student, instructor, and AI-generated comments

- **Grading Options**:
  - Instructor grading (points-based)
  - Peer review points (students earn points by commenting on other students' submissions)
  - Configurable point allocation

- **AI Integration**: Optional AI API integration that can automatically generate comments when a student submits their paper

- **Resubmission**: Optional ability for students to reset and resubmit their papers (configurable by instructor)

- **Auto-grading**: Maximum duration feature that allows students to complete grading if instructor hasn't graded within a set time period

## Configuration Options

- Instructions/Rubric text
- Overall points
- Instructor grade points
- Points earned per comment (one per submission)
- Peer review visibility (all students or limited number)
- Resubmission enabled/disabled
- AI API integration settings

## Technical Details

The tool uses CKEditor 5.0 for rich text editing and stores data in dedicated database tables (`aipaper_result` and `aipaper_comment`) for efficient querying and management.

You can use this in your LMS via TsugiCloud with a free key or if you have a campus Tsugi installation, it is available in the Manage Installed Modules admin section.
