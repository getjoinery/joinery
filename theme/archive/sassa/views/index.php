<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	$page = new PublicPageSassa();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	//echo PublicPageSassa::BeginPage('');
	?>
	
<!--==============================
Hero Area
==============================-->
    <div class="th-hero-wrapper hero-1" id="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-xl-6">
                    <div class="hero-style1">
                        <span class="sub-title">Welcome to Sassa</span>
                        <h1 class="hero-title">Effortless Solutions Endless Possibilities</h1>
                        <p class="hero-text">Provide a brief overview of your SaaS product, explaining what it does and how
                            it can
                            benefit the user.</p>
                        <div class="btn-group">
                            <a href="about.html" class="th-btn">Get Started For Free</a>
                            <div class="call-btn">
                                <a href="https://www.youtube.com/watch?v=_sI_Ps7JSEk" class="play-btn popup-video">
                                    <i class="fas fa-play"></i>
                                </a>
                                <div class="media-body">
                                    <a href="https://www.youtube.com/watch?v=_sI_Ps7JSEk" class="btn-title popup-video">Watch
                                        Video</a>
                                </div>
                            </div>
                        </div>
                        <div class="client-box mb-sm-0 mb-3">
                            <div class="client-thumb-group">
                                <div class="thumb"><img src="/theme/sassa/assets/img/shape/client-1-1.png" alt="avater"></div>
                                <div class="thumb"><img src="/theme/sassa/assets/img/shape/client-1-2.png" alt="avater"></div>
                                <div class="thumb"><img src="/theme/sassa/assets/img/shape/client-1-3.png" alt="avater"></div>
                                <div class="thumb icon"><i class="fa-regular fa-plus"></i></div>
                            </div>
                            <div class="cilent-box">
                                <h4 class="box-title"><span class="counter-number">10</span>k+ Happy Clients</h4>
                                <div class="client-wrapp">
                                    <div class="client-review">
                                        <i class="fa-sharp fa-solid fa-star"></i><i class="fa-sharp fa-solid fa-star"></i><i class="fa-sharp fa-solid fa-star"></i><i class="fa-sharp fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star-half-stroke"></i>
                                    </div>
                                    <span class="rating">4.8 Rating</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="hero-img tilt-active">
                        <img src="/theme/sassa/assets/img/hero/hero_1_1.png" alt="Hero Image">
                    </div>
                </div>
            </div>
        </div>
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
    </div>
	
	
    <!--======== / Hero Section ========--><!--==============================
Brand Area  
==============================-->
<!--
    <div class="brand-sec4 overflow-hidden space">
        <div class="container">
            <div class="title-area mb-60 text-center">
                <h3 class=" mt-n1">Trusted by millions of customers.</h3>
            </div>
            <div class="slider-area text-center">
                <div class="swiper th-slider brand-slider1" id="brandSlider1" data-slider-options='{"breakpoints":{"0":{"slidesPerView":2},"576":{"slidesPerView":"2"},"768":{"slidesPerView":"3"},"992":{"slidesPerView":"4"},"1200":{"slidesPerView":"5"},"1400":{"slidesPerView":"6"}}}'>
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_1.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_2.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_3.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_4.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_5.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_6.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_1.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_2.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_3.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_4.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_5.svg" alt="Brand Logo">
                            </a>
                        </div>
                        <div class="swiper-slide">
                            <a href="about.html" class="brand-box">
                                <img src="/theme/sassa/assets/img/brand/brand_1_6.svg" alt="Brand Logo">
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
	-->
	
	
	<!--==============================
