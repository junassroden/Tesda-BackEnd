import json
import math
import os
from pathlib import Path
import shutil
import sys

import joblib
import pytesseract
from PIL import Image, ImageOps

from predict_cnn import DEVICE, load_model, predict_image


PROJECT_DIR = Path(__file__).resolve().parent
CNN_METADATA_PATH = PROJECT_DIR / "models" / "custom_cnn_metadata.json"
TEXT_MODEL_PATH = PROJECT_DIR / "models" / "text_classifier_prototype.joblib"
TEXT_METADATA_PATH = PROJECT_DIR / "models" / "text_classifier_prototype_metadata.json"
MAX_IMAGE_PIXELS = 40_000_000
MAX_PDF_PAGES = 20
MAX_TEXT_LENGTH = 30_000


def configure_tesseract():
    configured = os.environ.get("TESSERACT_CMD")
    candidates = [
        configured,
        shutil.which("tesseract"),
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
    ]
    executable = next(
        (candidate for candidate in candidates if candidate and Path(candidate).is_file()),
        None,
    )
    if executable is None:
        raise RuntimeError(
            "Tesseract OCR was not found. Install Tesseract or set TESSERACT_CMD."
        )
    pytesseract.pytesseract.tesseract_cmd = executable


def classify_text(text, classifier):
    cleaned = text.strip()
    if len(cleaned) > MAX_TEXT_LENGTH:
        return {
            "label": None,
            "word_count": len(cleaned.split()),
            "message": (
                f"Text exceeds the {MAX_TEXT_LENGTH:,}-character classifier limit. "
                "The extracted text is still available for searching."
            ),
        }
    word_count = len(cleaned.split())
    if word_count < 3:
        return {
            "label": None,
            "word_count": word_count,
            "message": "At least three words are needed for text classification.",
        }

    return {
        "label": str(classifier.predict([cleaned])[0]),
        "word_count": word_count,
        "message": None,
    }


def extract_ocr_text(image):
    configure_tesseract()
    grayscale = ImageOps.autocontrast(ImageOps.grayscale(image))
    scale = min(2.0, 3600 / max(grayscale.size))
    if scale != 1:
        grayscale = grayscale.resize(
            (int(grayscale.width * scale), int(grayscale.height * scale)),
            Image.Resampling.LANCZOS,
        )

    return pytesseract.image_to_string(
        grayscale,
        lang="eng",
        config="--oem 3 --psm 3",
        timeout=25,
    ).strip()


def analyze_image(image_path):
    with Image.open(image_path) as source:
        width, height = source.size
        if width * height > MAX_IMAGE_PIXELS:
            raise ValueError("This image is too large to analyze. Use an image under 40 megapixels.")
        image = source.convert("RGB")

    cnn, class_names, transform = load_model()
    classifier = joblib.load(TEXT_MODEL_PATH)
    predictions = predict_image(image, cnn, class_names, transform)
    ocr_error = None
    try:
        text = extract_ocr_text(image)
    except (pytesseract.TesseractNotFoundError, pytesseract.TesseractError, RuntimeError) as error:
        text = ""
        ocr_error = str(error)

    return {
        "image_classification": {
            "label": predictions[0]["label"],
            "confidence": predictions[0]["confidence"],
            "top_predictions": predictions,
            "device": str(DEVICE),
        },
        "ocr": {"text": text, "error": ocr_error},
        "text_classification": classify_text(text, classifier),
        "model_info": read_model_info(),
        "pages_analyzed": 1,
    }


