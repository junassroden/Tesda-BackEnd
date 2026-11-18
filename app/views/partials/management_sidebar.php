<?php
$sidebarRole = $user_role ?? ($role ?? '');
$sidebarIsCenterAdmin = !empty($is_center_admin) || $sidebarRole === 'center_admin';
$sidebarActivePage = $sidebar_active_page ?? ($active_page ?? '');
$sidebarCanAccessArchive = !empty($can_access_document_archive)
    || $sidebarActivePage === 'document-archive';
$sidebarCurrent = static function ($page) use ($sidebarActivePage) {
    return $sidebarActivePage === $page ? ' aria-current="page"' : '';
};
?>
<aside class="sidebar">
    <a class="brand" href="<?= site_url('/dashboard'); ?>"><img src="<?= site_url('/images/tsda.jpg'); ?>" alt="">TESDA NEXUS</a>
    <?php if ($sidebarIsCenterAdmin): ?>
        <nav class="side-nav" aria-label="Training center administration">
            <a href="<?= site_url('/dashboard'); ?>"<?= $sidebarCurrent('center-overview'); ?>>Center overview</a>
            <a href="<?= site_url('/center/applications'); ?>"<?= $sidebarCurrent('applications'); ?>>Applications</a>
            <a href="<?= site_url('/show-users'); ?>"<?= $sidebarCurrent('users'); ?>>Manage users</a>
            <a href="<?= site_url('/admin/courses'); ?>"<?= $sidebarCurrent('courses'); ?>>Manage NC courses</a>
            <?php if ($sidebarCanAccessArchive): ?>
                <a href="<?= site_url('/documents'); ?>"<?= $sidebarCurrent('document-archive'); ?>>Document archive</a>
            <?php endif; ?>
        </nav>
    <?php else: ?>
        <nav class="side-nav" aria-label="Administration">
            <a href="<?= site_url('/dashboard'); ?>"<?= $sidebarCurrent('analytics'); ?>>Analytics</a>
            <a href="<?= site_url('/show-users'); ?>"<?= $sidebarCurrent('users'); ?>>Manage users</a>
            <a href="<?= site_url('/admin/courses'); ?>"<?= $sidebarCurrent('courses'); ?>>Manage NC courses</a>
            <a href="<?= site_url('/admin/centers'); ?>"<?= $sidebarCurrent('centers'); ?>>Training centers</a>
            <a href="<?= site_url('/products'); ?>"<?= $sidebarCurrent('inventory'); ?>>Manage inventory</a>
            <a href="<?= site_url('/documents'); ?>"<?= $sidebarCurrent('document-archive'); ?>>Document archive</a>
            <a href="<?= site_url('/admin/document-ai'); ?>"<?= $sidebarCurrent('document-ai'); ?>>Document AI lab</a>
        </nav>
    <?php endif; ?>
    <div class="sidebar-bottom side-nav"><a href="<?= site_url('/logout'); ?>">Log out</a></div>
</aside>
