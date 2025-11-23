<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

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
                    <div class="col-lg-6 col-md-6">
                        <div class="single-featured">
                            <a href="#" class="blog-img">
                                <img src="/theme/phillyzouk/assets/images/home-three/business/1.jpg" alt="Image">
                                <span>News</span>
                            </a>
                            <div class="featured-content">
                                <ul>
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            Admin By Jhona Walker
                                        </a>
                                    </li>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        25 Mar 2024
                                    </li>
                                </ul>
                                <a href="#">
                                    <h3>The Supreme Art of War is to Subdue the Enemy Without Fighting</h3>
                                </a>
                                <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>
                                <a href="#" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6">
                        <div class="single-featured">
                            <a href="#" class="blog-img">
                                <img src="/theme/phillyzouk/assets/images/home-three/business/2.jpg" alt="Image">
                                <span>News</span>
                            </a>
                            <div class="featured-content">
                                <ul>
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            Admin By Kilva Walker
                                        </a>
                                    </li>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        26 Mar 2024
                                    </li>
                                </ul>
                                <a href="#">
                                    <h3>No Fixed Abode: Quitting Home Ownership</h3>
                                </a>
                                <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>
                                <a href="#" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6">
                        <div class="single-featured">
                            <a href="#" class="blog-img">
                                <img src="/theme/phillyzouk/assets/images/home-three/business/3.jpg" alt="Image">
                                <span>Business</span>
                            </a>
                            <div class="featured-content">
                                <ul>
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            Admin By Jexk Walker
                                        </a>
                                    </li>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        27 Mar 2024
                                    </li>
                                </ul>
                                <a href="#">
                                    <h3>Developing Self-Control Through The Wonders of Intermittent Fasting</h3>
                                </a>
                                <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>
                                <a href="#" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6">
                        <div class="single-featured">
                            <a href="#" class="blog-img">
                                <img src="/theme/phillyzouk/assets/images/home-three/business/4.jpg" alt="Image">
                                <span>Economy</span>
                            </a>
                            <div class="featured-content">
                                <ul>
                                    <li>
                                        <a href="#" class="admin">
                                            <i class="bx bx-user"></i>
                                            Admin By Anna Dew
                                        </a>
                                    </li>
                                    <li>
                                        <i class="bx bx-calendar"></i>
                                        28 Mar 2024
                                    </li>
                                </ul>
                                <a href="#">
                                    <h3>The 4 convolution's Neural Network Models That Can Classify</h3>
                                </a>
                                <p>Lorem ipsum dolor sit amet consectetur adipisicing elit. Facere laboriosam eveniet debitis tenetur.</p>
                                <a href="#" class="read-more">Read More</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="right-blog-editor-wrap-three">
                    <div class="right-blog-editor media align-items-center">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/business/7.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>Advantage & Disadvantages of having Matchbook</h3>
                            </a>
                            <span>
                                <i class="bx bx-calendar"></i>
                                25 Mar 2024
                            </span>
                        </div>
                    </div>

                    <div class="right-blog-editor media align-items-center">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/business/8.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>The Scariest Moment is Always Just Before You</h3>
                            </a>
                            <span>
                                <i class="bx bx-calendar"></i>
                                26 Mar 2024
                            </span>
                        </div>
                    </div>

                    <div class="right-blog-editor media align-items-center">
                        <a href="#">
                            <img src="/theme/phillyzouk/assets/images/home-three/business/9.jpg" alt="Image">
                        </a>
                        <div class="right-blog-content">
                            <a href="#">
                                <h3>The Expert's Guide To Surviving long Haul Flights</h3>
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
<!-- End Latest Project Area -->

<?php
$page->public_footer();
?>
