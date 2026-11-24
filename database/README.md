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

## Inventory request, approval, QR and delivery upgrade

Run `inventory_delivery.sql` once, after `training_management.sql`. Like that script it checks `information_schema` before each change, so running it again is safe, and it keeps existing rows: earlier requests are mapped to the new statuses (`in_review` → `pending`, `released` → `in_transit`, `received` → `completed`).

It builds on the existing inventory tables instead of adding new ones: `products` (stock), `stock_movements` (inventory transaction history), `inventory_requests` and `inventory_request_items`. It adds:

- **Two main office roles**, `inventory_staff` and `delivery_staff`. Main office administrators create these accounts under **Main office staff**.
- **Request letters**: a letter written in the app (date, recipient, subject, body, signatory) or an uploaded PDF, DOCX or DOC file, plus up to 10 supporting photos (`inventory_request_files`, stored under `runtime/inventory-requests`).
- **Statuses**: draft → pending → approved → processing → ready_for_delivery → in_transit → delivered → completed. A disapproved request has status `rejected`, shown as "Disapproved". Receipt with different quantities sets `discrepancy` until the main office resolves it.
- **QR deliveries** (`inventory_deliveries`, `inventory_delivery_items`). The QR code holds only a random 64-character token. A unique key on `active_request_id` stops a request from having two active QR codes.
- `stock_movements.delivery_id`, which links each release to its delivery.

### When stock changes

| Step | Who | Stock |
|---|---|---|
| Submit, approve, start preparing | Learning center, main office admin, inventory personnel | Unchanged |
| Generate QR code | Inventory personnel | Unchanged. The units are held for this delivery, so other QR codes cannot count them too. |
| Scan, validate, confirm release | Delivery personnel | **Deducted once**, recorded in `stock_movements` and the blockchain ledger |
| Mark delivered, confirm receipt | Delivery personnel, learning center | Unchanged |

The release runs in one transaction. Its first statement claims the delivery only while `status = 'ready' AND qr_used_at IS NULL`, so scanning the same QR code twice, double clicking or sending requests in parallel cannot deduct stock twice. Each item is taken with a conditional `quantity >= n` update, so stock never goes negative. If any item is short, the whole release is rolled back.

The scanner needs HTTPS, or `localhost`, before a browser will open the camera. Without a camera, delivery personnel can enter the delivery reference number (for example `DLV-2026-0001`), which is printed under the QR code.

## Blockchain ledger verification

Run `blockchain_ledger.sql` once, after `inventory_delivery.sql`. Running it again is safe. It adds `blockchain_ledger.payload_format`, tags the existing blocks with how they were built, and allows `batch` blocks.

**What is recorded.** `AuditModel::record()` writes one block per important event. The block is written inside the same database transaction as the change, so a change and its block are always saved together:

| Record | Events |
|---|---|
| Inventory request | submitted, approved, disapproved (`rejected`), receipt confirmed (`received`), `discrepancy`, `resolved` |
| Delivery | QR code `generated`, `released` (stock deducted), `delivered` |
| Student document | `verified` |
| Training batch | `completed` (automatic or confirmed; the CLI job records it too) |
| Archived document | `archived` (with the file's SHA-256, now also saved in `document_archive.file_hash`), `deleted` |
| Allowance request | `released` |

**What a block holds.** A block holds only fingerprints: `data_hash` is the SHA-256 of the event's payload, and `block_hash` is the SHA-256 of `index | type | id | action | data_hash | previous_hash`. It never holds names, documents or bank details. `app/libraries/Ledger.php` builds each payload from facts that never change after the event, such as quantities, timestamps, the people involved and file fingerprints.

**How verification works.** Main office administrators use **Blockchain ledger** in the sidebar:

- **Verify whole chain** walks every block from the genesis block. It checks that the indexes are in order, that each block links to the one before it, and that each block hash matches the block's contents. It names the first broken block.
- **Verify this block** rebuilds the payload from today's records and compares fingerprints. For files (archived documents, verified student documents, request letters and photos) it also re-hashes the stored file on disk. Each result is saved in `blockchain_verifications`.

| Result | Meaning |
|---|---|
| Verified | Everything matches. |
| Tampered | A record, a file or the block itself changed after the event. |
| Record missing | The record is gone and its deletion was not recorded. |
| Deleted (recorded) | The record is gone, and its deletion is on the ledger. |
| Chain only | The block's place in the chain is checked, but its content cannot be rebuilt from the records. This applies to the two demo blocks from `tesda_nexus.sql`, which were hashed from hand-written strings. |

The **Verified on ledger** badge on inventory requests, deliveries, student documents and archived documents runs the same record check every time the page loads. It skips the file re-hashing, and it does not save a result.

**Limits.** This ledger lives in the same database as the records. It detects edits made through the app or by hand in the records, the files or single blocks. It cannot stop someone with full database access from rewriting the whole chain consistently. The protection against that is anchoring: publishing the latest block hash to a public blockchain, which is the planned next step.

## Public anchoring and QR-verifiable printouts

Run `blockchain_anchoring.sql` once, after `blockchain_ledger.sql`. Running it again is safe. It adds `blockchain_anchors` and `document_seals`.

**Anchoring in Bitcoin (OpenTimestamps).** The newest block's hash is sent to the free public OpenTimestamps calendars, which put it into a Bitcoin transaction within a few hours. Every block contains the hash of the block before it, so one anchor proves the whole chain up to that block existed unchanged at that time. Even someone with full database access can no longer rewrite that part of the chain without it showing.

- Only that 64-character hash leaves the server. No records and no personal data are sent.
- Anchor from **Blockchain ledger → Anchor newest block** (and **Check confirmations** later), or schedule the CLI command every few hours:
  - Program: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
  - Arguments: `lava ledger:anchor`
  - Start in: `C:\laragon\www\TesdaNexus\Back-End`

  The command collects confirmations, then anchors the newest block if it is not anchored yet. `--check-only` only collects confirmations.
- Confirmation: the stored proof is followed to a Bitcoin block, and that block's merkle root is checked against a public block explorer (Blockstream, with mempool.space as fallback). **Verify** on an anchor repeats the whole check from scratch.
- The server needs internet access to `*.opentimestamps.org`, `a.pool.eternitywall.com` and `blockstream.info` / `mempool.space`.

**QR seals on printed sheets.** The information sheet, document checklist, enrollment packet and batch records print with a QR code and a 24-character code.

- **At print time** the sheet's content is fingerprinted from the records (SHA-256) and the seal is recorded on the ledger. Printing an unchanged sheet again reuses the same seal.
- **Scanning** opens `/verify/<code>`, a public page that needs no login. It reports:

  | Result | Meaning |
  |---|---|
  | Authentic and current | The records still match the paper. |
  | Issued, but no longer current | The records changed after printing. |
  | Cannot be trusted | The seal's ledger block, or a block before it, failed its check. |
  | Not found | No document has this code. |

  It also reports whether that part of the chain is anchored in Bitcoin.
- **Privacy:** the public page shows only the document type, issuing center, date, and a masked student number with initials (or the batch name). That is enough to match the paper without exposing personal data.
- **QR address:** the QR code contains the address the sheet was printed from. Print from the real server address, not `localhost`, so phones can open it.

**Going live with real data.** Sample blocks, anchors and seals stay in the chain. Before entering real records, clear the sample data and the ledger tables together (`blockchain_verifications`, `document_seals`, `blockchain_anchors`, `blockchain_ledger`), then insert a new genesis block, so the chain starts clean. Hashes already sent to OpenTimestamps are only fingerprints of sample data and are harmless.