About Area  
==============================-->
    <div class="position-relative overflow-hidden space-bottom">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="title-area text-center">
                        <span class="sub-title">Our Features</span>
                        <h2 class="sec-title">We Provide the Best Quality</h2>
                        <p class="sec-text">Clearly communicate the unique value proposition of your SaaS product and what sets it apart from competitors.</p>
                    </div>
                </div>
            </div>
            <div class="row gy-4 justify-content-center">
                <div class="col-md-6 col-xl-3">
                    <div class="feature-card th-ani">
                        <div class="box-icon">
                            <img src="/theme/sassa/assets/img/icon/feature_1_1.svg" alt="Icon">
                        </div>
                        <h3 class="box-title"><a href="service-details.html">Software Features</a></h3>
                        <p class="box-text">Software features refer to the functionalities and capabilities that a particular</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card th-ani">
                        <div class="box-icon">
                            <img src="/theme/sassa/assets/img/icon/feature_1_2.svg" alt="Icon">
                        </div>
                        <h3 class="box-title"><a href="service-details.html">Customization Option</a></h3>
                        <p class="box-text">Highlight the key features and functionalities of your SaaS product. This could.</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card th-ani">
                        <div class="box-icon">
                            <img src="/theme/sassa/assets/img/icon/feature_1_3.svg" alt="Icon">
                        </div>
                        <h3 class="box-title"><a href="service-details.html">Customer Support</a></h3>
                        <p class="box-text">Highlight the key features and functionalities of your SaaS product. This could.</p>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="feature-card th-ani">
                        <div class="box-icon">
                            <img src="/theme/sassa/assets/img/icon/feature_1_4.svg" alt="Icon">
                        </div>
                        <h3 class="box-title"><a href="service-details.html">Subscription Plans</a></h3>
                        <p class="box-text">Highlight the key features and functionalities of your SaaS product. This could.</p>
                    </div>
                </div>

            </div>
        </div>
        <div class="shape-mockup z-index-3 movingX d-none d-xl-block" data-top="18%" data-left="18%"><span class="feature-shape style1"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-top="15%" data-right="15%"><span class="feature-shape style2"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-bottom="30%" data-left="5%"><span class="feature-shape style3"></span></div>
        <div class="shape-mockup movingX d-none d-xl-block" data-bottom="20%" data-right="10%"><span class="feature-shape style4"></span></div>
    </div><!--==============================
About Area  
==============================-->
    <div class="about-area space-extra2" id="about-sec">
        <div class="container">
            <div class="row gy-5 align-items-center">
                <div class="col-xl-6">
                    <div class="pe-xxl-4 me-xl-3">
                        <div class="title-area mb-35">
                            <h2 class="sec-title">Powerful and easy to use saas builder platform </h2>
                        </div>
                        <p class="mt-n2 mb-35">Provide information about the customer support options available to users, such as email support, phone support, live chat, help documentation, etc. Assure users that help is available if they encounter any issues or have questions.</p>
                        <div class="about-feature-wrap">
                            <div class="about-feature">
                                <div class="box-icon">
                                    <img src="/theme/sassa/assets/img/icon/about_1_1.svg" alt="Icon">
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Cost Effective</h3>
                                    <p class="box-text">Contrary to popular belief, Lore Ipsum is not simply random text.</p>
                                </div>
                            </div>
                            <div class="about-feature">
                                <div class="box-icon">
                                    <img src="/theme/sassa/assets/img/icon/about_1_2.svg" alt="Icon">
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Global Awards</h3>
                                    <p class="box-text">Clearly communicate the unique value proposition of your SaaS product.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6 mb-30 mb-xl-0">
                    <div class="img-box1">
                        <div class="img1">
                            <img src="/theme/sassa/assets/img/normal/about_1_1.png" alt="About">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!--==============================
