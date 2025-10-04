<?php
// Canvas theme homepage
// Include PublicPage class following best practices
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home - Corporate Layout 2 | Canvas',
    'showheader' => true
));
?>

		<section id="slider" class="slider-element slider-parallax swiper_wrapper vh-75">
			<div class="slider-inner">

				<div class="swiper swiper-parent">
					<div class="swiper-wrapper">
						<div class="swiper-slide dark">
							<div class="container">
								<div class="slider-caption slider-caption-center">
									<h2 data-animate="fadeInUp">Welcome to Canvas</h2>
									<p class="d-none d-sm-block" data-animate="fadeInUp" data-delay="200">Create just what you need for your Perfect Website. Choose from a wide range of Elements &amp; simply put them on our Canvas.</p>
								</div>
							</div>
							<div class="swiper-slide-bg" style="background-image: url('images/slider/swiper/1.jpg');"></div>
						</div>
						<div class="swiper-slide dark">
							<div class="container">
								<div class="slider-caption slider-caption-center">
									<h2 data-animate="fadeInUp">Beautifully Flexible</h2>
									<p class="d-none d-sm-block" data-animate="fadeInUp" data-delay="200">Looks beautiful &amp; ultra-sharp on Retina Screen Displays. Powerful Layout with Responsive functionality that can be adapted to any screen size.</p>
								</div>
							</div>
							<div class="video-wrap no-placeholder">
								<video poster="images/videos/deskwork.jpg" preload="auto" loop autoplay muted playsinline>
									<source src='images/videos/deskwork.mp4' type='video/mp4'>
									<source src='images/videos/deskwork.webm' type='video/webm'>
								</video>
								<div class="video-overlay" style="background-color: rgba(0,0,0,0.55);"></div>
							</div>
						</div>
						<div class="swiper-slide">
							<div class="container">
								<div class="slider-caption">
									<h2 data-animate="fadeInUp">Great Performance</h2>
									<p class="d-none d-sm-block" data-animate="fadeInUp" data-delay="200">You'll be surprised to see the Final Results of your Creation &amp; would crave for more.</p>
								</div>
							</div>
							<div class="swiper-slide-bg" style="background-image: url('images/slider/swiper/3.jpg'); background-position: center top;"></div>
						</div>
					</div>
					<div class="slider-arrow-left"><i class="uil uil-angle-left-b"></i></div>
					<div class="slider-arrow-right"><i class="uil uil-angle-right-b"></i></div>
					<div class="slide-number"><div class="slide-number-current"></div><span>/</span><div class="slide-number-total"></div></div>
				</div>

			</div>
		</section>

		<!-- Content
		============================================= -->
		<section id="content">
			<div class="content-wrap">

				<div class="promo promo-light promo-full mb-6 header-stick border-top-0 p-5">
					<div class="container">
						<div class="row align-items-center">
							<div class="col-12 col-lg">
								<h3>Try Premium Free for <span>30 Days</span> and you'll never regret it!</h3>
								<span>Starts at just <em>$0/month</em> afterwards. No Ads, No Gimmicks and No SPAM. Just Real Content.</span>
							</div>
							<div class="col-12 col-lg-auto mt-4 mt-lg-0">
								<a href="#" class="button button-large button-circle m-0">Start Trial</a>
							</div>
						</div>
					</div>
				</div>

				<div class="container">

					<div class="row col-mb-50">
						<div class="col-sm-6 col-lg-3">
							<div class="feature-box fbox-center fbox-light fbox-effect border-bottom-0">
								<div class="fbox-icon">
									<a href="#"><i class="i-alt border-0 bi-cart"></i></a>
								</div>
								<div class="fbox-content">
									<h3>e-Commerce Solutions<span class="subtitle">Start your Own Shop today</span></h3>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-3">
							<div class="feature-box fbox-center fbox-light fbox-effect border-bottom-0">
								<div class="fbox-icon">
									<a href="#"><i class="i-alt border-0 bi-wallet"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Easy Payment Options<span class="subtitle">Credit Cards &amp; PayPal Support</span></h3>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-3">
							<div class="feature-box fbox-center fbox-light fbox-effect border-bottom-0">
								<div class="fbox-icon">
									<a href="#"><i class="i-alt border-0 bi-megaphone"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Instant Notifications<span class="subtitle">Realtime Email &amp; SMS Support</span></h3>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-3">
							<div class="feature-box fbox-center fbox-light fbox-effect border-bottom-0">
								<div class="fbox-icon">
									<a href="#"><i class="i-alt border-0 bi-fire"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Hot Offers Daily<span class="subtitle">Upto 50% Discounts</span></h3>
								</div>
							</div>
						</div>
					</div>

					<div class="line"></div>

					<div class="row col-mb-50">
						<div class="col-md-5">
							<a href="https://vimeo.com/101373765" class="d-block position-relative rounded overflow-hidden" data-lightbox="iframe">
								<img src="images/others/1.jpg" alt="Image" class="w-100">
								<div class="bg-overlay">
									<div class="bg-overlay-content dark">
										<i class="i-circled i-light uil uil-play m-0"></i>
									</div>
									<div class="bg-overlay-bg op-06 dark"></div>
								</div>
							</a>
						</div>

						<div class="col-md-7">
							<div class="heading-block">
								<h2>Globally Preferred Ecommerce Platform</h2>
							</div>

							<p>Worldwide John Lennon, mobilize humanitarian; emergency response donors; cause human experience effect. Volunteer Action Against Hunger Aga Khan safeguards women's.</p>

							<div class="row col-mb-30">
								<div class="col-sm-6 col-md-12 col-lg-6">
									<ul class="iconlist iconlist-color mb-0">
										<li><i class="fa-solid fa-caret-right"></i> Responsive Ready Layout</li>
										<li><i class="fa-solid fa-caret-right"></i> Retina Display Supported</li>
										<li><i class="fa-solid fa-caret-right"></i> Powerful &amp; Optimized Code</li>
										<li><i class="fa-solid fa-caret-right"></i> 380+ Templates Included</li>
									</ul>
								</div>

								<div class="col-sm-6 col-md-12 col-lg-6">
									<ul class="iconlist iconlist-color mb-0">
										<li><i class="fa-solid fa-caret-right"></i> 12+ Headers Included</li>
										<li><i class="fa-solid fa-caret-right"></i> 1000+ HTML Pages</li>
										<li><i class="fa-solid fa-caret-right"></i> 30+ Portfolio Layouts</li>
										<li><i class="fa-solid fa-caret-right"></i> Extensive Documentation</li>
									</ul>
								</div>
							</div>
						</div>
					</div>

				</div>

				<div class="section mt-0 border-top-0">
					<div class="container">

						<div class="heading-block center m-0">
							<h3>Powerful Features</h3>
						</div>

					</div>
				</div>

				<div class="container">

					<div class="row col-mb-50">

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn">
								<div class="fbox-icon">
									<a href="#"><i class="bi-display"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Responsive Framework</h3>
									<p>Powerful Layout with Responsive functionality that can be adapted to any screen size.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="200">
								<div class="fbox-icon">
									<a href="#"><i class="bi-eye"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Retina Graphics</h3>
									<p>Looks beautiful &amp; ultra-sharp on Retina Displays with Retina Icons, Fonts &amp; Images.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="400">
								<div class="fbox-icon">
									<a href="#"><i class="bi-star"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Powerful Performance</h3>
									<p>Optimized code that are completely customizable and deliver unmatched fast performance.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="600">
								<div class="fbox-icon">
									<a href="#"><i class="bi-video"></i></a>
								</div>
								<div class="fbox-content">
									<h3>HTML5 Video</h3>
									<p>Canvas provides support for Native HTML5 Videos that can be added to a Background.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="800">
								<div class="fbox-icon">
									<a href="#"><i class="bi-columns"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Parallax Support</h3>
									<p>Display your Content attractively using Parallax Sections that have unlimited customizable areas.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="1000">
								<div class="fbox-icon">
									<a href="#"><i class="bi-browser-edge"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Endless Possibilities</h3>
									<p>Complete control on each &amp; every element that provides endless customization possibilities.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="1200">
								<div class="fbox-icon">
									<a href="#"><i class="bi-umbrella"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Light &amp; Dark Color Schemes</h3>
									<p>Change your Website's Primary Scheme instantly by simply adding the dark class to the body.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="1400">
								<div class="fbox-icon">
									<a href="#"><i class="bi-text-indent-left"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Customizable Fonts</h3>
									<p>Use any Font you like from Google Web Fonts, Typekit or other Web Fonts. They will blend in perfectly.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="1600">
								<div class="fbox-icon">
									<a href="#"><i class="bi-layers"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Slider Revolution Included</h3>
									<p>Canvas included 20+ custom designed Slider Revolution Templates to Cater your Slider Needs.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="1800">
								<div class="fbox-icon">
									<a href="#"><i class="bi-sun"></i></a>
								</div>
								<div class="fbox-content">
									<h3>600+ Icons Included</h3>
									<p>Packed full of 600+ Font Icons would let you use Icons from 5 Font Icon Families.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="2000">
								<div class="fbox-icon">
									<a href="#"><i class="bi-sprint"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Bootstrap Compatible</h3>
									<p>Use the amazing features of the Bootstrap Framework applying the Grids, Font Sizes, Tables etc.</p>
								</div>
							</div>
						</div>

						<div class="col-sm-6 col-lg-4">
							<div class="feature-box fbox-sm fbox-plain" data-animate="fadeIn" data-delay="2200">
								<div class="fbox-icon">
									<a href="#"><i class="bi-tools"></i></a>
								</div>
								<div class="fbox-content">
									<h3>Easy to Customize</h3>
									<p>Customize your Website as you like with the Help of the most Configurable Theme Settings.</p>
								</div>
							</div>
						</div>

					</div>

				</div>

				<div class="section parallax dark mb-0" style="background-image: url('images/parallax/3.jpg');" data-bottom-top="background-position:0px 0px;" data-top-bottom="background-position:0px -300px;">

					<div class="container">

						<div class="heading-block center">
							<h2>Canvas Macbook Mockup</h2>
							<span>Unleash your creativity with the power of Canvas Framework.</span>
						</div>

						<div class="fslider" data-speed="700" data-pause="7500" data-arrows="false">
							<div class="flexslider">
								<div class="slider-wrap">
									<div class="slide">
										<img src="images/slider/boxed/2.jpg" alt="Slider">
									</div>
									<div class="slide">
										<img src="images/slider/boxed/3.jpg" alt="Slider">
									</div>
									<div class="slide">
										<img src="images/slider/boxed/1.jpg" alt="Slider">
									</div>
								</div>
							</div>
						</div>

					</div>

				</div>

				<div class="container">

					<div class="row col-mb-50">
						<div class="col-md-8">

							<h4>Our Skills</h4>

							<ul class="skills">
								<li data-percent="80">
									<span>Wordpress</span>
									<div class="progress">
										<div class="progress-percent"><div class="counter counter-inherit counter-instant"><span data-from="0" data-to="80" data-refresh-interval="30" data-speed="1000"></span>%</div></div>
									</div>
								</li>
								<li data-percent="60">
									<span>CSS3</span>
									<div class="progress">
										<div class="progress-percent"><div class="counter counter-inherit counter-instant"><span data-from="0" data-to="60" data-refresh-interval="30" data-speed="1000"></span>%</div></div>
									</div>
								</li>
								<li data-percent="90">
									<span>HTML5</span>
									<div class="progress">
										<div class="progress-percent"><div class="counter counter-inherit counter-instant"><span data-from="0" data-to="90" data-refresh-interval="30" data-speed="1000"></span>%</div></div>
									</div>
								</li>
								<li data-percent="70">
									<span>jQuery</span>
									<div class="progress">
										<div class="progress-percent"><div class="counter counter-inherit counter-instant"><span data-from="0" data-to="70" data-refresh-interval="30" data-speed="1000"></span>%</div></div>
									</div>
								</li>
								<li data-percent="85">
									<span>Bootstrap</span>
									<div class="progress">
										<div class="progress-percent"><div class="counter counter-inherit counter-instant"><span data-from="0" data-to="85" data-refresh-interval="30" data-speed="1000"></span>%</div></div>
									</div>
								</li>
							</ul>

						</div>

						<div class="col-md-4">
							<h4>Popular Posts</h4>

							<div class="posts-sm row col-mb-30" id="home-recent-news">
								<div class="entry col-12">
									<div class="grid-inner row g-0">
										<div class="col-auto">
											<div class="entry-image">
												<a href="#"><img src="images/magazine/small/1.jpg" alt="Image"></a>
											</div>
										</div>
										<div class="col ps-3">
											<div class="entry-title">
												<h4><a href="#">Lorem ipsum dolor sit amet, consectetur</a></h4>
											</div>
											<div class="entry-meta">
												<ul>
													<li><i class="uil uil-comments-alt"></i> 35 Comments</li>
												</ul>
											</div>
										</div>
									</div>
								</div>

								<div class="entry col-12">
									<div class="grid-inner row g-0">
										<div class="col-auto">
											<div class="entry-image">
												<a href="#"><img src="images/magazine/small/2.jpg" alt="Image"></a>
											</div>
										</div>
										<div class="col ps-3">
											<div class="entry-title">
												<h4><a href="#">Elit Assumenda vel amet dolorum quasi</a></h4>
											</div>
											<div class="entry-meta">
												<ul>
													<li><i class="uil uil-comments-alt"></i> 24 Comments</li>
												</ul>
											</div>
										</div>
									</div>
								</div>

								<div class="entry col-12">
									<div class="grid-inner row g-0">
										<div class="col-auto">
											<div class="entry-image">
												<a href="#"><img src="images/magazine/small/3.jpg" alt="Image"></a>
											</div>
										</div>
										<div class="col ps-3">
											<div class="entry-title">
												<h4><a href="#">Debitis nihil placeat, illum est nisi</a></h4>
											</div>
											<div class="entry-meta">
												<ul>
													<li><i class="uil uil-comments-alt"></i> 19 Comments</li>
												</ul>
											</div>
										</div>
									</div>
								</div>
							</div>

						</div>
					</div>

				</div>

			</div>
		</section><!-- #content end -->

<?php
$page->public_footer();
?>