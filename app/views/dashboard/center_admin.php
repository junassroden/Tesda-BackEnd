<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Center overview | TESDA NEXUS</title>
    <link rel="stylesheet" href="<?= site_url('/css/nexus.css'); ?>">
</head>
<body>
    <div class="app-layout">
        <?php $sidebar_active_page = 'center-overview'; include __DIR__ . '/../partials/management_sidebar.php'; ?>
        <div class="workspace">
            <header class="app-header"><p>Center administration</p><span class="user-chip"><?= htmlspecialchars($center_name ?: 'Training center', ENT_QUOTES, 'UTF-8'); ?></span></header>
            <main class="page-content role-page role-admin">
                <section class="page-heading role-heading">
                    <div>
                        <h1><?= htmlspecialchars($center_name ?: 'Center overview', ENT_QUOTES, 'UTF-8'); ?></h1>
                        <p>Review applicants and manage your center's accounts and NC course offerings.</p>
                    </div>
                </section>
                <div class="stats-grid role-stats">
                    <section class="stat-card"><span>Students</span><strong><?= (int) $analytics['students']; ?></strong><small>Active accounts at this center</small></section>
                    <section class="stat-card"><span>Instructors</span><strong><?= (int) $analytics['instructors']; ?></strong><small>Active accounts at this center</small></section>
                    <section class="stat-card"><span>NC courses</span><strong><?= (int) $analytics['courses']; ?></strong><small>Managed at this center</small></section>
                    <section class="stat-card stat-card-featured"><span>Applications</span><strong><?= (int) $analytics['applications']; ?></strong><small>Waiting for review</small></section>
                </div>
                <div class="content-grid role-admin-links">
                    <section class="panel role-panel">
                        <h2 class="section-title">Center operations</h2>
                        <div class="list-rows">
                            <a class="list-row admin-action-link" href="<?= site_url('/center/applications'); ?>"><strong>Review applications</strong><span><?= (int) $analytics['applications']; ?> pending student and instructor applications</span></a>
                            <a class="list-row admin-action-link" href="<?= site_url('/show-users'); ?>"><strong>Manage center accounts</strong><span>Update student and instructor accounts assigned here</span></a>
                            <a class="list-row admin-action-link" href="<?= site_url('/admin/courses'); ?>"><strong>Manage NC courses</strong><span>Maintain this center's course details and schedules</span></a>
                            <?php if (!empty($can_access_document_archive)): ?>
                                <a class="list-row admin-action-link" href="<?= site_url('/documents'); ?>"><strong>Document archive</strong><span>Store and find documents for the TESDA main office</span></a>
                            <?php endif; ?>
                        </div>
                    </section>
                    <section class="panel role-panel access-panel">
                        <h2 class="section-title">Local access</h2>
                        <p class="muted-copy">Applicants can log in only after approval. Your account can manage this center's applicants, users, and courses, but cannot appoint center organizers or edit other centers.</p>
                    </section>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
