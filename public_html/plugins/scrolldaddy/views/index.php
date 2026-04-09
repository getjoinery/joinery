<?php
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	// PathHelper is already loaded
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

	require_once(PathHelper::getIncludePath('logic/pricing_logic.php'));

	$page_vars = process_logic(pricing_logic($_GET, $_POST));
	$page_choice = $page_vars['page_choice'];
	$tier_display_data = $page_vars['tier_display_data'];
	
	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	//echo PublicPage::BeginPage('');
	?>
	
<!--==============================
Hero Area
==============================-->
	<!--<div class="th-hero-wrapper hero-1" id="hero">-->
    <div class="th-hero-wrapper hero-1" id="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-xl-6">
                    <div class="hero-style1">
                        <span class="sub-title">Welcome to ScrollDaddy</span>
                        <h1 class="hero-title">Save your sanity online</h1>
                        <p class="hero-text">Block social media, gambling, porn, news, and more before it gets to you.</p>
                        <div class="btn-group">
                            <a href="/scrolldaddy/pricing" class="th-btn">Get Started</a>
							<!--
                            <div class="call-btn">
                                <a href="https://www.youtube.com/watch?v=_sI_Ps7JSEk" class="play-btn popup-video">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polygon points="5,3 19,12 5,21 5,3" fill="currentColor" stroke="none"/></svg>
                                </a>
                                <div class="media-body">
                                    <a href="https://www.youtube.com/watch?v=_sI_Ps7JSEk" class="btn-title popup-video">Watch
                                        Video</a>
                                </div>
                            </div>-->
                        </div><!--
                        <div class="client-box mb-sm-0 mb-3">
                            <div class="client-thumb-group">
                                <div class="thumb"><img src="/plugins/scrolldaddy/assets/img/shape/client-1-1.png" alt="avater"></div>
                                <div class="thumb"><img src="/plugins/scrolldaddy/assets/img/shape/client-1-2.png" alt="avater"></div>
                                <div class="thumb"><img src="/plugins/scrolldaddy/assets/img/shape/client-1-3.png" alt="avater"></div>
                                <div class="thumb icon"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
                            </div>
							
                            <div class="cilent-box">
                                <h4 class="box-title"><span class="counter-number">10</span>k+ Happy Clients</h4>
                                <div class="client-wrapp">
                                    <div class="client-review">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2" fill="currentColor" stroke="none"/></svg><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2" fill="currentColor" stroke="none"/></svg><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2" fill="currentColor" stroke="none"/></svg><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2" fill="currentColor" stroke="none"/></svg>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="currentColor" stroke="none"/><line x1="12" y1="2" x2="12" y2="17.77" stroke="currentColor" stroke-width="2"/></svg>
                                    </div>
                                    <span class="rating">4.8 Rating</span>
                                </div>
                            </div>
                        </div>-->
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="hero-img tilt-active">
                        <img src="/static_files/peopleonphones.png" width="859" alt="Scrolldaddy people on phones">
                    </div>
                </div>
            </div>
        </div>
		<!--
        <div class="ripple-shape">
            <span class="ripple-1"></span>
            <span class="ripple-2"></span>
            <span class="ripple-3"></span>
            <span class="ripple-4"></span>
            <span class="ripple-5"></span>
        </div>
        <div class="th-circle">
            <span class="circle style1"></span>
            <span class="circle style2"></span>
            <span class="circle style3"></span>
        </div>
		-->
    </div>
	
	

	
	
	<!--==============================
About Area  
==============================-->
    <div class="position-relative overflow-hidden space">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="title-area text-center">
                        <span class="sub-title">Our Features</span>
                        <h2 class="sec-title">Your partner in managing your online world</h2>
                        <p class="sec-text">Use the internet when and how you want to and protect yourself from online threats..</p>
                    </div>
                </div>
            </div>
            <div class="row gy-4 justify-content-center">
                <div class="col-md-6 col-xl-3">
                    <div class="feature-card ">
                        <!--<div class="box-icon">
                            <img src="/plugins/scrolldaddy/assets/img/icon/feature_1_1.svg" alt="Icon">
                        </div>-->
                        <h3 class="box-title"><a href="#">Block Social Media</a></h3>
                        <p class="box-text">Block Facebook, Instagram, TikTok, and more.</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card">
                         <!--<div class="box-icon">
                            <img src="/plugins/scrolldaddy/assets/img/icon/feature_1_1.svg" alt="Icon">
                        </div>-->
                        <h3 class="box-title"><a href="#">Protect yourself online</a></h3>
                        <p class="box-text">Block known malware, phishing, and virus related sites.</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card">
                         <!--<div class="box-icon">
                            <img src="/plugins/scrolldaddy/assets/img/icon/feature_1_1.svg" alt="Icon">
                        </div>-->
                        <h3 class="box-title"><a href="#">Filter what you consume</a></h3>
                        <p class="box-text">Integrated ad blocker that works before content even gets to your computer.</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card ">
                         <!--<div class="box-icon">
                            <img src="/plugins/scrolldaddy/assets/img/icon/feature_1_1.svg" alt="Icon">
                        </div>-->
                        <h3 class="box-title"><a href="#">Block full time or scheduled</a></h3>
                        <p class="box-text">Choose to block full time, and on a schedule separately.</p>
                    </div>
                </div>

            </div>
        </div>
       <!-- <div class="shape-mockup z-index-3 movingX d-none d-xl-block" data-top="18%" data-left="18%"><span class="feature-shape style1"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-top="15%" data-right="15%"><span class="feature-shape style2"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-bottom="30%" data-left="5%"><span class="feature-shape style3"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-bottom="20%" data-right="10%"><span class="feature-shape style4"></span></div>-->
    </div><!--==============================
