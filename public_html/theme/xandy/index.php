<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');
	require_once (LibraryFunctions::get_logic_file_path('events_logic.php'));


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

<!--
		<section id="nexa-banner-style-7" class="nexa-banner-travel">
	      <div id="carousel-testmonial-travel-banner" class="carousel slide" data-ride="carousel">
	         
	         <div class="carousel-inner" role="listbox">
	            <div class="item active">
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="/static_files/Home1.png" alt="The Liberato Method Picture" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1></h1>
	                        <h2></h2>
	                     </div>
	                  </div>
	               </div>
	            </div>
	          
	         </div>
	      </div>
	   </section>
-->
		
		<!-- Start Banner / Slider section -->
		
		<section id="nexa-banner-style-7" class="nexa-banner-travel">
	      <div id="carousel-testmonial-travel-banner" class="carousel slide" data-ride="carousel">
	       
	         <ol class="carousel-indicators">
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="0" class="active"></li>
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="1"></li>
	            <li data-target="#carousel-testmonial-travel-banner" data-slide-to="2"></li>
	         </ol>
	         
	         <div class="carousel-inner" role="listbox">
	            <div class="item active">
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="/static_files/Home1.png" alt="The Liberato Method Picture 1" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1></h1>
	                        <h2></h2>
	                     </div>
	                  </div>
	               </div>
	            </div>
	            <div class="item">
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="/static_files/Home2.png" alt="The Liberato Method Picture 2" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1></h1>
	                        <h2></h2>
	                     </div>
	                  </div>
	               </div>
	            </div>
	            <div class="item">
	               <div class="row">
	                  <div class="banner-box">
	                     <div class="banner-photo">
	                        <img src="/static_files/Home3.png" alt="The Liberato Method Picture 3" class="img-responsive">
	                     </div>
	                     <div class="banner-info">
	                        <h1></h1>
	                        <h2></h2>
	                     </div>
	                  </div>
	               </div>
	            </div>
	         </div>
	      </div>
	      <div class="scroll-btn-banner">
	         <a href="/events"><i class="fa fa-angle-down" aria-hidden="true"></i></a>
	       </div>
	   </section>
	   
	   <!-- end-banner-section -->
	   <!-- Start about section -->
		<section class="about-section">
			<div class="about-section-inner">
				<div class="vertical-space-80"></div>
				<div class="container">
					<h2 class="main-title text-center">Welcome to the Liberato Portal</h2>
					<h6 class="sub-title after-title text-center">Dance as a source of wellbeing through online courses, workshops, private classes, retreats, books and articles.</h6>

					
					<div class="vertical-space-80"></div>
					<div class="row yoga-about-benifits v2">
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<a href="/events"><i class="fa fa-heart" aria-hidden="true"></i>
							<h3 class="font-blue">Liberato Method</h3>
							<p class="icon-text">Experience Xandy Liberato's dance training method.</p></a>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<a href="/events"><i class="fa fa-circle-o" aria-hidden="true"></i>
							<h3 class="font-blue">Retreats </h3>
							<p class="icon-text">Concentrated study.</p></a>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<a href="/events"><i class="fa fa-leaf" aria-hidden="true"></i>
						
							<h3 class="font-blue">Zouk Training</h3>
							<p class="icon-text">Classes tailored just for Zouk.</p></a>
						</div>
						<div class="col-xs-12 col-sm-3 col-md-3 text-center benifit">
							<a href="/events"><i class="fa fa-user-o" aria-hidden="true"></i>
							<h3 class="font-blue">Mentorship</h3>
							<p class="icon-text">Improve all aspects of yourself, not just dance.</p></a>
						</div>
					</div>
					
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!-- End about section -->


