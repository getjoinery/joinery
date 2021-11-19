<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('');
	
	?>
		<!-- Hero section -->
		<div class="owl-carousel owl-nav-overlay owl-dots-overlay" data-owl-nav="true" data-owl-dots="true" data-owl-items="1">
			<!-- Slider Item 1 -->
			<div class="bg-image" data-bg-src="/static_files/banners/devon-jerry-banner2.jpg">
				<div class="section-xl bg-black-03">
					<div class="container text-center">
						<div class="row">
							<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
								<h6 class="uppercase font-weight-light letter-spacing-1 margin-bottom-20 text-white"></h6>
								<h1 class="font-weight-bold uppercase letter-spacing-1">Online Zouk Lessons</h1>
								<a class="button button-xl button-radius button-outline-white margin-top-20 button-font-2" href="/events">See Courses</a>
							</div>
						</div><!-- end row -->
					</div><!-- end container -->
				</div>
			</div>
			<!-- Slider Item 2 -->
			<div class="bg-image" data-bg-src="/static_files/banners/devon-jerry-banner.jpg">
				<div class="section-xl bg-black-03">
					<div class="container text-center">
						<div class="row">
							<div class="col-12 col-sm-10 offset-sm-1 col-md-8 offset-md-2">
								<h5 class="uppercase font-weight-light letter-spacing-1 margin-bottom-20 text-white"></h5>
								<h1 class="font-weight-bold uppercase letter-spacing-1">Online Zouk Lessons</h1>
								<a class="button button-xl button-radius button-outline-white margin-top-20 button-font-2" href="/events">See Courses</a>
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
						<!--<i class="ti-star text-dark"></i>-->
						<h5 class="font-weight-normal margin-top-20">Recorded Classes</h5>
						<p>Find it challenging to make it onto live zoom sessions or live in a dance wasteland? We've got you covered. All our courses are hi-def, succinctly edited, and vouched for by many!</p>
					</div>
					<!-- Icon text box 2 -->
					<div class="col-12 col-md-4">
						<!--<i class="ti-blackboard text-dark"></i>-->
						<h5 class="font-weight-normal margin-top-20">Specificity without Dogma</h5>
						<p>Frustrated with vague descriptions of "energy"?  We like to get specific, detached of dogma, with vibrant imagery that shows you all possibilities informed by anatomy and physics. </p>
					</div>
					<!-- Icon text box 3 -->
					<div class="col-12 col-md-4">
						<!--<i class="ti-comment-alt text-dark"></i>-->
						<h5 class="font-weight-normal margin-top-20">Hilarious Delivery</h5>
						<p>We're pretty funny - just ask our therapists and our students.</p>
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
						<img class="box-shadow" src="/uploads/medium/Decon_zouk_5_x74ki5j.jpg" alt="">
					</div>
					<div class="col-12 col-lg-6">
						<h3 class="font-weight-light margin-bottom-20">Our focus is on quality of movement, healthy body awareness, comfort, and longevity.</h3>
						<p></p>
						<a class="button button-lg button-radius button-reveal-right-dark margin-top-30" href="#"><i class="ti-arrow-right"></i><span>Learn More</span></a>
					</div>
				</div>
			</div><!-- end container -->
		</div>
		<!-- end About section -->

		<!-- Parallax section -->
		<div class="section-xl bg-image parallax" data-bg-src="../assets/images/background.jpg">
			<div class="bg-black-05">
				<div class="container">
					<div class="row align-items-center">
						<div class="col-12 col-lg-8 col-xl-7">
							<h1 class="font-weight-light">Want to book us for classes or DJing</h1>
							<p class="font-large"><a href="https://www.facebook.com/devonandjerry">@devonandjerry</a> / <a href="mailto:devonandjerry@gmail.com">devonandjerry@gmail.com</a></p>
						</div>
						<div class="col-12 col-lg-4 col-xl-5 text-lg-right">
							<a class="button button-xl button-radius button-reveal-right-outline-white" href="mailto:devonandjerry@gmail.com"><i class="ti-arrow-right"></i><span>Get In Touch</span></a>
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
						<!--<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>-->
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Oh my god where do I even begin? Devon is, and always was, an awesome person and a great teacher. Every class makes me feel like I'm being taken gently by the hand, constantly soothed with humor and charm as I walk through the valley of the shadow of death, AKA Zouk head movements.
She breaks it down so simply, using concepts from every day life and tips that were surely earned through years and years of experience as a dancer. She makes complex movements attainable for everyone who has the will to learn and practice. 10 out of 5 recommend.
</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Tom Lev</h5>
							<span class="font-small font-weight-normal">Founder of Sotaki Dance School</span>
						</div>
					</div>
					<!-- Testimonial box 2 -->
					<div class="testimonial-grid-box">
						<!--<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>-->
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">Devon Near-Hill is an amazing teacher and dancer! I really enjoyed her EFO course and look forward to continuing to learn from her. She is so genuine, which is something I value VERY highly in my teachers--fun, creative, and always brings joy out of her "mistakes." I love how brightly her personality and genuine spirit shine through even distance/online learning! Not to mention, she has years of experience with zouk and other dance forms, beautiful technique and flow, and such a bright energy. Thank you for the learning, feedback, and laughter you brought me during this time when I wasn't very inspired to dance or train❤️</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Rose Curtis</h5>
							<span class="font-small font-weight-normal">Movement Therapist</span>
						</div>
					</div>
					
					<!-- Testimonial box 3 -->
					<div class="testimonial-grid-box">
						<!--<div class="testimonial-img">
							<img src="../assets/images/img-circle-small.jpg" alt="">
						</div>-->
						<div class="testimonial-content">
							<p class="margin-bottom-10 font-small">In science, the validity of a unified model is judged on its capability to answer not only the question its developers set out to explain, but it's ability to predict the solutions to problems they never intended to solve.  That is exactly my feeling after attending Deconstructing Zouk.  So many frustrating problems in my dance magically disappeared when I applied the concepts we learned.  I'm exceptionally grateful for the wonderful instructors and hosts that made it possible.</p>
							<h5 class="font-weight-normal margin-0 line-height-140">Corrie Brandl</h5>
							<!--<span class="font-small font-weight-normal">Movement Therapist</span>-->
						</div>
					</div>
					
				</div><!-- end testimonial-grid -->
			</div><!-- end row -->
		</div>
		<!-- end Testimonial section -->


		<?php

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
