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

<!-- Start Banner Area -->
<section class="banner-area-three">
    <div class="banner-slider-wrap owl-carousel owl-theme">
        <div class="banner-item-area">
            <div class="d-table">
                <div class="d-table-cell">
                    <div class="container">
                        <div class="banner-text one">
                            <span>News</span>
                            <h1>If You Were A Start Business From Search Tomorrow</h1>
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        By Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    25 Mar 2024
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="banner-item-area">
            <div class="d-table">
                <div class="d-table-cell">
                    <div class="container">
                        <div class="banner-text two">
                            <span>News</span>
                            <h1>The Best Dog Tech & Accessories</h1>
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        By Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    25 Mar 2024
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Banner Area -->

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
					<p>We offer classes in various dance styles including Latin, Ballroom, Contemporary, and more for all skill levels.</p>
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

<!-- Start Latest Project Area -->
<section class="latest-project-area pb-70">
    <div class="container">
        <div class="section-title">
            <h2>Business</h2>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="row">
                    <?php
                    if ($page_vars['recent_posts']->count() > 0) {
                        $count = 0;
                        foreach ($page_vars['recent_posts'] as $post) {
                            if ($count >= 4) break;
                            $author = new User($post->get('pst_usr_user_id'), TRUE);
                            $post_tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
                            $tag_name = !empty($post_tags) ? $post_tags[0] : 'News';
                            ?>
                            <div class="col-lg-6 col-md-6">
                                <div class="single-featured">
                                    <a href="<?php echo $post->get_url(); ?>" class="blog-img">
                                        <?php if ($post->get('pst_image_link')): ?>
                                            <img src="<?php echo htmlspecialchars($post->get('pst_image_link')); ?>" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                        <?php else: ?>
                                            <img src="https://via.placeholder.com/400x300/f8f9fa/6c757d?text=Blog+Post" alt="<?php echo htmlspecialchars($post->get('pst_title')); ?>">
                                        <?php endif; ?>
                                    </a>
                                    <div class="featured-content">
                                        <ul>
                                            <li>
                                                <a href="#" class="admin">
                                                    <i class="bx bx-user"></i>
                                                    <?php echo htmlspecialchars($author->display_name()); ?>
                                                </a>
                                            </li>
                                            <li>
                                                <i class="bx bx-calendar"></i>
                                                <?php echo date('d M Y', strtotime($post->get('pst_published_time'))); ?>
                                            </li>
                                        </ul>
                                        <a href="<?php echo $post->get_url(); ?>">
                                            <h3><?php echo htmlspecialchars($post->get('pst_title')); ?></h3>
                                        </a>
                                        <p><?php
                                            if($post->get('pst_short_description')){
                                                echo htmlspecialchars($post->get('pst_short_description'));
                                            } else {
                                                echo htmlspecialchars(substr(strip_tags($post->get('pst_body')),0,150)) . '...';
                                            }
                                        ?></p>
                                        <a href="<?php echo $post->get_url(); ?>" class="read-more">Read More</a>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $count++;
                        }
                    } else {
                        ?>
                        <div class="col-lg-12">
                            <p>No blog posts published yet.</p>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="right-blog-editor-wrap-three">
                    <h3 style="margin-bottom: 20px;">Upcoming Events</h3>
                    <?php
                    if ($page_vars['upcoming_events']->count() > 0) {
                        foreach ($page_vars['upcoming_events'] as $event) {
                            ?>
                            <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                                <a href="<?php echo $event->get_url(); ?>">
                                    <h4 style="margin-bottom: 10px; margin-top: 0;"><?php echo htmlspecialchars($event->get('evt_name')); ?></h4>
                                </a>
                                <span style="display: block; font-size: 14px; color: #666; margin-bottom: 5px;">
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('M d, Y', strtotime($event->get('evt_start_time'))); ?>
                                </span>
                                <span style="display: block; font-size: 14px; color: #666;">
                                    <i class="bx bx-time"></i>
                                    <?php echo date('g:i A', strtotime($event->get('evt_start_time'))); ?>
                                </span>
                            </div>
                            <?php
                        }
                    } else {
                        ?>
                        <p>No upcoming events scheduled.</p>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Latest Project Area -->

<?php
$page->public_footer();
?>
