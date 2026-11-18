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