Process Area  
==============================-->
    <section class="space" id="process-sec">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-6">
                    <div class="title-area text-center">
                        <span class="sub-title">How It Work </span>
                        <h2 class="sec-title">Work smarter with easy access for user..</h2>
                    </div>
                </div>
            </div>
            <div class="process-tabs-wrapper">
                <div class="nav nav-tabs process-tabs" id="nav-tab" role="tablist">
                    <button class="nav-link th-btn active" id="nav-step1-tab" data-bs-toggle="tab" data-bs-target="#nav-step1" type="button">01.Create account</button>
                    <button class="nav-link th-btn" id="nav-step2-tab" data-bs-toggle="tab" data-bs-target="#nav-step2" type="button">02. Install tracking code</button>
                    <button class="nav-link th-btn" id="nav-step3-tab" data-bs-toggle="tab" data-bs-target="#nav-step3" type="button">Track analytics</button>
                </div>
                <div class="tab-content" id="nav-tabContent">
                    <div class="tab-pane fade show active" id="nav-step1" role="tabpanel">
                        <div class="process-wrapp">
                            <div class="process-content">
                                <div class="process-title-area">
                                    <div class="process-icon">
                                        <img src="/theme/sassa/assets/img/icon/user.svg" alt="">
                                    </div>
                                    <h3 class="">Create your account & start your work</h3>
                                </div>
                                <p class="process-text">Ensure that your landing page is optimized for mobile devices and
                                    tablets, as many users will be accessing it from different devices. A responsive design
                                    ensures that the user experience remains consistent across all devices and screen sizes.
                                </p>
                                <a href="contact.html" class="th-btn style2">Get Started Now</a>
                            </div>
                            <div class="process-image">
                                <img src="/theme/sassa/assets/img/normal/process-img.png" alt="">
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="nav-step2" role="tabpanel">
                        <div class="process-wrapp">
                            <div class="process-content">
                                <div class="process-title-area">
                                    <div class="process-icon">
                                        <img src="/theme/sassa/assets/img/icon/code.svg" alt="">
                                    </div>
                                    <h3 class="">Install your Tracking Code & start your work</h3>
                                </div>
                                <p class="process-text">Ensure that your landing page is optimized for mobile devices and
                                    tablets, as many users will be accessing it from different devices. A responsive design
                                    ensures that the user experience remains consistent across all devices and screen sizes.
                                </p>
                                <a href="contact.html" class="th-btn style2">Get Started Now</a>
                            </div>
                            <div class="process-image">
                                <img src="/theme/sassa/assets/img/normal/process-img-2.png" alt="">
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="nav-step3" role="tabpanel">
                        <div class="process-wrapp">
                            <div class="process-content">
                                <div class="process-title-area">
                                    <div class="process-icon">
                                        <img src="/theme/sassa/assets/img/icon/search.svg" alt="">
                                    </div>
                                    <h3 class="">Track Analytics your account & Safe Account</h3>
                                </div>
                                <p class="process-text">Ensure that your landing page is optimized for mobile devices and tablets, as many users will be accessing it from different devices. A responsive design ensures that the user experience remains consistent across all devices and screen sizes. </p>
                                <a href="contact.html" class="th-btn style2">Get Started Now</a>
                            </div>
                            <div class="process-image">
                                <img src="/theme/sassa/assets/img/normal/process-img-3.png" alt="">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section><!--==============================
Customiization Area  
==============================-->
    <div class="customiization-area space">
        <div class="container">
            <div class="row gy-5 flex-row-reverse align-items-center">
                <div class="col-xl-5">
                    <div class="">
                        <div class="title-area mb-35">
                            <span class="sub-title">Customizations & Analysis</span>
                            <h2 class="sec-title">Advanced Features & Functionality of Sassa</h2>
                        </div>
                        <p class="mt-n2 mb-25">Highlight the main features and functionalities of your SaaS product. This could include things like automation tools, analytics dashboards, collaboration features, integrations.</p>
                        <div class="about-feature-wrap">
                            <div class="about-feature style2">
                                <div class="box-icon">
                                    <img src="/theme/sassa/assets/img/icon/about_2_1.svg" alt="Icon">
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Advanced Analytics</h3>
                                    <p class="box-text">Ensure that your landing page is optimized for mobile devices and tablets. </p>
                                </div>
                            </div>
                            <div class="about-feature style2">
                                <div class="box-icon">
                                    <img src="/theme/sassa/assets/img/icon/about_2_2.svg" alt="Icon">
                                </div>
                                <div class="media-body">
                                    <h3 class="box-title">Powerful Automation</h3>
                                    <p class="box-text">Visuals help to make the landing page more engaging and provide users.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-7 mb-30 mb-xl-0">
                    <div class="img-box2">
                        <div class="img1">
                            <img src="/theme/sassa/assets/img/normal/customization_2_1.png" alt="About">
                        </div>
                        <div class="img2">
                            <img src="/theme/sassa/assets/img/normal/customization_2_2.png" alt="About">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
	
	
	
	
	<!--==============================
