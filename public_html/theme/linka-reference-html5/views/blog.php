<?php
/**
 * Blog listing page for Linka Reference Theme
 *
 * @version 1.0.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => 'Blog - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));

// Get blog posts if available
$posts = isset($page_vars['posts']) ? $page_vars['posts'] : array();
?>

<!-- Start Page Title Area -->
<div class="page-title-area bg-12">
    <div class="container">
        <div class="page-title-content">
            <h2>Blog</h2>
            <ul>
                <li>
                    <a href="/">Home</a>
                </li>
                <li>Blog</li>
            </ul>
        </div>
    </div>
</div>
<!-- End Page Title Area -->

<!-- Start Full Width Blog Area -->
<section class="full-width-blog ptb-100">
    <div class="container-fluid">
        <div class="row">
            <?php if (!empty($posts)): ?>
                <?php foreach ($posts as $post): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="<?php echo htmlspecialchars($post['url'] ?? '#'); ?>">
                            <img src="<?php echo htmlspecialchars($post['image'] ?? '/theme/linka-reference/assets/images/home-one/latest-project/1.jpg'); ?>" alt="<?php echo htmlspecialchars($post['title'] ?? 'Blog Post'); ?>">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        <?php echo htmlspecialchars($post['author'] ?? 'Admin'); ?>
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo htmlspecialchars($post['date'] ?? date('d M Y')); ?>
                                </li>
                            </ul>

                            <a href="<?php echo htmlspecialchars($post['url'] ?? '#'); ?>">
                                <h3><?php echo htmlspecialchars($post['title'] ?? 'Blog Post Title'); ?></h3>
                            </a>

                            <p><?php echo htmlspecialchars($post['excerpt'] ?? 'Blog post excerpt goes here...'); ?></p>

                            <a href="<?php echo htmlspecialchars($post['url'] ?? '#'); ?>" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Sample blog posts for demonstration -->
                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/1.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>How to Use The Most Instagram Photography</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/2.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>Impossibly High Beauty Standards Are Ruining My Self Esteem</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/3.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>The Next Big Thing in Fashion? Not Washing Your Clothes</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/4.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>I Moved Across the Country and Never Looked Back</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/5.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>Extreme Athleticism Is the New Midlife Crisis</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="single-featured">
                        <a href="#">
                            <img src="/theme/linka-reference/assets/images/home-one/latest-project/6.jpg" alt="Image">
                        </a>

                        <div class="featured-content">
                            <ul>
                                <li>
                                    <a href="#" class="admin">
                                        <i class="bx bx-user"></i>
                                        Admin
                                    </a>
                                </li>
                                <li>
                                    <i class="bx bx-calendar"></i>
                                    <?php echo date('d M Y'); ?>
                                </li>
                            </ul>

                            <a href="#">
                                <h3>Relationships Aren't Easy, But They're Worth It</h3>
                            </a>

                            <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>

                            <a href="#" class="read-more">Read More</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-lg-12">
                <div class="page-navigation-area">
                    <nav aria-label="Page navigation example text-center">
                        <ul class="pagination">
                            <li class="page-item">
                                <a class="page-link page-links" href="#">
                                    <i class='bx bx-chevrons-left'></i>
                                </a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">2</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">3</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">
                                    <i class='bx bx-chevrons-right'></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Full Width Blog Area -->

<?php
$page->public_footer();
?>
