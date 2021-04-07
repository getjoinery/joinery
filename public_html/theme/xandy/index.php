<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	//$settings = Globalvars::get_instance();
	//$site_template = $settings->get_setting('site_template');
	//include_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/logic/fundraising_thermometer.php');

	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		//'title' => 'Integral Zen',
		//'description' => 'Integral Zen',
		'body_id' => 'homepage',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('');
?>




		
		<!-- Start Banner / Slider section -->
		<section id="nexa-banner-style-7" class="nexa-banner-travel">
	      <div id="carousel-testmonial-travel-banner" class="carousel slide" data-ride="carousel">
	         <!-- Indicators -->
	         <ol class="carousel-indicators">
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="0" class="active"></li>
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="1"></li>
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="2"></li>
	         </ol>
	         <!-- Wrapper for slides -->
	         <div class="carousel-inner" role="listbox">
	            <div class="item active"><!-- Item-1-Starting -->
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="images/new/home-var3/banner.png" alt="reviewer-img" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1>Meditation</h1>
	                        <h2>Prepare for your best yoga Meditation</h2>
	                     </div>
	                  </div>
	               </div>
	            </div><!-- item-ended -->
	            <div class="item"><!-- Item-1-Starting -->
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="images/new/home-var3/banner.png" alt="reviewer-img" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1>Pranayam</h1>
	                        <h2>Prepare for your best yoga pranayam</h2>
	                     </div>
	                  </div>
	               </div>
	            </div><!-- item-ended -->
	            <div class="item"><!-- Item-1-Starting -->
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="images/new/home-var3/banner.png" alt="reviewer-img" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1>Yoga Power</h1>
	                        <h2>Get Best Yoga Power Using Meditation</h2>
	                     </div>
	                  </div>
	               </div>
	            </div><!-- item-ended -->
	         </div><!-- end-of-carousel-inner -->
	      </div><!-- end-of-carousel -->
	      <div class="scroll-btn-banner">
	         <a href="#"><i class="fa fa-angle-down" aria-hidden="true"></i></a>
	       </div>
	   </section>
	   <!-- end-banner-section -->
	   <!-- Start about section -->
		<section class="about-section">
			<div class="about-section-inner">
				<div class="vertical-space-80"></div>
				<div class="container">
					<h2 class="main-title text-center">About DreamHealth</h2>
					<h6 class="sub-title after-title text-center">What Is DreamHealth?</h6>
					<div class="vertical-space-70"></div>
					<p class="text-center">
						Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla. Maecenas volutpat fermentum ante. Sed nibh metus, mollis a dolor ut, feugiat fringilla nunc. Donec consequat pretium nunc, vel feugiat purus cursus a. Fusce eleifend luctus volutpat. Praesent mi massa, commodo at neque in, ultricies tempor quam. Donec vel magna in nibh sollicitudin eleifend a in erat. Vivamus elementum libero ac mattis condimentum. 
					</p>
					<div class="vertical-space-80"></div>
					<div class="row yoga-about-benifits v2">
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<i class="fa fa-heart" aria-hidden="true"></i>
							<h3 class="font-blue">Healthy Daily Life</h3>
							<p class="icon-text">Lorem ipsum dolor sit amet, consectetur adipiscing elit. feugiat fringilla nunc.</p>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<i class="fa fa-coffee" aria-hidden="true"></i>
							<h3 class="font-blue">Balance Body, Mind </h3>
							<p class="icon-text">Lorem ipsum dolor sit amet, consectetur adipiscing elit. feugiat fringilla nunc.</p>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<i class="fa fa-leaf" aria-hidden="true"></i>
						
							<h3 class="font-blue">Improve Your Style</h3>
							<p class="icon-text">Lorem ipsum dolor sit amet, consectetur adipiscing elit. feugiat fringilla nunc.</p>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<i class="fa fa-pagelines" aria-hidden="true"></i>
							<h3 class="font-blue">Living Skilfully</h3>
							<p class="icon-text">Lorem ipsum dolor sit amet, consectetur adipiscing elit. feugiat fringilla nunc.</p>
						</div>
					</div>
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!-- End about section -->
		<!-- Start classes section -->
		<section class="background-light-grey">
			<div class="vertical-space-80"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Our</span> Classes</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s">May Help You?</h6>
				<div class="vertical-space-80"></div>
				<div class="row">
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="class-container">
							<div class="class-image-container">
								<img src="images/new/home-var3/classes.png" class="full-width" alt="full-width">	
								<div class="image-overlay"></div>
								<img src="images/new/home-var3/classes-inner.png" alt="trainer" class="trainer-image s3">
								<div class="trainer-name">
									<span>Trainer,</span>
									<p>Kim Doe</p>
								</div>
							</div>
							<div class="class-detail background-white">
								<a href="class-detail.html"><h2 class="font-blue class-title">Pranayama Class</h2></a>
								<span>19, Mar 2017</span>
								<span class="pull-right">10.00 AM TO 11.00 AM</span>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="class-container">
							<div class="class-image-container">
								<img src="images/new/home-var3/classes.png" class="full-width" alt="full-width">	
								<div class="image-overlay"></div>
								<img src="images/new/home-var3/classes-inner.png" alt="trainer" class="trainer-image s3">
								<div class="trainer-name">
									<span>Trainer,</span>
									<p>Sunny Franko</p>
								</div>
							</div>
							<div class="class-detail background-white">
								<a href="class-detail.html"><h2 class="font-blue class-title">Yoga Rahasya Class</h2></a>
								<span>20, Oct 2017</span>
								<span class="pull-right">09.00 AM TO 10.00 AM</span>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="class-container">
							<div class="class-image-container">
								<img src="images/new/home-var3/classes.png" class="full-width" alt="full-width">	
								<div class="image-overlay"></div>
								<img src="images/new/home-var3/classes-inner.png" alt="trainer" class="trainer-image s3">
								<div class="trainer-name">
									<span>Trainer,</span>
									<p>Mark Ketty</p>
								</div>
							</div>
							<div class="class-detail background-white">
								<a href="class-detail.html"><h2 class="font-blue class-title">Vinyasa Flow Class</h2></a>
								<span>10, Dec 2017</span>
								<span class="pull-right">12.00 AM TO 02.00 AM</span>
							</div>
						</div>
					</div>
				</div>
				<div class="vertical-space-40"></div>
				<div class="text-center">
					<a href="class.html" class="view-more">View More <i class="fa fa-fighter-jet" aria-hidden="true"></i>
					</a>
				</div>
			</div>
			<div class="vertical-space-60"></div>
		</section>
		<!-- End classes section -->
		<!-- start Register section with parallex effects -->
		<section class="yoga-section">
			<div class="background-transperant-black-medium">
				<div class="vertical-space-50"></div>
				<div class="text-center">
					<div class="container">
						<div class="row">
							<div class="col-xs-12 col-sm-8 col-md-8 col-sm-offset-2">
								<h2 class="font-white">Join Dream Health Center</h2>
								<div class="vertical-space-20"></div>
								<p class="font-white">
									Our Dreamhealth Institute provide best yoga classes with good experience trainer.Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla. You need to Join us our Classes.
								</p>
								<div class="vertical-space-20"></div>
								<a href="registration.html" class="view-more2">
									Register Now
								</a>
							</div>
						</div>
					</div>
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!-- End register section -->

		<!-- Start photo gallery section -->
		<section class="background-light-grey">
			<div class="vertical-space-80"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Our</span> Photo Gallery</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s">Visit our gallery.</h6>
				<div class="vertical-space-60"></div>
				<div class="button-group filter-button-group">
				  <button data-filter="*" class="active gallery-btn">show all</button>
				  <button data-filter=".Meditation" class="gallery-btn">Meditation</button>
				  <button data-filter=".pranayam" class="gallery-btn">pranayam</button>
				  <button data-filter=".vinyasa" class="gallery-btn">vinyasa</button>
				</div>
				<div class="vertical-space-40"></div>
				<div class="grid">
				
					<div class="grid-item Meditation vinyasa">
						<a data-fancybox="gallery" href="images/gallery/small/g1.jpeg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
					<div class="grid-item Meditation">
						<a data-fancybox="gallery" href="images/gallery/small/g2.jpeg">
							<img src="images/new/home-var3/gallery.png"  class="full-width" alt="Classes">
						</a>
					</div>
					<div class="grid-item pranayam">
						<a data-fancybox="gallery" href="images/gallery/small/g3.jpg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
					<div class="grid-item vinyasa">
						<a data-fancybox="gallery" href="images/gallery/small/g4.jpg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
					<div class="grid-item Meditation pranayam vinyasa">
						<a data-fancybox="gallery" href="images/gallery/small/g5.jpg">
							<img src="images/new/home-var3/gallery.png"  class="full-width" alt="Classes">
						</a>
					</div>
					<div class="grid-item Meditation pranayam">
						<a data-fancybox="gallery" href="images/gallery/small/g6.jpg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
					<div class="grid-item Meditation vinyasa">
						<a data-fancybox="gallery" href="images/gallery/small/g7.jpg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
					<div class="grid-item Meditation vinyasa">
						<a data-fancybox="gallery" href="images/gallery/small/g8.jpg">
							<img src="images/new/home-var3/gallery.png" class="full-width"  alt="Classes">
						</a>
					</div>
				</div>
			</div>
			<div class="vertical-space-80"></div>
		</section>
		<!-- End photo gallery section -->
		<!-- Start review section -->
		<section class="review-section">
			<div class="background-transperant-black-medium">
				<div class="vertical-space-60"></div>
				<div class="container">
					<h2 class="main-title text-center wow fadeIn font-white" data-wow-duration="0.3s" data-wow-delay="0s">Good Reviews</h2>
					<h6 class="sub-title after-title text-center wow fadeIn font-white" data-wow-duration="0.3s" data-wow-delay="0.3s">Real Reviews From Real Customer</h6>
					<div class="vertical-space-50"></div>
					<div class="row">
						<div class="col-xs-12 col-sm-12 col-md-12">
							<div class="testimonial-carousel owl-carousel owl-theme">
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Kim Doe</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Johne Doe</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Mark Vally</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Ricky Matt</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Sammy Dan</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div><div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla.Donec consequat pretium nunc, vel feugiat purus cursus a. 
										</p>
									</div>
									<div class="media">
										<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>
										<div class="media-body">
											<h3 class="font-white">- Sunny Franko</h3>
											<h5 class="bold font-white">Customer</h5>
										</div>
									</div>
								</div>
								<!-- end -->
							</div>
						</div>
					</div>
				</div>
				<div class="vertical-space-60"></div>
				<div class="background-transperant-black-medium"></div>
			</div>
		</section>
		<!-- End reviews section -->
		<!-- Start team section -->
		<section class="background-white">
			<div class="vertical-space-60"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Our</span> Team</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s">May Help You?</h6>
				<div class="vertical-space-80"></div>
				<div class="row">
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="our-team-container">
							<div class="our-team">
								<img src="images/new/home-var3/team.png" class="our-team-image" alt="full-width">
								<div class="team-detail">
									<div class="vertical-space-40"></div>
									<h3 class="text-center"><a href="trainer-detail.html">John Done </a></h3>
									<h3 class="text-center"><span>Trainer</span></h3>
									<div class="vertical-space-20"></div>
									<p class="text-center">
										Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla. Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla.
									</p>
									<div class="vertical-space-20"></div>
									<ul class="social-icons text-center">
										<li>
											<a href="#">
												<i class="fa fa-facebook" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-twitter" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-linkedin" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-google-plus" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-youtube-play" aria-hidden="true"></i>
											</a>
										</li>
									</ul> <!-- social-icons -->
								</div>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="our-team-container">
							<div class="our-team">
								<img src="images/new/home-var3/team.png" class="our-team-image" alt="full-width">
								<div class="team-detail">
									<div class="vertical-space-40"></div>
									<h3 class="text-center"><a href="trainer-detail.html">Alina Morris</a></h3>
									<h3 class="text-center"><span>CEO</span></h3>
									<div class="vertical-space-20"></div>
									<p class="text-center">
										Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla. Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla.
									</p>
									<div class="vertical-space-20"></div>
									<ul class="social-icons text-center">
										<li>
											<a href="#">
												<i class="fa fa-facebook" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-twitter" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-linkedin" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-google-plus" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-youtube-play" aria-hidden="true"></i>
											</a>
										</li>
									</ul> <!-- social-icons -->
								</div>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="our-team-container">
							<div class="our-team">
								<img src="images/new/home-var3/team.png" class="our-team-image" alt="full-width">
								<div class="team-detail">
									<div class="vertical-space-40"></div>
									<h3 class="text-center"><a href="trainer-detail.html">Mark Ketty</a></h3>
									<h3 class="text-center"><span>Yoga Trainer</span></h3>
									<div class="vertical-space-20"></div>
									<p class="text-center">
										Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla. Lorem ipsum dolor sit amet, consectetur adi piscing elit. In sit amet purus id nunc porta fringilla.
									</p>
									<div class="vertical-space-20"></div>
									<ul class="social-icons text-center">
										<li>
											<a href="#">
												<i class="fa fa-facebook" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-twitter" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-linkedin" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-google-plus" aria-hidden="true"></i>
											</a>
										</li>
										<li>
											<a href="#">
												<i class="fa fa-youtube-play" aria-hidden="true"></i>
											</a>
										</li>
									</ul> <!-- social-icons -->
								</div>
							</div>
						</div>						
					</div>
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!-- End Team section -->
		<!-- Start blog section -->
		<section class="background-light-grey">
			<div class="vertical-space-60"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Latest</span> Blog</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s">Our Experts Says!</h6>
				<div class="vertical-space-80"></div>
				<div class="row">
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="blog-container">
							<img src="images/new/home-var3/blog.png" class="full-width" alt="full-width">
							<div class="blog-detail background-white">
								<a href="blog-detail.html"><h2 class="font-blue class-title">Why Should go to DreamHealth</h2></a>
								<p class="blog-tt-txt">
									Lorem ipsum dolor sit amet, consectetur adipiscing elit. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
								</p>
								<a href="#"><span>19, Mar 2017</span></a>
								<a href="#"><span class="pull-right">John Doe</span></a>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="blog-container">
							<img src="images/new/home-var3/blog.png" class="full-width" alt="full-width">
							<div class="blog-detail background-white">
								<a href="blog-detail.html"><h2 class="font-blue class-title">Yoga Stretch Your Body & Mind</h2></a>
								<p class="blog-tt-txt">
									Lorem ipsum dolor sit amet, consectetur adipiscing elit. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
								</p>
								<a href="#"><span>19, Mar 2017</span></a>
								<a href="#"><span class="pull-right">John Doe</span></a>
							</div>
						</div>
					</div>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="blog-container">
							<img src="images/new/home-var3/blog.png" class="full-width" alt="full-width">
							<div class="blog-detail background-white">
								<a href="blog-detail.html"><h2 class="font-blue class-title">Benifits yoga For Gentleman</h2></a>
								<p class="blog-tt-txt">
									Lorem ipsum dolor sit amet, consectetur adipiscing elit. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
								</p>
								<a href="#"><span>19, Mar 2017</span></a>
								<a href="#"><span class="pull-right">John Doe</span></a>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="vertical-space-60"></div>
		</section>
		<!-- End blog section -->


	
	
	
	
	
	
	
	
	
	
	
	<div class="entry-content">
		


		
		<div style="border: 1px solid rgba(211,211,211,1); margin-bottom: 20px; padding: 20px;">
		<?php 
		
		$page_content = new PageContent(1, TRUE);
		if($page_content->get('pac_is_published')){
			echo $page_content->get_filled_content(); 
		}
		?>
		<!--
		<h1 class="cta-title">The Whole Spectrum of Shadows Self-paced Course</h1>		
