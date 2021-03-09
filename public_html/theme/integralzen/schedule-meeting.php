<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	$logic_path = LibraryFunctions::get_logic_file_path('schedule-meeting.php');
	require_once ($logic_path);

	$session = SessionControl::get_instance();
	$session->set_return();

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Schedule a meeting',
		'description' => '',
	);
	$page->public_header($hoptions);
	
	
	echo PublicPage::BeginPage('Schedule a meeting');
			echo '<div class="section padding-top-20">
			<div class="container">';
?>

				<div class="row col-spacing-50">
					<!-- Blog Posts -->
					<div class="col-12 col-lg-8 order-lg-2">
						<!-- Blog Post box 1 -->
						<div class="margin-bottom-50">
							<div class="margin-top-30">
<h5>Integral Zen One-on-one Meetings</h5>
<ul>
<li><strong>Introductory Meeting [1 hour]</strong> - 
If you are new to Integral Zen and this is your first time to schedule an appointment, choose a 1 hour “Introductory Meeting”. This is your opportunity to ask any questions you may have about your meditation, Integral Zen, or Integral Awakening: Waking Up, Growing Up, Fucking Up, Cleaning Up and Showing Up as an awake, compassionate, whole and healthy human being.</li>
<li><strong>One on One [1 hour]</strong>
This is a private meeting between a Zen student and an Integral Zen lay teacher or teacher in training. The intention is to advise students and answer questions about their practice. If you are a returning student wanting to check in about your practice you should choose this option. Questions should be limited to your Integral meditation practice, which includes: Waking up, Growing up, Fucking up, Cleaning up and Showing up as a fully awake, compassionate, healthy and whole human being.</li>
<li><strong>Awareness Intervention [1-2 hour](by Invitation Only)</strong>
This is a meeting between a Zen student and an Integral Zen lay teacher or teacher in training. The intention is to guide students with language into an experience of “wu.” What is wu? Wu is a Chinese word, Japanese is “mu”. It is difficult to translate into English. The best translation for our purposes of Integral Awakening is “nothingness.” It is ironic that a direct experience of nothingness, the emptiness that isn’t empty but awake, can change your life.</li>
</ul>
								<h5>Dana: the priceless practice of giving generously</h5>


<p>Dana means “selfless giving” or “giving without conditions or ego agendas”. Integral Zen does not charge for any of these offerings; we do not charge for
the Dharma because the work is just too important. Ironically, Integral Zen does rely on the generous
gifts of Dana from members of the Integral Zen Sangha and Community. This work is possible because
of your generous support and donations. Please practice generous giving - Dana by making a
contribution. </p>
<p><a class="button button-dark" href="/contribute/dana/">Give Dana (Donation)</a></p>

							</div>
						</div>
						
					</div>
					<!-- end Blog Posts -->

					<!-- Blog Sidebar -->
					<div class="col-12 col-lg-4 order-lg-1 sidebar-wrapper">
						<!-- Sidebar box 1 - About me -->
						<div class="sidebar-box text-center">
							<h6 class="font-small font-weight-normal uppercase">Meetings with an Integral Zen Lay Teacher</h6>
							<img class="img-circle-md margin-bottom-20" src="/uploads/small/kodoaishi_2hwxxt7.jpg" alt="">
							<p>If you are new to Integral Zen or a returning student and would like to schedule an appointment with an Integral Zen Lay Teacher</p>
							<a class="button button-dark" href="https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers" >Schedule a Meeting</a> 
						</div>
						<!-- Sidebar box 2 - Categories -->
						<div class="sidebar-box text-center">
							<h6 class="font-small font-weight-normal uppercase">Pre-arranged Meetings with Doshin</h6>
							<img class="img-circle-md margin-bottom-20" src="/uploads/small/DOshin-9%20(1).jpg" alt="">
							<p>Meetings with Doshin are all pre-arranged and by invitation only.</p>
							<?php echo $replace_values['doshin_appt']; ?> 
						</div>

						

					</div>
					<!-- end Blog Sidebar -->
				</div><!-- end row -->







	
<?php
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>