def analyze_pdf(pdf_path):
    try:
        import pymupdf
    except ImportError as error:
        raise RuntimeError("PDF support is unavailable. Install the packages in ai/requirements.txt.") from error

    try:
        document = pymupdf.open(pdf_path)
    except (pymupdf.FileDataError, OSError, ValueError) as error:
        raise ValueError("The PDF is invalid or could not be opened.") from error

    with document:
        if document.needs_pass:
            raise ValueError("Password-protected PDFs are not supported.")
        page_count = len(document)
        if page_count == 0:
            raise ValueError("The PDF has no pages to analyze.")
        if page_count > MAX_PDF_PAGES:
            raise ValueError(f"PDFs can contain no more than {MAX_PDF_PAGES} pages.")

        cnn, class_names, transform = load_model()
        classifier = joblib.load(TEXT_MODEL_PATH)
        page_texts = []
        page_errors = []
        label_votes = {}
        max_page_pixels = max(1, int(MAX_IMAGE_PIXELS * 0.95 // page_count))
        pixels_rendered = 0

        for page_number, page in enumerate(document, start=1):
            rectangle = page.rect
            page_area = rectangle.width * rectangle.height
            if page_area <= 0:
                raise ValueError(f"PDF page {page_number} has invalid dimensions.")

            scale = min(2.0, math.sqrt(max_page_pixels / page_area))
            pixmap = page.get_pixmap(
                matrix=pymupdf.Matrix(scale, scale),
                colorspace=pymupdf.csRGB,
                alpha=False,
                annots=False,
            )
            pixels = pixmap.width * pixmap.height
            if pixels_rendered + pixels > MAX_IMAGE_PIXELS:
                raise ValueError("The PDF exceeds the 40-megapixel total rendering limit.")
            pixels_rendered += pixels
            image = Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples)
            del pixmap

            page_predictions = predict_image(image, cnn, class_names, transform)
            best_page_prediction = page_predictions[0]
            label_votes.setdefault(best_page_prediction["label"], []).append(
                best_page_prediction["confidence"]
            )

            embedded_text = page.get_text("text").strip()
            page_text = embedded_text
            if len(embedded_text) < 20:
                try:
                    ocr_text = extract_ocr_text(image)
                    if ocr_text:
                        page_text = ocr_text
                except (
                    pytesseract.TesseractNotFoundError,
                    pytesseract.TesseractError,
                    RuntimeError,
                ) as error:
                    page_errors.append(f"Page {page_number}: {error}")

            if page_text:
                page_texts.append(f"[Page {page_number}]\n{page_text}")
            del image

        ranked_labels = sorted(
            label_votes.items(),
            key=lambda item: (len(item[1]), sum(item[1]) / len(item[1])),
            reverse=True,
        )
        top_predictions = [
            {
                "label": label,
                "confidence": round(len(confidences) * 100 / page_count, 2),
            }
            for label, confidences in ranked_labels[:3]
        ]
        text = "\n\n".join(page_texts)
        return {
            "image_classification": {
                "label": top_predictions[0]["label"],
                "confidence": top_predictions[0]["confidence"],
                "top_predictions": top_predictions,
                "device": str(DEVICE),
            },
            "ocr": {
                "text": text,
                "error": "; ".join(page_errors)[:1000] or None,
            },
            "text_classification": classify_text(text, classifier),
            "model_info": read_model_info(),
            "pages_analyzed": page_count,
        }


def classify_ocr_text(text):
    if not text.strip():
        raise ValueError("Enter or paste OCR text before classifying it.")
    if len(text) > MAX_TEXT_LENGTH:
        raise ValueError(f"OCR text must be {MAX_TEXT_LENGTH:,} characters or fewer.")

    classifier = joblib.load(TEXT_MODEL_PATH)
    return {
        "text_classification": classify_text(text, classifier),
        "model_info": read_model_info(),
    }


def read_model_info():
    with CNN_METADATA_PATH.open(encoding="utf-8") as file:
        cnn = json.load(file)
    with TEXT_METADATA_PATH.open(encoding="utf-8") as file:
        text = json.load(file)

    return {
        "cnn_accuracy": round(float(cnn["test_accuracy"]) * 100, 1),
        "cnn_test_images": int(cnn.get("test_images", 992)),
        "text_accuracy": round(float(text["accuracy"]) * 100, 1),
        "text_test_documents": int(text["test_documents"]),
    }


def main():
    try:
        request = json.load(sys.stdin)
        mode = request.get("mode")
        if mode == "image":
            result = analyze_image(request["path"])
        elif mode == "pdf":
            result = analyze_pdf(request["path"])
        elif mode == "text":
            result = classify_ocr_text(str(request.get("text", "")))
        else:
            raise ValueError("Choose image analysis or OCR text classification.")
        json.dump(result, sys.stdout, ensure_ascii=False)
        sys.stdout.write("\n")
    except Exception as error:
        json.dump({"error": str(error)}, sys.stdout, ensure_ascii=False)
        sys.stdout.write("\n")
        raise SystemExit(1)


if __name__ == "__main__":
    main()
