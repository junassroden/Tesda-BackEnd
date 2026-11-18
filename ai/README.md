# Document AI and archive

The admin-only test page is available at `/admin/document-ai`. It uses the supplied custom CNN to classify an image, Tesseract to extract text, and the trained TF-IDF + Linear SVM model to classify OCR text. You can also paste text from the existing OCR samples and test the text model directly. Files uploaded to this test page are temporary and are not retained.

The persistent document archive is available at `/documents` to central administrators and center organizers assigned to **TESDA Main Office**. Run [`database/document_archive.sql`](../database/document_archive.sql) in the portal database, then assign the organizer account to the TESDA Main Office center. Archive uploads run the same CNN and OCR pipeline, then save the original image, OCR output, and predictions under `runtime/document-archive`. Downloads are served through an authenticated route; direct web access to the storage directory is blocked.

The archive's chat-style finder searches filenames, OCR text, and model labels locally. It is not a generative AI service and does not send documents or search queries to an external provider. It accepts PDF files up to 20 pages and the same image formats as the test page; the 10 MB upload cap applies to both.

PDF pages are rendered locally with PyMuPDF. Embedded PDF text is used when available; scanned pages are passed through Tesseract. Install the updated `ai/requirements.txt` before processing PDFs.

The trained model files and a full-resolution RVL-CDIP invoice sample are packaged under `ai/models/` and `ai/samples/`. The large training datasets and the original virtual environment remain in the separate `AI` folder and are not needed for inference.

## Runtime

- The PHP web process invokes `ai/inference.py` with the Python interpreter configured by `DOCUMENT_AI_PYTHON`.
- If that setting is empty, the app checks the neighboring `AI/.venv/Scripts/python.exe`, then `python` on the web server's `PATH`.
- The Python environment needs the packages listed in `ai/requirements.txt`.
- Tesseract must be installed. The worker checks `TESSERACT_CMD`, then the system `PATH`, then the standard Windows installation path.
- Images uploaded to the test page are validated and processed from PHP's temporary upload directory. The archive page retains its uploads in private server storage after analysis.

The supplied model reports 71.1% CNN accuracy on 992 held-out images and 58.9% OCR-text classification accuracy on 236 held-out documents. These small prototype evaluation sets do not guarantee accuracy on new documents. The text model does not provide calibrated confidence scores.
