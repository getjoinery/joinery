<?php
/**
 * Homepage for Linka Reference Theme
 *
 * @version 1.0.0
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => $settings->get_setting('site_name', true, true) . ' - Home',
    'showheader' => true
));
?>

<!-- Start Main Blog Area -->
<section class="main-blog-area pb-100">
    <div class="container-fluid">
        <div class="main-blog-slider-item-wrap owl-carousel owl-theme">
            <div class="single-main-blog-item">
                <img src="/theme/linka-reference/assets/images/home-one/main-blog-img/1.jpg" alt="Image">
                <a href="#" class="blog-link">Featured</a>

                <div class="main-blog-content">
                    <a href="#">
                        <h3>Welcome to <?php echo htmlspecialchars($settings->get_setting('site_name', true, true)); ?></h3>
                    </a>

                    <ul>
                        <li>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="single-main-blog-item">
                <img src="/theme/linka-reference/assets/images/home-one/main-blog-img/2.jpg" alt="Image">
                <a href="#" class="blog-link">News</a>

                <div class="main-blog-content">
                    <a href="#">
                        <h3>Discover Amazing Stories and Insights</h3>
                    </a>

                    <ul>
                        <li>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="single-main-blog-item">
                <img src="/theme/linka-reference/assets/images/home-one/main-blog-img/3.jpg" alt="Image">
                <a href="#" class="blog-link">Lifestyle</a>

                <div class="main-blog-content">
                    <a href="#">
                        <h3>Explore Our Latest Content and Updates</h3>
                    </a>

                    <ul>
                        <li>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Main Blog Area -->

<!-- Start Featured Area -->
<section class="featured-area one pb-70">
    <div class="container">
        <div class="section-title">
            <h2>Featured</h2>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/1.jpg" alt="Image">
                        <span>Lifestyle</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>A Simple Way to Address the Gap Between Attention</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/2.jpg" alt="Image">
                        <span>Fashion</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>Style Tips From Top Designer of United States</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/3.jpg" alt="Image">
                        <span>Music</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>Top Trending Music Concert Program This Year</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/4.jpg" alt="Image">
                        <span>Culture</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>What to Expect From the 2024 Oscar Nomination</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/5.jpg" alt="Image">
                        <span>World</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>Impossibly High Beauty Standards Are Ruining My Self Esteem</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-featured">
                    <a href="#" class="blog-img">
                        <img src="/theme/linka-reference/assets/images/home-one/featured-img/6.jpg" alt="Image">
                        <span>Tech</span>
                    </a>

                    <div class="featured-content">
                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>

                        <a href="#">
                            <h3>The 4 Neural Network Models That Can Classify</h3>
                        </a>

                        <a href="#" class="read-more">Read More</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Featured Area -->

<!-- Start Editor Choice Area -->
<section class="editor-choice-area bg-color pt-100 pb-70">
    <div class="container">
        <div class="section-title">
            <h2>Editor's Choice</h2>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="editor-blog">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/editor-img/1.jpg" alt="Image">
                    </a>

                    <div class="editor-blog-content">
                        <a href="#">
                            <h3>The City of London Wants to Have It Brexit Cake In it Just To</h3>
                        </a>

                        <p><?php echo htmlspecialchars($settings->get_setting('site_description', true, true)); ?></p>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="right-blog-editor media align-items-center">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/editor-img/2.jpg" alt="Image">
                    </a>

                    <div class="right-blog-content">
                        <a href="#">
                            <h3>Advantage & Disadvantages of having Matchbook</h3>
                        </a>

                        <span>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </span>
                    </div>
                </div>

                <div class="right-blog-editor media align-items-center">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/editor-img/3.jpg" alt="Image">
                    </a>

                    <div class="right-blog-content">
                        <a href="#">
                            <h3>The Scariest Moment is Always Just Before You</h3>
                        </a>

                        <span>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </span>
                    </div>
                </div>

                <div class="right-blog-editor media align-items-center">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/editor-img/4.jpg" alt="Image">
                    </a>

                    <div class="right-blog-content">
                        <a href="#">
                            <h3>The Expert's Guide To Surviving long Haul Flights</h3>
                        </a>

                        <span>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </span>
                    </div>
                </div>

                <div class="right-blog-editor media align-items-center">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/editor-img/5.jpg" alt="Image">
                    </a>

                    <div class="right-blog-content">
                        <a href="#">
                            <h3>The Scariest Moment Is Always Just Before You</h3>
                        </a>

                        <span>
                            <i class="bx bx-calendar"></i>
                            <?php echo date('d M Y'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Editor Choice Area -->

<!-- Start Inspiration Area -->
<section class="inspiration-area pt-100 pb-70">
    <div class="container">
        <div class="section-title">
            <h2>Inspiration</h2>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/1.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Food</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>The two Important Tools to Reconnect Your Partner</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration mt-minus-50">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/2.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Fitness</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>Get Scary With This Vegan Spooky Spider Crackle Cake!</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/3.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Tech</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>No Fixed Abode: Quitting Home Ownership</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/4.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Travel</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>Impossibly High Beauty Standards Are Ruining My Self Esteem</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration mt-minus-50">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/5.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Summer</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>Do You Want Stronger Friendships, a More Balanced Mindset</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="single-inspiration">
                    <a href="#">
                        <img src="/theme/linka-reference/assets/images/home-one/inspiration-img/6.jpg" alt="Image">
                    </a>
                    <span class="blog-link">Travel</span>

                    <div class="inspiration-content">
                        <a href="#">
                            <h3>The 4 convolution's Neural Network Models That Can Classify</h3>
                        </a>

                        <ul>
                            <li>
                                <i class="bx bx-calendar"></i>
                                <?php echo date('d M Y'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- End Inspiration Area -->

<?php
$page->public_footer();
?>