About Area  
==============================-->
    <div class="about-area space-extra2" id="about-sec">
        <div class="container">
            <div class="row gy-5 align-items-center">
                <div class="col-xl-6">
                    <div class="pe-xxl-4 me-xl-3">
                        <div class="title-area mb-35">
                            <h2 class="sec-title">Powerful, Safe, Private </h2>
                        </div>
                        <p class="mt-n2 mb-35">ScrollDaddy works as a DNS server and sits between you and the internet's system that converts domain names into locations.  It is not limited by app permissions on your phone or computer.</p>
                        <div class="about-feature-wrap">
                            <div class="about-feature">
                                <div>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="5em" height="5em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><polyline points="20,6 9,17 4,12"/></svg>
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Powerful</h3>
                                    <p class="box-text">Completely block whole domains seamlessly.</p>
                                </div>
                            </div>
                            <div class="about-feature">
                                <div>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="5em" height="5em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Safe</h3>
                                    <p class="box-text">Transparently and automatically block websites, malware, and spam.</p>
                                </div>
                            </div>
                            <div class="about-feature">
                                <div>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="5em" height="5em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.125em"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Private</h3>
                                    <p class="box-text">ScrollDaddy has no access to your web traffic or files on your computer, and does not log by default.</p>
                                </div>
                            </div>
                  
                        </div>
                    </div>
                </div>
                <div class="col-xl-6 mb-30 mb-xl-0">
                    <div class="img-box1">
                        <div class="img1">
                            <img src="/static_files/switches2_resized.png" width="684" height="548" alt="Screenshot of features">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
	
	<!--==============================
Process Area  
==============================-->
    <section class="space" id="process-sec">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-6">
                    <div class="title-area text-center">
                        <span class="sub-title">Simple Signup </span>
                        <h2 class="sec-title">Be up and running in 5 minutes.</h2>
                    </div>
                </div>
            </div>
            <div class="container py-5">
    <div class="row text-center">
        <div class="col-md-4">
            <div class="p-4 border rounded shadow">
                <h3>Create your account</h3>
                <p>We need only your a name and email...use a pseudonym and an anyonymous email if you like.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-4 border rounded shadow">
                <h3>Install our app</h3>
                <p>We use very simple apps that do nothing at all except change the proper DNS settings on your device.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-4 border rounded shadow">
                <h3>Choose your list of blocked sites</h3>
                <p>Pick which web content to block with simple switches.  Have two separate blocklists...one list that is always on and another that works on a schedule you pick.</p>
            </div>
        </div>
    </div>
</div>
        </div>
    </section>	
	
	
	
	
	
	
