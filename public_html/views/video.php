<?php
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('video_logic.php', 'logic'));

    $page_vars = video_logic($_GET, $_POST, $video, $params);
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
    $video     = $page_vars['video'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => $video->get('vid_title'),
    ]);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($video->get('vid_title')); ?></h1>
                <?php if ($video->get('vid_description')): ?>
                <span><?php echo htmlspecialchars(substr(strip_tags($video->get('vid_description')), 0, 120)); ?></span>
                <?php endif; ?>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($video->get('vid_title')); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 860px; margin: 0 auto;">

            <!-- Video Player -->
            <div style="background: #000; border-radius: 8px; overflow: hidden; margin-bottom: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.2);">
                <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                    <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                        <?php echo $video->get_embed(); ?>
                    </div>
                </div>
            </div>

            <!-- Description & Duration -->
            <?php if ($video->get('vid_description') || $video->get('vid_duration')): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                    <?php if ($video->get('vid_description')): ?>
                    <div style="flex: 1;">
                        <?php echo $video->get('vid_description'); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($video->get('vid_duration')): ?>
                    <div style="flex-shrink: 0;">
                        <span style="display: inline-block; background: var(--color-muted, #6c757d); color: #fff; font-size: 0.8125rem; padding: 0.25rem 0.75rem; border-radius: 4px;">
                            &#9201; <?php echo htmlspecialchars($video->get('vid_duration')); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Transcript & Tags -->
            <?php if ($video->get('vid_transcript') || $video->get('vid_tags')): ?>
            <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-start;">

                <?php if ($video->get('vid_transcript')): ?>
                <div style="flex: 2; min-width: 240px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h5 style="margin: 0; font-size: 1rem;">&#128221; Transcript</h5>
                    </div>
                    <div style="padding: 1.25rem;">
                        <?php echo nl2br(htmlspecialchars($video->get('vid_transcript'))); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($video->get('vid_tags')): ?>
                <div style="flex: 1; min-width: 180px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 0.875rem 1.25rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h6 style="margin: 0; font-size: 0.9375rem;">&#127991; Tags</h6>
                    </div>
                    <div style="padding: 1.25rem;">
                        <?php
                        $tags = explode(',', $video->get('vid_tags'));
                        foreach ($tags as $tag):
                            $tag = trim($tag);
                            if ($tag):
                        ?>
                        <span style="display: inline-block; background: var(--color-primary); color: #fff; font-size: 0.8125rem; padding: 0.25rem 0.625rem; border-radius: 4px; margin: 0 0.25rem 0.375rem 0;">
                            <?php echo htmlspecialchars($tag); ?>
                        </span>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>
    </div>
</section>

<?php
    $page->public_footer(['track' => true]);
?>
