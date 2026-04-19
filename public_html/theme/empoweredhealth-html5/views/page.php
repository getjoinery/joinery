<?php
// theme/empoweredhealth/views/page.php
// Theme-specific page template with empoweredhealth styling

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('page_logic.php', 'logic'));

$page_vars = process_logic(page_logic($_GET, $_POST, $page, $params));
$page = $page_vars['page'];

$paget = new PublicPage();
$page_header_opts = [
    'is_valid_page' => $is_valid_page,
    'title'         => $page->get('pag_title'),
];
if ($page->get('pag_body')) {
    $pag_desc = trim(strip_tags($page->get('pag_body')));
    if (mb_strlen($pag_desc) > 160) {
        $pag_desc = mb_substr($pag_desc, 0, 157) . '...';
    }
    if ($pag_desc) {
        $page_header_opts['meta_description'] = $pag_desc;
    }
}
$og_img = $page->get_picture_link('og_image') ?: $page->get_picture_link('hero');
if ($og_img) {
    $page_header_opts['preview_image_url'] = $og_img;
}
$paget->public_header($page_header_opts);
?>

<!-- Banner Section -->
<section class="banner-area relative about-banner" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row d-flex align-items-center justify-content-center">
            <div class="about-content col-lg-12">
                <h1 class="text-white"><?php echo htmlspecialchars($page->get('pag_title')); ?></h1>
                <p class="text-white link-nav">
                    <a href="/">Home </a><span class="lnr lnr-arrow-right"></span>
                    <?php echo htmlspecialchars($page->get('pag_title')); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="offered-service-area dep-offred-service">
    <div class="container">
        <div class="row offred-wrap section-gap">
            <div class="col-lg-12 offered-left">
                <?php echo $page->get_filled_content(); ?>
            </div>
        </div>
    </div>
</section>

<?php
$paget->public_footer(array('track' => TRUE));
?>
