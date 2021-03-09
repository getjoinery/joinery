<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

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




		

<!--
		
		<div style="border: 1px solid rgba(211,211,211,1); margin-bottom: 20px; padding: 20px;">
		<h1 class="cta-title">2020 Integral Zen Fundraiser</h1>	
			
<a style="float:left; margin: 10px;" href="https://www.coolfundraisingideas.net/" alt="Fundraising Thermometer">
<img border="0" src="https://www.coolfundraisingideas.net/thermometer/thermometer.php?currency=dollar&goal=30000&raised=<?php echo $replace_values['total_raised']; ?>&color=red&size=large">
</a>

<p>In today’s tragically politically polarized world, a huge need is emerging
among the people who are drawn to our teachings. Many
people are struggling with negative self-image, traumas, depression,
shadows, feelings of worthlessness, and deeper self-loathing.</p> 
<p>For 17 years, we have been developing powerful tools that address these issues. We have helped many people heal,
develop, and grow beyond these crippling problems.</p>
<p>The need to bring new treatment tools to those who suffer with these new forms of self-loathing is great, the suffering is real, and we have good medicine.</p>
<p>We would like to help as many people as we can who suffer from these
issues. </p>
<p><b>To fund our efforts for 2021, existing donors have agreed to provide $15,000 for a matching fundraiser this year. Read on for all of the details.</b></p> 		
				
<br /><p><a class="et_pb_button" href="/contribute/dana/" >Read more about the fundraiser</a></p>	
	<div style="clear:both;">&nbsp;</div>


</div>

-->


	
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
		<!--
			<h1 class="cta-title"><a href="/event/28/Sunday-Integral-Dharma-Calls">Live Dharma talks by Doshin</a></h1>
                         	<div class="cta-info">							
																	<div class="cta-img">
											<img src="/uploads/small/doshin-mic.jpg" alt="" width="320" height="160" />
										</div>
																<div class="cta-description"><p>Happening every Sunday. Zen wisdom, deep conflicts, and a simple Integral framework to
make sense of what is happening in the world.</p>
</div>
							</div>
							
							<div class="readmore-button">
								<a class="et_pb_button" href="/event/28/Sunday-Integral-Dharma-Calls">Register</a>
							</div>	
							-->
							
						</li>
						
					
						
						
											<li class="single-cta">
											<?php
													$page_content = new PageContent(3, TRUE);
		if($page_content->get('pac_is_published')){
			echo $page_content->get_filled_content(); 
		}
		?>
		<!--
			<h1 class="cta-title"><a href="/event/29/Daily-Group-Meditation">Daily Group Meditation</a></h1>
                         	<div class="cta-info">							
																	<div class="cta-img">
											<img src="/uploads/small/empty_sitting.jpg" alt="" width="320" height="160" />
										</div>
																<div class="cta-description"><p>Join us in our Rocks & Waters Zendo on Zoom for daily meditation. Daily meditation periods - mornings from 7 to 8 eastern.</p>
</div>
							</div>
							
							<div class="readmore-button">
								<a class="et_pb_button" href="/event/29/Daily-Group-Meditation">Read more</a>
							</div>	
							-->
						</li>	



												
	
						
						
						
					
						
											</ul><!-- home-ctas -->	
					

		<div style="border: 1px solid rgba(211,211,211,1); margin-bottom: 50px; padding: 20px;">
		
		<h2 class="cta-title">Sign up for updates</h2>	
		<a class="button button-dark" href="/community/newsletter/">Sign up for the newsletter</a>

	

<?php
	//echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
