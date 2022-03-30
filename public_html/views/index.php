<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPageTW::BeginPage('');
	
	?>
		<!-- Hero section -->
		<div class="owl-carousel owl-nav-overlay owl-dots-overlay" data-owl-nav="true" data-owl-dots="true" data-owl-items="1">
			<!-- Slider Item 1 -->
			<div class="bg-image" data-bg-src="../assets/images/background.jpg">
				<div class="section-xl bg-black-03">
					<div class="container text-center">
						<div class="row">
							<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
								<h6 class="uppercase font-weight-light letter-spacing-1 margin-bottom-20 text-white">Hello there! This Template is</h6>
								<h1 class="font-weight-bold uppercase letter-spacing-1">Made for your Business</h1>
								<a class="button button-xl button-radius button-outline-white margin-top-20 button-font-2" href="#">Explore</a>
							</div>
						</div><!-- end row -->
					</div><!-- end container -->
				</div>
			</div>
			<!-- Slider Item 2 -->
			<div class="bg-image" data-bg-src="../assets/images/background.jpg">
				<div class="section-xl bg-black-03">
					<div class="container text-center">
						<div class="row">
							<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
								<h5 class="uppercase font-weight-light letter-spacing-1 margin-bottom-20 text-white">Hello there! This Template is</h5>
								<h1 class="font-weight-bold uppercase letter-spacing-1">Made for your Business</h1>
								<a class="button button-xl button-radius button-outline-white margin-top-20 button-font-2" href="#">Explore</a>
							</div>
						</div><!-- end row -->
					</div><!-- end container -->
				</div>
			</div>
		</div><!-- end owl-carousel -->
		<!-- end Hero section -->
		
		<!-- Services section -->
		<div class="section">
			<div class="container">
				<div class="row icon-5xl text-center">
					<!-- Icon text box 1 -->
					<div class="col-12 col-md-4">
						<i class="ti-star text-dark"></i>
						<h5 class="font-weight-normal margin-top-20">Social Marketing</h5>
						<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa.</p>
					</div>
					<!-- Icon text box 2 -->
					<div class="col-12 col-md-4">
						<i class="ti-blackboard text-dark"></i>
						<h5 class="font-weight-normal margin-top-20">Strategy Planning</h5>
						<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa.</p>
					</div>
					<!-- Icon text box 3 -->
					<div class="col-12 col-md-4">
						<i class="ti-comment-alt text-dark"></i>
						<h5 class="font-weight-normal margin-top-20">Consulting</h5>
						<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa.</p>
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<!-- end Services section -->

		<!-- About section -->
		<div class="section padding-top-0">
			<div class="container">
				<div class="row align-items-center col-spacing-50">
					<div class="col-12 col-lg-6">
						<img class="box-shadow" src="../assets/images/col-1.jpg" alt="">
					</div>
					<div class="col-12 col-lg-6">
						<h3 class="font-weight-light margin-bottom-20">We believe that teamwork divides the tasks and multiplies the success</h3>
						<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
						<a class="button button-lg button-radius button-reveal-right-dark margin-top-30" href="#"><i class="ti-arrow-right"></i><span>Learn More</span></a>
					</div>
				</div>
			</div><!-- end container -->
		</div>
		<!-- end About section -->

		<!-- Progress bars -->
		<div class="section padding-top-0">
			<div class="container">
				<div class="row">
					<!-- Progress bar box 1 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Consulting</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 90%;" aria-valuenow="90" aria-valuemin="0" aria-valuemax="100">
                                    <span>90%</span>
                                </div>
                            </div>
                        </div>
					</div>
					<!-- Progress bar box 2 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Social Marketing</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 100%;" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100">
                                    <span>100%</span>
                                </div>
                            </div>
                        </div>
					</div>
					<!-- Progress bar box 3 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Management</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 75%;" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100">
                                    <span>75%</span>
                                </div>
                            </div>
                        </div>
					</div>
					<!-- Progress bar box 4 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Branding</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 95%;" aria-valuenow="95" aria-valuemin="0" aria-valuemax="100">
                                    <span>95%</span>
                                </div>
                            </div>
                        </div>
					</div>
					<!-- Progress bar box 5 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Strategy & Planning</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 85%;" aria-valuenow="85" aria-valuemin="0" aria-valuemax="100">
                                    <span>85%</span>
                                </div>
                            </div>
                        </div>
					</div>
					<!-- Progress bar box 6 -->
					<div class="col-12 col-md-6 col-lg-4">
						<div class="progress-box">
                            <h6 class="font-small font-weight-normal uppercase">Digital Solutions</h6>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 90%;" aria-valuenow="90" aria-valuemin="0" aria-valuemax="100">
                                    <span>90%</span>
                                </div>
                            </div>
                        </div>
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<!-- end Progress bars -->

		<!-- Parallax section -->
		<div class="section-xl bg-image parallax" data-bg-src="../assets/images/background.jpg">
			<div class="bg-black-05">
				<div class="container">
					<div class="row align-items-center">
						<div class="col-12 col-lg-8 col-xl-7">
							<h1 class="font-weight-light">Feel free to ask any questions</h1>
							<p class="font-large">Etiam sit amet orci eget eros faucibus tincidunt.</p>
						</div>
						<div class="col-12 col-lg-4 col-xl-5 text-lg-right">
							<a class="button button-xl button-radius button-reveal-right-outline-white" href="#"><i class="ti-arrow-right"></i><span>Get In Touch</span></a>
						</div>
					</div><!-- end row -->
				</div><!-- end container -->
			</div>
		</div>
		<!-- end Parallax section -->

		<!-- Testimonial section -->
		<div class="section">
			<div class="container">
				<div class="margin-bottom-70">
					<div class="row text-center">
						<div class="col-12 col-md-10 offset-md-1 col-lg-8 offset-lg-2">
							<h6 class="font-small font-weight-normal uppercase">Testimonial</h6>
							<h2>What People Say</h2>
						</div>
					</div>
				</div>
				<div class="testimonial-grid column-2">
					<!-- Testimonial box 1 -->
					<div class="testimonial-grid-box">
						<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Emiley Haley</h5>
							<span class="font-small font-weight-normal">Manager - Mono</span>
						</div>
					</div>
					<!-- Testimonial box 2 -->
					<div class="testimonial-grid-box">
						<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Andrew Palmer</h5>
							<span class="font-small font-weight-normal">Developer - Mono</span>
						</div>
					</div>
					<!-- Testimonial box 3 -->
					<div class="testimonial-grid-box">
						<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Anna Mullen</h5>
							<span class="font-small font-weight-normal">Designer - Mono</span>
						</div>
					</div>
					<!-- Testimonial box 4 -->
					<div class="testimonial-grid-box">
						<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
							<h5 class="font-weight-normal margin-0 line-height-140">John Smith</h5>
							<span class="font-small font-weight-normal">Marketer - Mono</span>
						</div>
					</div>
				</div><!-- end testimonial-grid -->
			</div><!-- end row -->
		</div>
		<!-- end Testimonial section -->

		<!-- Clients section -->
		<div class="section bg-grey-lighter">
			<div class="container">
				<div class="owl-carousel" data-owl-nav="true" data-owl-dots="false" data-owl-margin="50" data-owl-autoplay="true" data-owl-xs="1" data-owl-sm="2" data-owl-md="3" data-owl-lg="4" data-owl-xl="5">
					<!-- Client box 1 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 2 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 3 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 4 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 5 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 6 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
					<!-- Client box 7 -->
					<div class="client-box">
						<a href="#">
							<img src="../assets/images/col-5.jpg" alt="">
						</a>
					</div>
				</div><!-- end owl-carousel -->
			</div><!-- end container -->
		</div>
		<!-- end Clients section -->
		<?php

	echo PublicPageTW::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
