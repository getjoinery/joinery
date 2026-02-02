<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('index_logic.php', 'logic'));

$page_vars = index_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home - Phillyzouk Modern Blog',
    'showheader' => true
));
?>

<!-- Start Hero Area -->
<section class="main-blog-area pt-100 pb-70">
    <div class="container">
        <div class="main-blog-slider-item-wrap owl-carousel owl-theme">
            <div class="single-main-blog-item">
                <img src="/theme/phillyzouk/assets/images/home-three/banner-bg.jpg" alt="Dance Community">
                <div class="main-blog-content">
                    <a href="#">
                        <h3>Welcome to Philly Zouk</h3>
                    </a>
                </div>
            </div>

            <div class="single-main-blog-item">
                <img src="/theme/phillyzouk/assets/images/home-three/banner-bg.jpg" alt="Dance Events">
                <div class="main-blog-content">
                    <a href="/events">
                        <h3>Discover Upcoming Events & Workshops</h3>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Hero Area -->

<!-- Start About Info Area -->
<section class="contact-info-area pt-100 pb-70">
	<div class="container">
		<div class="row">
			<div class="col-lg-3 col-sm-6">
				<div class="single-contact-info">
					<i class="bx bx-heart"></i>
					<h3>Our Passion</h3>
					<p>We are dedicated to celebrating the art of dance and bringing our community together through movement and music.</p>
				</div>
			</div>
			<div class="col-lg-3 col-sm-6">
				<div class="single-contact-info">
					<i class="bx bx-group"></i>
					<h3>Community</h3>
					<p>Join dancers of all levels in a welcoming environment where everyone can express themselves and grow together.</p>
				</div>
			</div>
			<div class="col-lg-3 col-sm-6">
				<div class="single-contact-info">
					<i class="bx bx-music"></i>
					<h3>Styles</h3>
					<p>We offer classes, socials, and weekenders for Brazilian Zouk dancing.</p>
				</div>
			</div>
			<div class="col-lg-3 col-sm-6">
				<div class="single-contact-info">
					<i class="bx bx-calendar-event"></i>
					<h3>Events</h3>
					<p>Participate in our performances, workshops, and social events throughout the year celebrating dance culture.</p>
				</div>
			</div>
		</div>
	</div>
</section>
<!-- End About Info Area -->

<!-- Start Main Blog List Area - COMMENTED OUT
<section class="blog-list-area-three pb-100">
    <div class="container">
        <div class="blog-list-wrap mt-minus-100">
            <div class="row">
                <div class="col-lg-4 col-sm-6">
                    <div class="right-blog-editor">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/blog-item/1.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>Developing Self-Control Through The Wonders of Intermittent Fasting</h3>
                            </a>
                            <span>
                                <i class="bx bx-calendar"></i>
                                25 Mar 2024
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-sm-6">
                    <div class="right-blog-editor">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/blog-item/2.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>And a Lonely Stranger Has Spoke to Me Ever Since</h3>
                            </a>
                            <span>
                                <i class="bx bx-calendar"></i>
                                26 Mar 2024
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-sm-6 offset-sm-3 offset-lg-0">
                    <div class="right-blog-editor">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/blog-item/3.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>This Week in Business: Flying Uber Pricey French Cheese</h3>
                            </a>
                            <span>
                                <i class="bx bx-calendar"></i>
                                27 Mar 2024
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
End Main Blog List Area -->

<!-- Start Events Section -->
<section class="latest-project-area pb-70">
    <div class="container">
        <div class="row">
            <div class="col-lg-8">
                <div class="section-title">
                    <h2>Upcoming Events</h2>
                </div>
                <div class="row">
                    <?php
                    if ($page_vars['upcoming_events']->count() > 0) {
                        $count = 0;
                        foreach ($page_vars['upcoming_events'] as $event) {
                            if ($count >= 6) break;
                            ?>
                            <div class="col-lg-6 col-md-6">
                                <div class="single-featured event-card">
                                    <?php if ($event->get_picture_link()): ?>
                                    <a href="<?php echo $event->get_url(); ?>" class="blog-img">
                                        <img src="<?php echo htmlspecialchars($event->get_picture_link()); ?>" alt="<?php echo htmlspecialchars($event->get('evt_name')); ?>">
                                    </a>
                                    <?php endif; ?>
                                    <div class="featured-content">
                                        <ul>
                                            <li>
                                                <i class="bx bx-calendar"></i>
                                                <?php echo LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $event->get('evt_timezone'), 'M d, Y'); ?>
                                            </li>
                                            <li>
                                                <i class="bx bx-time"></i>
                                                <?php echo LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $event->get('evt_timezone'), 'g:i A'); ?>
                                            </li>
                                        </ul>
                                        <a href="<?php echo $event->get_url(); ?>">
                                            <h3><?php echo htmlspecialchars($event->get('evt_name')); ?></h3>
                                        </a>
                                        <?php if ($event->get('evt_short_description')): ?>
                                        <p><?php echo htmlspecialchars(substr($event->get('evt_short_description'), 0, 120)); ?>...</p>
                                        <?php endif; ?>
                                        <a href="<?php echo $event->get_url(); ?>" class="read-more">View Event</a>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $count++;
                        }
                    } else {
                        ?>
                        <div class="col-lg-12">
                            <p>No upcoming events scheduled.</p>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="section-title">
                    <h2>Latest Posts</h2>
                </div>
                <div class="right-blog-editor-wrap-three">
                    <?php
                    if ($page_vars['recent_posts']->count() > 0) {
                        $count = 0;
                        foreach ($page_vars['recent_posts'] as $post) {
                            if ($count >= 4) break;
                            ?>
                            <div class="sidebar-post-item">
                                <?php if ($post->get('pst_image_link')): ?>
                                <a href="<?php echo $post->get_url(); ?>" class="sidebar-post-img">
                                    <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                </a>
                                <?php endif; ?>
                                <div class="sidebar-post-content">
                                    <a href="<?php echo $post->get_url(); ?>">
                                        <h4><?php echo htmlspecialchars($post->get('pst_title')); ?></h4>
                                    </a>
                                    <span class="post-date">
                                        <i class="bx bx-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($post->get('pst_published_time'))); ?>
                                    </span>
                                </div>
                            </div>
                            <?php
                            $count++;
                        }
                    } else {
                        ?>
                        <p>No blog posts published yet.</p>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Events Section -->

<?php
$page->public_footer();
?>
