<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

$settings = Globalvars::get_instance();
$page = new PublicPage();
$page->public_header(array(
    'title' => 'Home - ' . $settings->get_setting('site_name', true, true),
    'showheader' => true
));
?>

<!-- Banner Area -->
<section class="banner-area relative" id="home">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row fullscreen d-flex align-items-center justify-content-center" style="height: 493px;">
            <div class="banner-content col-lg-8 col-md-12">
                <h1>
                    Empowering You to
                    Be Healthy
                </h1>
                <p class="pt-10 pb-10 text-white">
                    Bringing old-school service to modern holistic medicine in Knoxville, TN.
                </p>
                <a href="/about" class="primary-btn text-uppercase">Learn More</a>
            </div>
        </div>
    </div>
</section>

<!-- Appointment Area -->
<section class="appointment-area">
    <div class="container">
        <div class="row justify-content-between align-items-center pb-120 appointment-wrap">
            <div class="col-lg-5 col-md-6 appointment-left mt-30">
                <h1>
                    About Empowered Health
                </h1>


                <p>
                    Empowered Health is a new, innovative personalized medicine service in Knoxville, TN.
                </p>
                <p>
                    Imagine seeing your healthcare provider and enjoying the experience, not feeling rushed.&nbsp; At Empowered Health, you have a provider who takes interest in all aspects of your life because your well-being isn't
                    just physical, but mental and emotional as well.&nbsp;                </p>
                <p>
                    At Empowered Health, our members get
                    </p><ul class="unordered-list">
                    <li>longer appointments</li>
                    <li>personalized service that takes all of you into account</li><li>
                    </li><li>visits when you need them</li><li>
                    </li><li>annual health improvement: goal-setting and planning</li>
                    <li>and lots more...</li>
                    </ul>

                <p></p>
                    <a href="/contact" class="primary-btn text-uppercase">Contact to Book</a>

            </div>
            <div class="col-lg-6 col-md-6 appointment-right pt-60 pb-60 mb-30">
                <h3 class="pb-20 text-center mb-30">Letter from Heath</h3>
                <div class="pb-30 pr-30 pl-30">
                        <p>As a nurse practitioner for over a decade, I see two things wrong with modern medicine: strict time limits mean visits where the patient cannot be heard, and the idea of just taking more pills to fix our problems. </p>

                    <p>I created Empowered Health for those of us who want to treat the underlying causes of our illness.&nbsp; During our longer appointments, we will talk about a range of options, with western medicine
                    being only one of them. We will design a plan together to meet your health goals and
                    then coach and modify the plan as needed. On your journey to great health, you
                    will learn to listen to your body and mind, hence the name: Empowered
                    Health.</p>

                <p>
                    Sincerely,<br>
                    <strong>Heath Tunnell, Certified Nurse Practitioner (FNP)</strong>
                </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Offered Service Area -->
<section class="offered-service-area section-gap">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 offered-left">
                <h1 class="text-white">Book a Visit</h1>
                <div class="service-wrap row">
                    <div class="col-lg-6 col-md-6">
                        <div class="appointment-left sidebar-service-hr">
                            <h3 class="pb-20">
                                Standard Visits
                            </h3>

                            <ul class="time-list">
                                <li class="d-flex justify-content-between">
                                    <span>Telemedicine</span>
                                    <span>$60</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Mobile visit or Covid Testing (PCR or Rapid Covid Testing)</span>
                                    <span>$100</span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span>Same day mobile visit or Covid Testing (PCR or Rapid Covid Testing)</span>
                                    <span>$125</span>
                                </li>
                            </ul>
                            <a href="/contact" class="primary-btn text-uppercase">Contact to Book</a>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-6">
                        <div class="appointment-left sidebar-service-hr">
                            <h3 class="pb-20">
                                Coronavirus Consult (free)
                            </h3>

                            <p>
                                Short 15 minute visit if you think you might have Covid-19. Testing is also available at an additional charge.
                            </p>

                            <ul class="time-list">
                                <li class="d-flex justify-content-between">
                                    <span>Coronavirus consult</span>
                                    <span>Free</span>
                                </li>
                            </ul>
                            <a href="/contact" class="primary-btn text-uppercase">Contact to Book</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="offered-right relative">
                    <div class="overlay overlay-bg"></div>
                    <h3 class="relative text-white">Services</h3>
                    <ul class="relative dep-list">
                        <li><a href="#">Primary care/Telemedicine</a></li>
                        <li><a href="#">Covid-19 Testing</a></li>
                        <li><a href="#">Chronic health management</a></li>
                        <li><a href="#">Stress management</a></li>
                        <li><a href="#">Holistic health plans</a></li>
                        <li><a href="#">Nutrition</a></li>
                    </ul>
                    <a class="viewall-btn" href="#">Read more about our services</a>
                </div>
            </div>


        </div>
    </div>
