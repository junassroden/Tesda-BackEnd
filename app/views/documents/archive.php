<?php
$documents = $documents ?? [];
$chatMessages = $chat_messages ?? [];
if (!$chatMessages) {
    $chatMessages = [[
        'role' => 'assistant',
        'text' => 'Hello. I can search the archive by filename or words printed on a document.',
        'documents' => []
    ]];
}
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$renderDocument = static function ($document) use ($escape) {
    $ocrText = trim((string) ($document['ocr_text'] ?? ''));
    $snippet = $ocrText !== '' ? mb_substr(preg_replace('/\s+/', ' ', $ocrText), 0, 220) : '';
    ?>
    <article class="archive-document">
        <div class="archive-document-main">
            <h3><?= $escape($document['original_name']); ?></h3>
            <p class="archive-document-meta">
                <?= $escape(date('M j, Y g:i A', strtotime($document['created_at']))); ?>
                <?php if (!empty($document['uploader_name'])): ?> · <?= $escape($document['uploader_name']); ?><?php endif; ?>
                · <?= number_format((int) $document['file_size'] / 1024, 0); ?> KB
            </p>
            <?php if ($snippet !== ''): ?><p class="archive-snippet"><?= $escape($snippet); ?><?= mb_strlen($ocrText) > 220 ? '…' : ''; ?></p><?php endif; ?>
            <?php if (!empty($document['ocr_error'])): ?><p class="archive-warning">OCR issue: <?= $escape($document['ocr_error']); ?></p><?php endif; ?>
            <div class="archive-labels">
                <?php if (!empty($document['image_label'])): ?><span>Image: <?= $escape(str_replace('_', ' ', $document['image_label'])); ?></span><?php endif; ?>
                <?php if (!empty($document['text_label'])): ?><span>Text: <?= $escape(str_replace('_', ' ', $document['text_label'])); ?></span><?php endif; ?>
                <?php if ($snippet === '' && empty($document['ocr_error'])): ?><span>OCR found no searchable text</span><?php endif; ?>
            </div>
        </div>
        <a class="button button-secondary button-small" href="<?= site_url('/documents/download/' . (int) $document['id']); ?>">Download</a>
    </article>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Store and search documents analyzed by TESDA's OCR service.">
    <title>Document archive | TESDA NEXUS</title>
    <link rel="stylesheet" href="<?= site_url('/css/nexus.css'); ?>">
</head>
<body>
    <div class="app-layout">
        <?php $sidebar_active_page = 'document-archive'; include __DIR__ . '/../partials/management_sidebar.php'; ?>
        <div class="workspace">
            <header class="app-header">
                <p>TESDA main office</p>
                <span class="user-chip"><?= $escape($username ?? 'Staff'); ?></span>
            </header>
            <main class="page-content role-page archive-page">
                <section class="page-heading role-heading">
                    <div>
                        <h1>Document archive</h1>
                        <p>Upload an image or PDF to run OCR and classification, then keep it in the main-office archive for later retrieval.</p>
                    </div>
                    <span class="archive-count"><?= (int) ($total_documents ?? 0); ?> saved documents</span>
                </section>

                <?php if (!empty($error)): ?><p class="form-error" role="alert"><?= $escape($error); ?></p><?php endif; ?>
                <?php if (!empty($success)): ?><p class="archive-success" role="status"><?= $escape($success); ?></p><?php endif; ?>

                <div class="archive-tools">
                    <section class="panel role-panel archive-upload">
                        <h2 class="section-title">Add a document</h2>
                        <p class="muted-copy">The OCR and image models analyze each page before it is saved. Files stay on this server.</p>
                        <form class="management-form" action="<?= site_url('/documents'); ?>" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="archive_token" value="<?= $escape($csrf_token ?? ''); ?>">
                            <div class="field">
                                <label for="document_file">Document image or PDF</label>
                                <input id="document_file" name="document_file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.bmp,.tif,.tiff,application/pdf,image/jpeg,image/png,image/webp,image/bmp,image/tiff" required>
                                <small class="muted-copy">PDF up to 20 pages, JPEG, PNG, WebP, BMP, or TIFF. Maximum 10 MB. Images are limited to 40 megapixels.</small>
                            </div>
                            <div class="form-actions"><button class="button" type="submit">Analyze and save</button></div>
                        </form>
                    </section>
                </div>

                <section class="panel role-panel archive-list">
                    <div class="archive-list-heading">
                        <div><h2 class="section-title">Recent documents</h2><p class="muted-copy">Latest files saved at the TESDA main office.</p></div>
                    </div>
                    <?php if ($documents): ?>
                        <div class="archive-results"><?php foreach ($documents as $document) { $renderDocument($document); } ?></div>
                    <?php else: ?>
                        <div class="empty-state"><strong>No archived documents yet</strong><span>Upload an image or PDF above. OCR text and predictions will be saved with the file.</span></div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
    <details class="archive-chat-widget" id="archive-chat" <?= !empty($chat_open) ? 'open' : ''; ?>>
        <summary class="archive-chat-launcher">
            <span class="archive-chat-launcher-closed">Ask for a document</span>
            <span class="archive-chat-launcher-open">Close chat</span>
        </summary>
        <section class="archive-chat-panel" aria-label="Document assistant">
            <header class="archive-chat-header">
                <div>
                    <h2>Document assistant</h2>
                    <p>Local archive search · chat is cleared at logout</p>
                </div>
                <div class="archive-chat-actions">
                    <form action="<?= site_url('/documents'); ?>" method="post">
                        <input type="hidden" name="archive_token" value="<?= $escape($csrf_token ?? ''); ?>">
                        <button class="archive-chat-clear" type="submit" name="clear_chat" value="1">Clear</button>
                    </form>
                    <button class="archive-chat-close" type="button" data-chat-close>Close</button>
                </div>
            </header>
            <div class="archive-chat-log" role="log" aria-live="polite" aria-relevant="additions text" data-chat-log>
                <?php foreach ($chatMessages as $message): ?>
                    <?php
                    $messageRole = ($message['role'] ?? '') === 'user' ? 'user' : 'assistant';
                    $messageDocuments = isset($message['documents']) && is_array($message['documents'])
                        ? $message['documents']
                        : [];
                    ?>
                    <article class="archive-chat-message archive-chat-message-<?= $messageRole; ?>">
                        <span class="archive-chat-speaker"><?= $messageRole === 'user' ? 'You' : 'Document assistant'; ?></span>
                        <p><?= nl2br($escape($message['text'] ?? '')); ?></p>
                        <?php foreach ($messageDocuments as $messageDocument): ?>
                            <?php if (!empty($messageDocument['id'])): ?>
                                <a class="archive-chat-document" href="<?= site_url('/documents/download/' . (int) $messageDocument['id']); ?>">
                                    <span><?= $escape($messageDocument['original_name'] ?? 'Archived document'); ?></span>
                                    <strong>Download</strong>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($chat_notice)): ?><p class="archive-chat-notice" role="status"><?= $escape($chat_notice); ?></p><?php endif; ?>
            <?php if (!empty($chat_error)): ?><p class="archive-chat-error" role="alert"><?= $escape($chat_error); ?></p><?php endif; ?>
            <form class="archive-chat-compose" action="<?= site_url('/documents'); ?>" method="post">
                <input type="hidden" name="archive_token" value="<?= $escape($csrf_token ?? ''); ?>">
                <label class="archive-chat-sr-only" for="archive-chat-question">Message the document assistant</label>
                <input id="archive-chat-question" name="question" type="text" maxlength="240" value="<?= $escape($question ?? ''); ?>" placeholder="Ask about a document..." required>
                <button class="button" type="submit">Send</button>
            </form>
        </section>
    </details>
    <script>
        (function () {
            var widget = document.getElementById('archive-chat');
            var closeButton = widget && widget.querySelector('[data-chat-close]');
            var log = widget && widget.querySelector('[data-chat-log]');
            if (!widget) return;
            var scrollToLatest = function () {
                if (log) log.scrollTop = log.scrollHeight;
            };
            if (widget.open) scrollToLatest();
            widget.addEventListener('toggle', function () {
                if (widget.open) scrollToLatest();
            });
            if (closeButton) {
                closeButton.addEventListener('click', function () {
                    widget.open = false;
                    widget.querySelector('summary').focus();
                });
            }
        }());
    </script>
</body>
</html>