Counter Area  
==============================-->
<!--
    <div class="space">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-7">
                    <div class="title-area text-center">
                        <span class="sub-title">Our Advertisement</span>
                        <h2 class="sec-title">Revolutionize Your Workflow with SaaS Excellence</h2>
                    </div>
                </div>
            </div>
            <div class="counter-area">
                <div class="row justify-content-md-between justify-content-center">
                    <div class="col-xl-3 col-md-6">
                        <div class="counter-card">
                            <h3 class="box-number"><span class="counter-number">16</span>k<span class="plus">+</span></h3>
                            <div class="media-body">
                                <p class=" counter-text mb-n2">Completed Our<span>Projects</span></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="counter-card">
                            <h3 class="box-number"><span class="counter-number">150</span><span class="plus">+</span></h3>
                            <div class="media-body">
                                <p class=" counter-text mb-n2">Our Expert Support<span>Team Members</span> </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="counter-card">
                            <h3 class="box-number"><span class="counter-number">1</span>k<span class="plus">+</span></h3>
                            <div class="media-body">
                                <p class=" counter-text mb-n2">Our Worldwide <span>Clients</span></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="counter-card">
                            <h3 class="box-number"><span class="counter-number">16</span><span class="plus">+</span></h3>
                            <div class="media-body">
                                <p class=" counter-text mb-n2">We are Winning <span>Awards</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
	-->
	
	<!--==============================
Download Area 1 
==============================-->
    <section class=" bg-theme" data-bg-src="/theme/sassa/assets/img/bg/download-shape-1.png">
        <div class="container">
            <div class="row align-items-center flex-row-reverse">
                <div class="col-xl-7">
                    <div class="img-box3">
                        <div class="img1 tilt-active">
                            <img src="/theme/sassa/assets/img/normal/download-1-1.png" alt="img">
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="pb-5 pb-xl-0 mb-5 mb-xl-0">
                        <div class="title-area mb-30 text-center text-xl-start">
                            <span class="sub-title download-title">Project Management App</span>
                            <h2 class="sec-title text-white">Download our app and start your free trail to get started today!
                            </h2>
                        </div>
                        <div class="btn-wrap download-btn justify-content-center justify-content-xl-start">
                            <a href="contact.html" class="th-btn style3 style-radius"><img src="/theme/sassa/assets/img/icon/apple-logo.svg" alt="">Playstore</a>
                            <a href="contact.html" class="th-btn style4 style-radius"><i class="far fa-cloud-arrow-down me-2"></i>Download app</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section><!--==============================