<!--==============================
Price Area  
==============================-->
    <section class="space">
        <div class="container">
            <div class="title-area text-center">
                <!--<span class="sub-title">
                    Our Pricing
                </span>-->
                <h2 class="sec-title">Plans</h2>
                <!--<p>Choose a plan that suits your business needs</p>-->
                <div class="pricing-tabs">
                    <div class="switch-area">
                        <label class="toggler toggler--is-active ms-0" id="filt-monthly">Monthly</label>

						<div class="toggle">
							<?php

							if(!$page_choice || $page_choice == 'month'){
								echo '<input type="checkbox" id="switcher" class="check">';
							}
							else{
								
								echo '<input type="checkbox" id="switcher" class="check" checked>';
							}
                            ?>
                            <b class="b switch"></b>
							
                        </div>
						
                        <label class="toggler" id="filt-yearly">Yearly</label>
                    </div>
					
					
					   <script>
						// Get the toggle input
						const toggle = document.getElementById('switcher');

						// Add an event listener for change
						toggle.addEventListener('change', function () {
							
						  if (this.checked) {
							  
							// Redirect to the new URL when toggled on
							window.location.href = '/scrolldaddy/pricing?page=year';
						  } else {

							// Redirect to a different URL or stay on the same page when toggled off
							window.location.href = '/scrolldaddy/pricing';
						  }
						});
					  </script>
                    <div class="discount-tag">
                        <svg width="54" height="41" viewBox="0 0 54 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M15.5389 7.99353C15.4629 8.44111 15.3952 8.82627 15.3583 9.02966C15.1309 10.2666 14.942 13.4078 14.062 15.5433C13.3911 17.1727 12.3173 18.2233 10.6818 17.8427C9.19525 17.4967 8.26854 16.0251 7.82099 13.9916C6.85783 9.61512 8.00529 2.6265 8.90147 0.605294C8.99943 0.384693 9.25826 0.284942 9.48075 0.382666C9.70224 0.479891 9.80333 0.737018 9.70537 0.957619C8.84585 2.89745 7.75459 9.6061 8.67913 13.8076C9.04074 15.4498 9.68015 16.7144 10.881 16.9937C12.0661 17.2698 12.7622 16.3933 13.2485 15.2121C14.1054 13.134 14.273 10.0757 14.4938 8.87118C14.6325 8.11613 15.0798 5.22149 15.1784 4.9827C15.3016 4.68358 15.5573 4.69204 15.641 4.70108C15.7059 4.708 16.0273 4.76322 16.0423 5.15938C16.2599 10.808 20.5327 19.3354 26.8096 25.0475C33.0314 30.7095 41.2522 33.603 49.4783 28.0026C49.6784 27.8669 49.9521 27.9178 50.0898 28.1157C50.2269 28.3146 50.1762 28.5863 49.9762 28.7219C41.3569 34.5897 32.7351 31.6217 26.217 25.6902C20.7234 20.6913 16.7462 13.5852 15.5389 7.99353Z" fill="var(--theme-color)" />
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M49.2606 28.5952C48.2281 28.5096 47.1974 28.4571 46.1708 28.2919C43.4358 27.8522 40.6863 26.8206 38.4665 25.1551C38.2726 25.0089 38.2345 24.7355 38.3799 24.5438C38.5267 24.3517 38.8021 24.3145 38.9955 24.4592C41.1013 26.0411 43.7143 27.0136 46.3092 27.4305C47.4844 27.6191 48.6664 27.6581 49.8489 27.7714C49.9078 27.7778 50.4232 27.8114 50.53 27.8482C50.7793 27.9324 50.8288 28.1252 50.8402 28.2172C50.8506 28.2941 50.8446 28.3885 50.7944 28.4939C50.7528 28.5801 50.6349 28.7253 50.4357 28.886C49.7992 29.4029 48.1397 30.3966 47.8848 30.5884C44.9622 32.7862 42.6161 35.3187 40.0788 37.9235C39.9097 38.0958 39.6311 38.1004 39.4566 37.9332C39.2821 37.766 39.2778 37.49 39.4459 37.3172C42.0151 34.6792 44.3946 32.1179 47.353 29.8939C47.5278 29.7615 48.5366 29.0813 49.2606 28.5952Z" fill="var(--theme-color)" />
                        </svg>
                        Save 17%
                    </div>
                </div>
            </div>
            <div id="monthly" class="wrapper-full">
                <div class="row justify-content-center">
					<?php foreach ($tier_display_data as $item){
						$tier = $item['tier'];
						$product = $item['product'];
						$version = $item['version'];
					?>
                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title"><?php echo $product->get('pro_name'); ?></h3>
                                <!--<p class="subtitle"><?php echo $tier->get('sbt_display_name'); ?></p>-->
                            </div>
                            <!--<p class="box-text">Perfect plan to get started</p>-->
							<?php echo $product->get('pro_short_description'); ?>
                            <h4 class="box-price">
								<?php
								echo $product->get_readable_price($version->key);
								?>
							<span class="duration">/<?php echo $version->get('prv_price_type'); ?></span></h4>

                            <div class="box-content">
								<!--<p class="box-text2">A free plan grants you access to some cool features of Spend.</p>-->
                                <div class="available-list">
									<?php echo $product->get('pro_description'); ?>
                                    <!--<ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>-->
                                </div>
                                <a href="<?php echo $product->get_url(). '?product_version_id='.$version->key; ?>" class="th-btn btn-fw style-radius">Get Started</a>
                            </div>
                        </div>
                    </div>
					<?php } ?>
					
                </div>
            </div>

        </div>
    </section>
	
<?php

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
