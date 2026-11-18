<?php
$result = $result ?? null;
$imageResult = $result['image_classification'] ?? null;
$ocrResult = $result['ocr'] ?? null;
$textResult = $result['text_classification'] ?? null;
$modelInfo = $result['model_info'] ?? null;
$ocrText = isset($ocr_text) && $ocr_text !== ''
    ? $ocr_text
    : ($ocrResult['text'] ?? '');
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Test the document image classifier and OCR text model.">
    <title>Document AI lab | TESDA NEXUS</title>
    <link rel="stylesheet" href="<?= site_url('/css/nexus.css'); ?>">
</head>
<body>
    <div class="app-layout">
        <?php $sidebar_active_page = 'document-ai'; include __DIR__ . '/../partials/management_sidebar.php'; ?>
        <div class="workspace">
            <header class="app-header">
                <p>Document analysis</p>
                <span class="user-chip">CNN + OCR</span>
            </header>
            <main class="page-content role-page document-ai-page">
                <section class="page-heading role-heading">
                    <div>
                        <h1>Document AI lab</h1>
                        <p>Upload a document image to compare the CNN category prediction with OCR text extraction and text classification.</p>
                    </div>
                    <a class="button button-secondary" href="<?= site_url('/dashboard'); ?>">Back to analytics</a>
                </section>

                <?php if (!empty($error)): ?>
                    <p class="form-error" role="alert"><?= $escape($error); ?></p>
                <?php endif; ?>

                <div class="ai-workbench">
                    <section class="panel role-panel ai-upload-panel">
                        <div class="ai-panel-heading">
                            <span class="ai-step">Image analysis</span>
                            <h2 class="section-title">Test a document</h2>
                            <p class="muted-copy">The CNN classifies the image. Tesseract extracts text, then the text model predicts a category from those words.</p>
                        </div>
                        <form class="management-form" action="<?= site_url('/admin/document-ai'); ?>" method="post" enctype="multipart/form-data">
                            <div class="field">
                                <label for="document_file">Document image</label>
                                <input id="document_file" name="document_file" type="file" accept=".jpg,.jpeg,.png,.webp,.bmp,.tif,.tiff,image/jpeg,image/png,image/webp,image/bmp,image/tiff" required>
                                <small class="muted-copy">JPEG, PNG, WebP, BMP, or TIFF. Maximum 10 MB and 40 megapixels. The file is not saved.</small>
                            </div>
                            <div class="form-actions">
                                <button class="button" type="submit">Analyze document</button>
                            </div>
                        </form>
                        <div class="ai-sample-action">
                            <span>Or use the included RVL-CDIP invoice test image.</span>
                            <form action="<?= site_url('/admin/document-ai/sample'); ?>" method="post">
                                <button class="text-button" type="submit">Run sample invoice</button>
                            </form>
                        </div>
                    </section>

                    <aside class="ai-model-note">
                        <span class="ai-step">Model evaluation</span>
                        <h2>Prototype scores</h2>
                        <div class="ai-evaluation">
                            <div><strong>71.1%</strong><span>CNN test accuracy across 992 document images</span></div>
                            <div><strong>58.9%</strong><span>OCR-text test accuracy across 236 documents</span></div>
                        </div>
                        <p>These are results from the supplied held-out datasets, not a guarantee of accuracy on new documents. Review each prediction before relying on it.</p>
                    </aside>
                </div>

                <?php if ($result): ?>
                    <section class="ai-results" aria-labelledby="ai-results-title">
                        <div class="ai-results-heading">
                            <div>
                                <span class="ai-step">Analysis results</span>
                                <h2 id="ai-results-title"><?= $escape($source_name ?: 'OCR text'); ?></h2>
                            </div>
                            <?php if (!empty($imageResult['device'])): ?><span class="ai-device">CNN device: <?= $escape($imageResult['device']); ?></span><?php endif; ?>
                        </div>
                        <div class="ai-results-grid">
                            <?php if ($imageResult): ?>
                                <section class="panel role-panel ai-result-panel">
                                    <h3>Image classifier</h3>
                                    <p class="ai-prediction"><?= $escape(str_replace('_', ' ', $imageResult['label'])); ?></p>
                                    <p class="muted-copy">Top prediction confidence: <?= number_format((float) $imageResult['confidence'], 1); ?>%</p>
                                    <?php if (!empty($imageResult['top_predictions'])): ?>
                                        <ol class="ai-prediction-list">
                                            <?php foreach ($imageResult['top_predictions'] as $prediction): ?>
                                                <li><span><?= $escape(str_replace('_', ' ', $prediction['label'])); ?></span><strong><?= number_format((float) $prediction['confidence'], 1); ?>%</strong></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php endif; ?>
                                </section>
                            <?php endif; ?>
                            <?php if ($textResult): ?>
                                <section class="panel role-panel ai-result-panel">
                                    <h3>OCR text classifier</h3>
                                    <?php if (!empty($textResult['label'])): ?>
                                        <p class="ai-prediction"><?= $escape(str_replace('_', ' ', $textResult['label'])); ?></p>
                                        <p class="muted-copy">Predicted from <?= (int) $textResult['word_count']; ?> words. This model does not return calibrated confidence scores.</p>
                                    <?php else: ?>
                                        <p class="ai-no-text"><?= $escape($textResult['message'] ?? 'No usable text was available for classification.'); ?></p>
                                    <?php endif; ?>
                                </section>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ocrResult['error'])): ?>
                            <p class="form-error" role="alert">OCR could not run: <?= $escape($ocrResult['error']); ?></p>
                        <?php endif; ?>
                        <?php if ($ocrResult): ?>
                            <section class="panel role-panel ai-ocr-panel">
                                <div class="ai-results-heading">
                                    <div><h3>Extracted text</h3><p class="muted-copy"><?= (int) str_word_count($ocrText); ?> words. Edit the text below and rerun the text classifier if needed.</p></div>
                                </div>
                                <?php if (trim($ocrText) !== ''): ?>
                                    <form action="<?= site_url('/admin/document-ai/text'); ?>" method="post">
                                        <div class="field"><label for="ocr_text">OCR text</label><textarea id="ocr_text" name="ocr_text" maxlength="30000" rows="12"><?= $escape($ocrText); ?></textarea></div>
                                        <div class="form-actions"><button class="button button-secondary" type="submit">Classify edited text</button></div>
                                    </form>
                                <?php else: ?>
                                    <div class="empty-state"><strong>No text was detected</strong><span>Try a clearer, higher-resolution image or paste text into the classifier below.</span></div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if (!$ocrResult || trim($ocrText) === ''): ?>
                    <section class="panel role-panel ai-text-panel">
                        <span class="ai-step">Text-only test</span>
                        <h2 class="section-title">Classify OCR text</h2>
                        <p class="muted-copy">Paste text from your OCR samples to test the TF-IDF + Linear SVM without uploading an image.</p>
                        <form action="<?= site_url('/admin/document-ai/text'); ?>" method="post">
                            <div class="field"><label for="ocr_text">Extracted document text</label><textarea id="ocr_text" name="ocr_text" maxlength="30000" rows="9" required><?= $escape($ocr_text); ?></textarea></div>
                            <div class="form-actions"><button class="button button-secondary" type="submit">Classify text</button></div>
                        </form>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
