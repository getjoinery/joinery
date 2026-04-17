<?php
/**
 * About page for Linka Reference Theme
 *
 * @version 1.0.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => 'About - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));
?>

<!-- Start Page Title Area -->
<div class="page-title-area bg-12">
    <div class="container">
        <div class="page-title-content">
            <h2>About Us</h2>
            <ul>
                <li>
                    <a href="/">Home</a>
                </li>
                <li>About</li>
            </ul>
        </div>
    </div>
</div>
<!-- End Page Title Area -->

<!-- Start About Area -->
<section class="about-area ptb-100">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="about-image">
                    <img src="/theme/linka-reference/assets/images/home-one/editor-img/1.jpg" alt="About Us">
                </div>
            </div>
            <div class="col-lg-6">
                <div class="about-content">
                    <h2>Welcome to <?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></h2>
                    <p><?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?></p>

                    <p>We are dedicated to bringing you the latest and greatest content. Our team works tirelessly to ensure that every piece of content we publish meets the highest standards of quality and relevance.</p>

                    <p>Whether you're looking for inspiration, information, or entertainment, you've come to the right place. We cover a wide range of topics to cater to diverse interests and preferences.</p>

                    <div class="about-features mt-4">
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="single-feature">
                                    <i class="bx bx-check-circle"></i>
                                    <span>Quality Content</span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="single-feature">
                                    <i class="bx bx-check-circle"></i>
                                    <span>Expert Writers</span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="single-feature">
                                    <i class="bx bx-check-circle"></i>
                                    <span>Regular Updates</span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="single-feature">
                                    <i class="bx bx-check-circle"></i>
                                    <span>Community Focus</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End About Area -->

<!-- Start Team Area -->
<section class="team-area bg-color ptb-100">
    <div class="container">
        <div class="section-title">
            <h2>Meet Our Team</h2>
            <p>The talented people behind our success</p>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-6">
                <div class="single-team">
                    <img src="/theme/linka-reference/assets/images/author-img.jpg" alt="Team Member">
                    <div class="team-content">
                        <h3>John Anderson</h3>
                        <span>Chief Editor</span>
                        <div class="social-links">
                            <a href="#"><i class="bx bxl-facebook"></i></a>
                            <a href="#"><i class="bx bxl-twitter"></i></a>
                            <a href="#"><i class="bx bxl-linkedin"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-team">
                    <img src="/theme/linka-reference/assets/images/author-img.jpg" alt="Team Member">
                    <div class="team-content">
                        <h3>Sarah Williams</h3>
                        <span>Senior Writer</span>
                        <div class="social-links">
                            <a href="#"><i class="bx bxl-facebook"></i></a>
                            <a href="#"><i class="bx bxl-twitter"></i></a>
                            <a href="#"><i class="bx bxl-linkedin"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-team">
                    <img src="/theme/linka-reference/assets/images/author-img.jpg" alt="Team Member">
                    <div class="team-content">
                        <h3>Mike Johnson</h3>
                        <span>Content Manager</span>
                        <div class="social-links">
                            <a href="#"><i class="bx bxl-facebook"></i></a>
                            <a href="#"><i class="bx bxl-twitter"></i></a>
                            <a href="#"><i class="bx bxl-linkedin"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Team Area -->

<?php
$page->public_footer();
?>
