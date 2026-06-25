<?php
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('video_logic.php', 'logic'));

    $page_vars = process_logic(video_logic(array_merge($_GET, $_POST, $params ?? [])));
    $video     = $page_vars['video'];

    $page = new PublicPage();
    $video_header_options = [
        'is_valid_page'    => $is_valid_page,
        'title'            => $video->get('vid_title'),
        'og_type'          => 'article',
        'entity_type'      => 'video',
        'entity_body_html' => $video->get('vid_description'),
    ];
    $page->public_header($video_header_options);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="jy-container">
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

<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-video-wrap">

            <!-- Video Player -->
            <div class="jy-video-player">
                <div class="jy-video-ratio">
                    <div class="jy-video-frame">
                        <?php echo $video->get_embed(); ?>
                    </div>
                </div>
            </div>

            <!-- Description & Duration -->
            <?php if ($video->get('vid_description') || $video->get('vid_duration')): ?>
            <div class="jy-video-card">
                <div class="jy-video-desc-row">
                    <?php if ($video->get('vid_description')): ?>
                    <div class="jy-video-grow">
                        <?php echo $video->get('vid_description'); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($video->get('vid_duration')): ?>
                    <div class="jy-noshrink">
                        <span class="jy-video-duration">
                            &#9201; <?php echo htmlspecialchars($video->get('vid_duration')); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Transcript & Tags -->
            <?php if ($video->get('vid_transcript') || $video->get('vid_tags')): ?>
            <div class="jy-video-cols">

                <?php if ($video->get('vid_transcript')): ?>
                <div class="jy-video-transcript">
                    <div class="jy-video-cardhead">
                        <h5 class="jy-video-cardtitle">&#128221; Transcript</h5>
                    </div>
                    <div class="jy-video-cardbody">
                        <?php echo nl2br(htmlspecialchars($video->get('vid_transcript'))); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($video->get('vid_tags')): ?>
                <div class="jy-video-tagscard">
                    <div class="jy-video-cardhead">
                        <h6 class="jy-video-cardtitle-sm">&#127991; Tags</h6>
                    </div>
                    <div class="jy-video-cardbody">
                        <?php
                        $tags = explode(',', $video->get('vid_tags'));
                        foreach ($tags as $tag):
                            $tag = trim($tag);
                            if ($tag):
                        ?>
                        <span class="jy-video-tag">
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

</div>
<?php
    $page->public_footer(['track' => true]);
?>