Price Area  
==============================-->
    <section class="space">
        <div class="container">
            <div class="title-area text-center">
                <span class="sub-title">
                    Our Pricing
                </span>
                <h2 class="sec-title">Choose The Plans That Suits You!</h2>
                <p>Choose a plan that suits your business needs</p>
                <div class="pricing-tabs">
                    <div class="switch-area">
                        <label class="toggler toggler--is-active ms-0" id="filt-monthly">Monthly</label>
                        <div class="toggle">
                            <input type="checkbox" id="switcher" class="check">
                            <b class="b switch"></b>
                        </div>
                        <label class="toggler" id="filt-yearly">Yearly</label>
                    </div>
                    <div class="discount-tag">
                        <svg width="54" height="41" viewBox="0 0 54 41" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M15.5389 7.99353C15.4629 8.44111 15.3952 8.82627 15.3583 9.02966C15.1309 10.2666 14.942 13.4078 14.062 15.5433C13.3911 17.1727 12.3173 18.2233 10.6818 17.8427C9.19525 17.4967 8.26854 16.0251 7.82099 13.9916C6.85783 9.61512 8.00529 2.6265 8.90147 0.605294C8.99943 0.384693 9.25826 0.284942 9.48075 0.382666C9.70224 0.479891 9.80333 0.737018 9.70537 0.957619C8.84585 2.89745 7.75459 9.6061 8.67913 13.8076C9.04074 15.4498 9.68015 16.7144 10.881 16.9937C12.0661 17.2698 12.7622 16.3933 13.2485 15.2121C14.1054 13.134 14.273 10.0757 14.4938 8.87118C14.6325 8.11613 15.0798 5.22149 15.1784 4.9827C15.3016 4.68358 15.5573 4.69204 15.641 4.70108C15.7059 4.708 16.0273 4.76322 16.0423 5.15938C16.2599 10.808 20.5327 19.3354 26.8096 25.0475C33.0314 30.7095 41.2522 33.603 49.4783 28.0026C49.6784 27.8669 49.9521 27.9178 50.0898 28.1157C50.2269 28.3146 50.1762 28.5863 49.9762 28.7219C41.3569 34.5897 32.7351 31.6217 26.217 25.6902C20.7234 20.6913 16.7462 13.5852 15.5389 7.99353Z" fill="var(--theme-color)" />
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M49.2606 28.5952C48.2281 28.5096 47.1974 28.4571 46.1708 28.2919C43.4358 27.8522 40.6863 26.8206 38.4665 25.1551C38.2726 25.0089 38.2345 24.7355 38.3799 24.5438C38.5267 24.3517 38.8021 24.3145 38.9955 24.4592C41.1013 26.0411 43.7143 27.0136 46.3092 27.4305C47.4844 27.6191 48.6664 27.6581 49.8489 27.7714C49.9078 27.7778 50.4232 27.8114 50.53 27.8482C50.7793 27.9324 50.8288 28.1252 50.8402 28.2172C50.8506 28.2941 50.8446 28.3885 50.7944 28.4939C50.7528 28.5801 50.6349 28.7253 50.4357 28.886C49.7992 29.4029 48.1397 30.3966 47.8848 30.5884C44.9622 32.7862 42.6161 35.3187 40.0788 37.9235C39.9097 38.0958 39.6311 38.1004 39.4566 37.9332C39.2821 37.766 39.2778 37.49 39.4459 37.3172C42.0151 34.6792 44.3946 32.1179 47.353 29.8939C47.5278 29.7615 48.5366 29.0813 49.2606 28.5952Z" fill="var(--theme-color)" />
                        </svg>
                        Save 35%
                    </div>
                </div>
            </div>
            <div id="monthly" class="wrapper-full">
                <div class="row justify-content-center">

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Basic Plan</h3>
                                <p class="subtitle">FREE</p>
                            </div>
                            <p class="box-text">Perfect plan to get started</p>
                            <h4 class="box-price">$00.00<span class="duration">/month</span></h4>
                            <p class="box-text2">A free plan grants you access to some cool features of Spend.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Free Plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani active">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Standard Plan</h3>
                                <p class="subtitle">ULTIMATE</p>
                            </div>
                            <p class="box-text">Best suits for great company!</p>
                            <h4 class="box-price">$49.00<span class="duration">/month</span></h4>
                            <p class="box-text2">If you a finance manager at big company, this plan is a perfect match.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Standard plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Premium Plan</h3>
                                <p class="subtitle">PRO</p>
                            </div>
                            <p class="box-text">Perfect plan for professionals!</p>
                            <h4 class="box-price">$99.00<span class="duration">/month</span></h4>
                            <p class="box-text2">For professional only! Start arranging your expenses with our best templates.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Premium Plan</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="yearly" class="wrapper-full hide">
                <div class="row justify-content-center">

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Basic Plan</h3>
                                <p class="subtitle">FREE</p>
                            </div>
                            <p class="box-text">Perfect plan to get started</p>
                            <h4 class="box-price">$199.00<span class="duration">/month</span></h4>
                            <p class="box-text2">A free plan grants you access to some cool features of Spend.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Free Plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani active">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Standard Plan</h3>
                                <p class="subtitle">ULTIMATE</p>
                            </div>
                            <p class="box-text">Best suits for great company!</p>
                            <h4 class="box-price">$249.00<span class="duration">/month</span></h4>
                            <p class="box-text2">If you a finance manager at big company, this plan is a perfect match.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Standard plan</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani ">
                            <div class="price-title-wrap">
                                <h3 class="box-title">Premium Plan</h3>
                                <p class="subtitle">PRO</p>
                            </div>
                            <p class="box-text">Perfect plan for professionals!</p>
                            <h4 class="box-price">$299.00<span class="duration">/month</span></h4>
                            <p class="box-text2">For professional only! Start arranging your expenses with our best templates.</p>
                            <div class="box-content">
                                <div class="available-list">
                                    <ul>
                                        <li>Limited Access Library</li>
                                        <li>Commercia License</li>
                                        <li>Hotline Support 24/7</li>
                                        <li class="unavailable">100+ HTML UI Elements</li>
                                        <li class="unavailable">WooCommerce Builder</li>
                                        <li class="unavailable">Updates for 1 Year</li>
                                    </ul>
                                </div>
                                <a href="contact.html" class="th-btn btn-fw style-radius">Get Your Premium Plan</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section><!--==============================