<img style="float:left; margin-right:20px;" src="/uploads/small/INTEGRAL_ZEN_Shadow_Webinar_Self_Paced-2_skwsetni.png">				


<p>A self-paced version of our live "Whole Spectrum of Shadows - Level 1" course. Topics covered include Integral Theory fundamentals: levels, states, quadrants, lines, and types...and how they interact with shadows and trauma.</p>

<p>This online course is offered on a free/donation basis.</p>
							
								<br /><a class="et_pb_button" href="/event/37/The-Whole-Spectrum-of-Shadows-Self-paced-Course-Level-1">Read more</a>
				 -->					
	<div style="clear:both;">&nbsp;</div>
	</div><!-- .entry-content -->

	

	
 






									<ul class="home-ctas">



												<li class="single-cta">
														<?php 
		$page_content = new PageContent(2, TRUE);
		if($page_content->get('pac_is_published')){
			echo $page_content->get_filled_content(); 
		}
		?>

							
						</li>
						
					
						
						
											<li class="single-cta">
											<?php
													$page_content = new PageContent(3, TRUE);
		if($page_content->get('pac_is_published')){
			echo $page_content->get_filled_content(); 
		}
		?>

						</li>	



												
	
						
						
						
					
						
											</ul><!-- home-ctas -->	
					

		<div style="border: 1px solid rgba(211,211,211,1); margin-bottom: 50px; padding: 20px;">
		
		<h2 class="cta-title">Sign up for updates</h2>	
		<a class="button button-dark" href="/community/newsletter/">Sign up for the newsletter</a>

	

<?php
	//echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