</section>

<!-- Facilities Area -->
<section class="facilities-area section-gap">
    <div class="container">
        <div class="row d-flex justify-content-center">
            <div class="menu-content pb-70 col-lg-7">
                <div class="title text-center">
                    <h1 class="mb-10">Our Specialties</h1>
                    <p>Come to Empowered Health not just to fix a health problem, but to improve your overall health and wellness. </p>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-3 col-md-6">
                <div class="single-facilities">
                    <span class="lnr lnr-rocket"></span>
                    <a href="#"><h4>Telemedicine/Mobile Appointments</h4></a>
                    <p>
                        Empowered Health can be your primary care provider, with easy online appointments and mobile in-person visits.
                    </p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="single-facilities">
                    <span class="lnr lnr-heart"></span>
                    <a href="#"><h4>Chronic Health Management</h4></a>
                    <p>
                        We don't just prescribe pills and hope things get better. We work with you and our partners to manage all of the causes of your condition.
                    </p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="single-facilities">
                    <span class="lnr lnr-bug"></span>
                    <a href="#"><h4>Stress Management</h4></a>
                    <p>
                        Stress is an underappreciated source of many health problems. We have special training in many stress-management modalities.
                    </p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="single-facilities">
                    <span class="lnr lnr-users"></span>
                    <a href="#"><h4>Holistic Health Plans</h4></a>
                    <p>
                        Get the best of western medicine, combined with the latest in nutrition, stress management, massage, fitness, and counseling.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Feedback Area -->
<section class="feedback-area section-gap relative">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12 pb-60 header-text text-center">
                <h1 class="mb-10 text-white">Hear from our happy patients</h1>
                <p class="text-white">
                    We have more happy patients than we can list, but we'll try to list just a few...
                </p>
            </div>
        </div>
        <div class="row feedback-contents justify-content-center align-items-center">
            <div class="col-lg-6 feedback-left relative d-flex justify-content-center align-items-center">
            </div>
            <div class="col-lg-6 feedback-right">
                <div class="active-review-carusel owl-carousel owl-theme">
                    <div class="single-feedback-carusel">
                        <div class="title d-flex flex-row">
                            <h4 class="text-white pb-10">Rico H. - Knoxville</h4>
                            <div class="star">
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                            </div>
                        </div>
                        <p class="text-white">
                            Heath is the best Nurse Practitioner in the Knoxville area! I had a
procedure done around the holidays last year that made me feel uneasy going
into. It also didn't help that I was new to the area but Heath made me feel
right at home. From the pre-op to the post- op he was there to guide me every
step of the way. If you are looking for someone who is an erudite and who cares
about the well being of patients, Heath Tunnell is the N.P. I would recommend.
                        </p>
                    </div>
                    <div class="single-feedback-carusel">
                        <div class="title d-flex flex-row">
                            <h4 class="text-white pb-10">Caylor T. - Knoxville</h4>
                            <div class="star">
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                            </div>
                        </div>
                        <p class="text-white">
                            Heath has been an excellent healthcare provider. His options for treatment have always been cost effective and tailored specifically to my needs. He has always provided me with multiple treatment options and has guided me to the best solution. I highly recommend Heath and will continue to come to him.
                        </p>
                    </div>
                    <div class="single-feedback-carusel">
                        <div class="title d-flex flex-row">
                            <h4 class="text-white pb-10">Tildy S. - Online Patient</h4>
                            <div class="star">
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                                <span class="fa fa-star checked"></span>
                            </div>
                        </div>
                        <p class="text-white">
                            Heath was super helpful while I had coronavirus the last couple weeks. Every time a symptom arose or changed he talked it through with me and explained what was going on and what I could do to help my body heal, including advice like body positions that helped keep my lungs healthy, what to eat to lessen the inflammation and help breathing, and which medications to consider taking, and those to avoid. </p>
                        <p class="text-white">His experience and knowledge makes his advice trustworthy and also calming because I knew he'd seen all my symptoms loads of times before and often way way more severe, so I felt in good hands. It was really important to speak to someone I trusted because stress and panic make the symptoms worse, and Heath was always there when I was freaked out that my symptoms were getting worse.</p>
                        <p class="text-white"> Now I'm almost fully recovered and I'm really grateful.</p>
                        <p class="text-white">Thanks Heath!</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$page->public_footer();
?>