Testimonial Area  
==============================-->
    <section class="testi-area  overflow-hidden space">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-xl-6">
                    <div class="title-area mb-35">
                        <span class="sub-title">Testimonials</span>
                        <h2 class="sec-title">What our clients say?</h2>
                    </div>

                    <div class="testi-thumb" data-slider-tab="#testi-thumb">
                        <div class="tab-btn active"></div>
                        <div class="tab-btn"></div>
                        <div class="tab-btn"></div>
                        <div class="tab-btn"></div>
                    </div>
                    <div class="swiper th-slider testi-slider" id="testi-thumb" data-slider-options='{"effect":"slide","loop":false,"mousewheel":true,"spaceBetween":10,"paginationType":"progressbar","breakpoints":{"0":{"direction":"horizontal"},"1199":{"direction":"vertical"}}}'>
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“There are so many wonderful things to say about Quere. Their staff genuinely cares about their clients and is competent and professional. They assisted me in locating the ideal house for my household.”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_1.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Janny Muna</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“It sounds like you had a great experience with Quere! It's wonderful to hear that their staff is caring, competent, and professional, and that they helped you find the perfect home for your family”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_2.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Mary Crispy</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“Their staff genuinely cares about their clients and is both competent and professional. It's fantastic that they assisted you in locating the ideal house for your household.”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_3.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Abraham Khalil</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“Their caring, competent, and professional staff helped you find the perfect home for your family. If you need assistance drafting a testimonial or review to share your positive experience”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_4.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Jenifer Lopez</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“There are so many wonderful things to say about Quere. Their staff genuinely cares about their clients and is competent and professional. They assisted me in locating the ideal house for my household.”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_1.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Janny Muna</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“It sounds like you had a great experience with Quere! It's wonderful to hear that their staff is caring, competent, and professional, and that they helped you find the perfect home for your family”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_2.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Mary Crispy</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“Their staff genuinely cares about their clients and is both competent and professional. It's fantastic that they assisted you in locating the ideal house for your household.”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_3.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Abraham Khalil</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <div class="testi-card">
                                    <div class="box-review">
                                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                                    </div>
                                    <p class="box-text">“Their caring, competent, and professional staff helped you find the perfect home for your family. If you need assistance drafting a testimonial or review to share your positive experience”</p>
                                    <div class="box-wrapp">
                                        <div class="box-profile">
                                            <div class="box-author">
                                                <img src="/theme/sassa/assets/img/testimonial/testi_1_4.jpg" alt="Avater">
                                            </div>
                                            <div class="box-info">
                                                <h3 class="box-title">Jenifer Lopez</h3>
                                                <span class="box-desig">CEO of Company</span>
                                            </div>
                                        </div>
                                        <div class="box-quote">
                                            <img src="/theme/sassa/assets/img/icon/quote.svg" alt="quote">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="slider-pagination"></div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="testi-img">
                        <div class="img1">
                            <img src="/theme/sassa/assets/img/normal/testi_1_1.png" alt="">
                        </div>
                        <div class="img2">
                            <img src="/theme/sassa/assets/img/normal/testi_1_2.png" alt="">
                        </div>
                        <div class="img3">
                            <img src="/theme/sassa/assets/img/normal/testi_1_3.png" alt="">
                        </div>
                        <div class="img4">
                            <img src="/theme/sassa/assets/img/normal/testi_1_4.png" alt="">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ripple-shape">
            <span class="ripple-1"></span>
            <span class="ripple-2"></span>
            <span class="ripple-3"></span>
            <span class="ripple-4"></span>
            <span class="ripple-5"></span>
        </div>
    </section><!--==============================