<!-- Start features section -->
		<section class="background-light-blue">
			<div class="vertical-space-80"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Benefits</span> of The Liberato Method</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s"></h6>
				<div class="vertical-space-80"></div>
				<div class="row">
					<div class="col-xs-12 col-sm-12 col-md-4 high-zindex">
						<div class="media wow" data-wow-delay="0.9s">
							<div class="media-body">
								<h3 class="media-heading font-blue text-left"><span class="normald">Increased body awareness</span></h3>
								<p class="text-right"></p>
							</div>							
							<div class="media-right">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
						</div>

						<div class="media wow" data-wow-delay="0.8s">
							<div class="media-body">
								<h3 class="media-heading font-blue text-left"><span class="normald">Increased self-confidence and confidence towards others</span></h3>
								<p class="text-right"></p>
							</div>
							<div class="media-right">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
						</div>

						<div class="media wow " data-wow-delay="0.8s">
							<div class="media-body">
								<h3 class="media-heading font-blue text-left"><span class="normald">More empathetic communication</span></h3>
								<p class="text-right"></p>
							</div>
							<div class="media-right">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
						</div>
						<div class="media wow " data-wow-delay="0.8s">
							<div class="media-body">
								<h3 class="media-heading font-blue text-left"><span class="normald">More conscious relationships</span></h3>
								<p class="text-right"></p>
							</div>
							<div class="media-right">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
						</div>
						<div class="media wow " data-wow-delay="0.8s">
							<div class="media-body">
								<h3 class="media-heading font-blue text-left"><span class="normald">Listening development</span></h3>
								<p class="text-right"></p>
							</div>
							<div class="media-right">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
						</div>

					</div>
					<div class="col-xs-12 col-sm-12 col-md-4 text-center">
						<img src="/static_files/effects-of-liberato-700.png" alt="The Liberato Method" class="full-width benifites-imgs">
					</div>
					<div class="col-xs-12 col-sm-12 col-md-4">

						
						<div class="media wow " data-wow-delay="0.7s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Sensitivity development</span></h3>
								<p></p>
							</div>
						</div>
						
						<div class="media wow " data-wow-delay="0.8s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Increased enjoyment ability</span></h3>
								<p></p>
							</div>
						</div>
						
						<div class="media wow " data-wow-delay="0.9s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Experiences of freedom</span></h3>
								<p></p>
							</div>
						</div>
						<div class="media wow " data-wow-delay="0.9s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Changes in the way of dancing and living</span></h3>
								<p></p>
							</div>
						</div>
						<div class="media wow " data-wow-delay="0.9s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Increased social abilities</span></h3>
								<p></p>
							</div>
						</div>
						<div class="media wow " data-wow-delay="0.9s">
							<div class="media-left">
								<!--<img class="media-object benifiets-icons" src="images/c1.png" alt="Chakra 1">-->
							</div>
							<div class="media-body">
								<h3 class="media-heading font-blue"><span class="normald">Change of mindset</span></h3>
								<p></p>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="vertical-space-80"></div>
		</section>
		<!-- End features section -->

		
		<!-- Start video parallex section -->
		<section class="video-section">
			<div class="background-transperant-black-medium">
				<div class="vertical-space-80"></div>
				<div class="text-center">
					<div class="container">
						<div class="row">
							<div class="col-xs-12 col-sm-8 col-md-8 col-sm-offset-2">
								<h2 class="font-white">Our Retreat</h2>
								<div class="vertical-space-20"></div>
								<!--<p class="font-white">
									Lorem ipsum dolor sit amet, consectetur adipiscing elit. In sit amet purus id nunc porta fringilla. Maecenas volutpat fermentum ante. Sed nibh metus, mollis a dolor ut, feugiat fringilla nunc. Donec consequat pretium nunc, vel feugiat purus cursus a.
								</p>-->
								<div class="vertical-space-20"></div>
								<iframe src="https://www.youtube.com/embed/BBYizZLqYkM" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
								<!--<a href="#" data-toggle="modal" data-target="#videoModal">
									<i class="fa fa-play font-white" aria-hidden="true"></i>-->
								</a>
							</div>
						</div>
					</div>
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!-- End video parallex section -->		
		
		<!-- Start classes section -->
		<section class="background-light-grey">
			<div class="vertical-space-80"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Our</span> Next Events</h2>
				<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s"></h6>
				<div class="vertical-space-80"></div>
				<div class="row">
					<?php
					$numdisplayed = 0;
					foreach ($events as $event){
						$now = LibraryFunctions::get_current_time_obj('UTC');
						$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
						$numdisplayed++;
						if($numdisplayed == 4){
							break;
						}
						?>
					<div class="col-xs-12 col-sm-4 col-md-4">
						<div class="class-container">
							<div class="class-image-container">	
								<div class="image-overlay"></div>
										<?php
										if($pic = $event->get_picture_link('small')){
											echo '<img class="full-width" src="'.$pic.'" alt="">';
										}
										?>

							</div>
							<div class="class-detail background-white">
								<a href="<?php echo $event->get_url(); ?>"><h2 class="font-blue class-title"><?php echo $event->get('evt_name'); ?></h2></a>
											<?php
											if($event->get('evt_start_time') && $event_time > $now){				
												echo '<span>'.$event->get_event_start_time($tz, 'M'). ' ' . $event->get_event_start_time($tz, 'd').'</span>'; 				
											}
											else if($next_session = $event->get_next_session()){
												echo '<span>'.$next_session->get_start_time($tz, 'M'). ' ' . $next_session->get_start_time($tz, 'd').'</span>'; 
											
											}					
											?>								
								<!--<span>19, Mar 2017</span>
								<span class="pull-right">10.00 AM TO 11.00 AM</span>-->
							</div>
						</div>
					</div>
					<?php
					}
					?>
				</div>
				<div class="vertical-space-40"></div>
				<div class="text-center">
					<a href="/events" class="view-more">View More <i class="fa fa-fighter-jet" aria-hidden="true"></i>
					</a>
				</div>
			</div>
			<div class="vertical-space-60"></div>
		</section>
		<!-- End classes section -->
		
		<!-- start Register section with parallex effects -->
		<!--
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
		-->
		<!-- End register section -->

		<!-- Start photo gallery section -->
		<?php
		$gallery_pictures = new MultiFile(
			array('deleted'=>false, 'picture'=>true, 'in_gallery'=>true),
			array('file_id' => 'DESC'),		//SORT BY => DIRECTION
			8,  //NUM PER PAGE
			NULL);  //OFFSET
		$numpics = $gallery_pictures->count_all();
		$gallery_pictures->load();	
		if($numpics){
		?>
		<section class="background-light-grey">
			<div class="vertical-space-80"></div>
			<div class="container">
				<h2 class="main-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0s"><span class="normald">Our</span> Photos</h2>
				<!--<h6 class="sub-title after-title text-center wow fadeIn" data-wow-duration="0.3s" data-wow-delay="0.3s">Visit our gallery.</h6>-->
				<!--<div class="vertical-space-60"></div>
				<div class="button-group filter-button-group">
				  <button data-filter="*" class="active gallery-btn">show all</button>
				  <button data-filter=".Meditation" class="gallery-btn">Meditation</button>
				  <button data-filter=".pranayam" class="gallery-btn">pranayam</button>
				  <button data-filter=".vinyasa" class="gallery-btn">vinyasa</button>
				</div>-->
				<div class="vertical-space-40"></div>
				<div class="grid">
					<?php
					foreach($gallery_pictures as $gallery_picture){
					
						echo '<div class="grid-item Meditation vinyasa">
							<a data-fancybox="gallery" href="'.$gallery_picture->get_url().'">
								<img src="'.$gallery_picture->get_url('small').'" class="full-width">
							</a>
						</div>';
					}
					?>
					
				</div>
			</div>
			<div class="vertical-space-80"></div>
		</section>
		<?php
		}
		?>
		<!-- End photo gallery section -->
		<!-- Start review section -->
		
		<section class="review-section">
			<div class="background-transperant-black-medium">
				<div class="vertical-space-60"></div>
				<div class="container">
					<h2 class="main-title text-center wow fadeIn font-white" data-wow-duration="0.3s" data-wow-delay="0s">Testimonials</h2>
					<h6 class="sub-title after-title text-center wow fadeIn font-white" data-wow-duration="0.3s" data-wow-delay="0.3s">From our current and former students</h6>
					<div class="vertical-space-50"></div>
					<div class="row">
						<div class="col-xs-12 col-sm-12 col-md-12">
							<div class="testimonial-carousel owl-carousel owl-theme">
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											The retreat completely changed my life and my vision of the world. Now I feel so much silence and love. And I feel free in dance. My creativity and flow woke up. Thank you all! 
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Daniel</h3>
											<h5 class="bold font-white">Retreat Participant</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Me siento muy conmovida por las personas increíbles que forman parte del personal, todo el mundo. Me sentí SEGURA y eso significa mucho para mí.
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Angelique</h3>
											<h5 class="bold font-white">Student</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											The retreat was a transformation point in my life. I feel like I am awake, refreshed, inspired, and filled with emotion. Thank you for the beautiful experience. 
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Lubna</h3>
											<h5 class="bold font-white">Retreat Participant</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Sinto-me como se as portas que não sabia que existiam tivessem se aberto. Ainda não sei bem o que isso significa, mas sinto que a minha vida mudou para melhor. Este foi um evento que mudou a minha vida, e não tenho palavras para dizer o quanto sou grato por ter feito parte disso. Obrigado!
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Christoffer</h3>
											<h5 class="bold font-white">Student</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											I used to go to many congresses and workshops to learn the dance techniques without thinking of myself and the others, without thinking about my feelings and taking time to discover what it can bring into my dance and into my life. I don’t know about the last retreat, but this one, which was the first for me, opened a door to a whole world of possibilities where my creativity can be expressed. Thank you. 
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Farid</h3>
											<h5 class="bold font-white">Retreat Participant</h5>
										</div>
									</div>
								</div>
								<div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											Solo tengo palabras de agradecimiento y apoyo a este método, que para mí después de estos días maravillosos pienso tenerlo como estilo de vida, conectar como nunca antes con los demás y conmigo mismo. No puedo imaginar mi vida sin la danza y ahora mucho menos que he vivido todo estos. Estos días espero que sean los primeros de muchos. Se quedarán en mi mente y en mi corazón hasta que me vaya de este mundo.
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Javi</h3>
											<h5 class="bold font-white">Student</h5>
										</div>
									</div>
								</div><div>
									<div class="vertical-space-40"></div>
									<div class="students-reviews v2 background-white">
										<p class="text-center">
											The retreat was a transformation point in my life. I feel like I am awake, refreshed, inspired, and filled with emotion. Thank you for the beautiful experience. 
										</p>
									</div>
									<div class="media">
										<!--<div class="media-left">
											<img src="images/new/home-var3/testimonial.png" alt="trainer" class="testimonial-imgs">		
										</div>-->
										<div class="media-body">
											<h3 class="font-white">Lubna</h3>
											<h5 class="bold font-white">Retreat Participant</h5>
										</div>
									</div>
								</div>
								
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
		<!--
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
									</ul> 
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
									</ul> 
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
									</ul> 
								</div>
							</div>
						</div>						
					</div>
				</div>
				<div class="vertical-space-80"></div>
			</div>
		</section>
		<!--
		<!-- End Team section -->
		<!-- Start blog section -->
		<!--
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
		-->
		<!-- End blog section -->
	

<?php
	//echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
