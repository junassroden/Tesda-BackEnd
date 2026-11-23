# Prototype database setup

This prototype uses standalone SQL setup scripts for training centers, course details, and center applications. These scripts do not add or change framework migrations.

1. Apply migrations `005` and `006` to the selected database.
2. Run `prototype_course_features.sql` once in that same database, for example through phpMyAdmin's SQL or Import page.
3. Run `prototype_center_applications.sql` to create the application review table. It uses `CREATE TABLE IF NOT EXISTS`, so it is safe to run again.
4. Run `seed.sql` to add or refresh the sample accounts and starter courses.
5. Run `document_archive.sql` to create the TESDA main-office archive and its private document index. This is a standalone SQL script, not a framework migration.

After applying `document_archive.sql`, use **Manage users** to create or assign a **Center organizer** account to **TESDA Main Office**. Only central administrators and organizers assigned to that office can use the document archive. Other center organizers, instructors, and students are denied. Stored files live under `runtime/document-archive`, which is blocked from direct web access; downloads pass through the authenticated route.

The archive accepts PDF files up to 20 pages, plus the same image formats as the OCR lab. Each PDF page is rendered and analyzed by the existing CNN and OCR pipeline. The archive saves the original file, extracted text, OCR errors, and model labels, then supports local filename/OCR-text search. If a search has no exact match and there is only one archived file, the assistant offers that file as a candidate. The document assistant uses local search and deterministic replies, not an external or generative AI service. It keeps up to 10 chat turns and recent document IDs in the authenticated session; the transcript does not store OCR text, and **Clear** or logout removes that session memory. The 10 MB upload limit applies to both PDFs and images.

The setup script creates demo centers for NCR, Central Luzon, and Central Visayas. The starter course days, schedules, and outlines are examples for the NCR demo center, not official TESDA schedules or training regulations. Replace them with the details approved by each center. Administrators can add more centers in the portal and assign centers to staff, students, and course offerings.

Course lecture materials are shared as HTTP or HTTPS links, such as links to files stored in the center's existing file-sharing service.

TESDA central administrators appoint center organizers from **Manage users** by creating a **Center organizer** account and assigning a training center. Organizers can review that center's student and instructor applications and manage only that center's accounts and courses. Applicants use `/apply`, select Student or Instructor and an active center, and cannot log in until the selected center approves the application.

The public API registration endpoint follows the same approval flow. Include `full_name`, `username`, `email`, `password`, `role` (`student` or `instructor`), and an active `training_center_id`; registration creates an inactive pending application rather than a login-ready account.

## Training management upgrade (courses, batches, documents, classroom)

Run `training_management.sql` once on a database that already has `tesda_nexus.sql`. It only adds columns, constraints and tables and keeps every existing row, and it checks `information_schema` before each change, so running it again is safe. It needs MySQL 8.0.16 or later.

What it adds:

- **Courses**: estimated training days, duration (weeks or months) and training mode (face-to-face, online, hybrid), next to the existing total hours.
- **Batches**: schedule (training days of the week, daily start and end time, venue, session frequency, total hours, number of training days, mode) and lifecycle (`suspended` status, original end date, completion date, who completed it and whether it was automatic, and "require my confirmation").
- **Attendance**: `batch_sessions` (one row per training day, with hours) and `batch_attendance` (present, late, absent, excused per trainee). Recorded hours are the batch's training progress.
- **Membership history**: `batch_students` keeps trainees who were removed or transferred, with the reason and the batch they moved to.
- **Student documents**: requirement types now carry the number of copies and an optional condition (the marriage contract applies only to married students). `student_documents` keeps one row per student and center. Uploaded copies are in `student_document_files`, and replaced copies are kept. Every upload, review, verification, rejection and reuse is written to `student_document_reviews`.
- **Virtual classroom**: `classroom_items` (materials and assignments), `classroom_submissions` and `classroom_files`. Stream posts use the existing `announcements.batch_id`. Classroom membership is not stored: the batch's teacher and its active or completed trainees are the members. Enrolling, removing or transferring a trainee changes access straight away.

Uploaded files are stored outside the web root, under `runtime/student-documents` and `runtime/classroom-files` (both blocked by `runtime/.htaccess`). Each download goes through an authenticated route that checks the user's role and center first.

### Batch lifecycle job

A batch starts on its start date. It completes automatically only when **all** of these are true: the end date has passed (an extension moves the end date), the batch is not suspended, it does not require an administrator's confirmation, and the recorded training hours reach the required hours (the batch's own hours, otherwise the course's). A batch past its end date that misses any of these stays active and shows as **Needs review** until the center extends it or confirms completion. Completed and cancelled batches move to Batch history with all their records.

The API runs this check whenever batch lists load. To run it on a schedule as well, add a daily Windows Task Scheduler task:

- Program: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Arguments: `lava batches:lifecycle`
- Start in: `C:\laragon\www\TesdaNexus\Back-End`

`php lava batches:lifecycle --date=2027-03-16` evaluates the batches as of another date, for checking.

### Who can do what

| Area | Main office admin | Learning center admin | Teacher | Student |
|---|---|---|---|---|
| Courses, batches, schedules, lifecycle | Read only (all centers) | Manage (own center) | Read (own center) | Their own batches |
| Enrollment, transfers, removal | — | Own center | — | — |
| Attendance | Read only | Own center | Batches assigned to them | Their own |
| Student records and documents | — | Own center: review, verify, print | — | Their own: upload and replace |
| Classroom | — | — | Batches assigned to them | Batches they are in |

These rules are enforced in the controllers, so hiding a button is never the only check. Another center's records return 404.