Blog Area  
==============================-->
    <section class="overflow-hidden space" id="blog-sec">
        <div class="container">
            <div class="title-area text-center">
                <span class="sub-title">News & Articles</span>
                <h2 class="sec-title">Latest Release News & Articles</h2>
            </div>

            <div class="slider-area">
                <div class="swiper th-slider has-shadow" id="blogSlider1" data-slider-options='{"loop":true,"breakpoints":{"0":{"slidesPerView":1},"576":{"slidesPerView":"1"},"768":{"slidesPerView":"2"},"992":{"slidesPerView":"2"},"1200":{"slidesPerView":"2"}}}'>
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            <div class="blog-card">
                                <div class="box-img global-img">
                                    <img src="/theme/sassa/assets/img/blog/blog_1_1.jpg" alt="blog image">
                                </div>
                                <div class="box-content">
                                    <div class="blog-meta">
                                        <a href="blog.html"><i class="fa-regular fa-calendar"></i>10 July,
                                            2024</a>
                                        <a href="blog.html"><i class="fa-regular fa-clock"></i>08 min read</a>
                                    </div>
                                    <h3 class="box-title"><a href="blog-details.html">What trends do we expect to start growing in the coming future?</a></h3>
                                    <a href="blog-details.html" class="th-btn">Read More</a>
                                </div>
                            </div>
                        </div>

                        <div class="swiper-slide">
                            <div class="blog-card">
                                <div class="box-img global-img">
                                    <img src="/theme/sassa/assets/img/blog/blog_1_2.jpg" alt="blog image">
                                </div>
                                <div class="box-content">
                                    <div class="blog-meta">
                                        <a href="blog.html"><i class="fa-regular fa-calendar"></i>10 July,
                                            2024</a>
                                        <a href="blog.html"><i class="fa-regular fa-clock"></i>08 min read</a>
                                    </div>
                                    <h3 class="box-title"><a href="blog-details.html">The shift towards online shopping is expected to persist, driven</a></h3>
                                    <a href="blog-details.html" class="th-btn">Read More</a>
                                </div>
                            </div>
                        </div>

                        <div class="swiper-slide">
                            <div class="blog-card">
                                <div class="box-img global-img">
                                    <img src="/theme/sassa/assets/img/blog/blog_1_1.jpg" alt="blog image">
                                </div>
                                <div class="box-content">
                                    <div class="blog-meta">
                                        <a href="blog.html"><i class="fa-regular fa-calendar"></i>10 July,
                                            2024</a>
                                        <a href="blog.html"><i class="fa-regular fa-clock"></i>08 min read</a>
                                    </div>
                                    <h3 class="box-title"><a href="blog-details.html">What trends do we expect to start growing in the coming future?</a></h3>
                                    <a href="blog-details.html" class="th-btn">Read More</a>
                                </div>
                            </div>
                        </div>

                        <div class="swiper-slide">
                            <div class="blog-card">
                                <div class="box-img global-img">
                                    <img src="/theme/sassa/assets/img/blog/blog_1_2.jpg" alt="blog image">
                                </div>
                                <div class="box-content">
                                    <div class="blog-meta">
                                        <a href="blog.html"><i class="fa-regular fa-calendar"></i>10 July,
                                            2024</a>
                                        <a href="blog.html"><i class="fa-regular fa-clock"></i>08 min read</a>
                                    </div>
                                    <h3 class="box-title"><a href="blog-details.html">The shift towards online shopping is expected to persist, driven</a></h3>
                                    <a href="blog-details.html" class="th-btn">Read More</a>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <button data-slider-prev="#blogSlider1" class="slider-arrow style3 slider-prev"><i class="far fa-arrow-left"></i></button>
                <button data-slider-next="#blogSlider1" class="slider-arrow style3 slider-next"><i class="far fa-arrow-right"></i></button>
            </div>
        </div>
    </section>
<?php
	//echo PublicPageSassa::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
