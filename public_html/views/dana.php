<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	//require_once (LibraryFunctions::get_logic_file_path('fundraising_thermometer.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Donations',
		'description' => '',
	);
	$page->public_header($hoptions);
	
	
	echo PublicPage::BeginPage('Dana: the priceless practice of giving generously');
?>

	<p>Integral Zen is a registered nonprofit religious organization and donations are fully tax deductible in the United States.</p>

<div uk-grid>
    <div class="uk-width-1-3@m"><div style="padding: 20px">


			


	<div>
	<h3>Monthly Donation</h3>	<a href="/product?product_id=3" style="float:left; margin:20px; margin-top: 0px;" ><img width="100" height="100" src="/uploads/small/Generosity-dana-buddhism-7x_j0t7xha.jpg" /></a>

	<p>Monthly donations are very helpful because they allow us to manage our budget.  We accept credit or debit cards.<br /><a class="et_pb_button" href="/product?product_id=3" >Monthly donation</a> </p>
	</div>
	<br style="clear:both;">
	<div>
	<h3>One Time Donation (Credit or Debit card)</h3>
		<a href="/product?product_id=2" style="float:left; margin:20px; margin-top: 0px;" ><img width="100" height="100" src="/uploads/small/DBZ-buddha_kzja6o64.jpg" /></a>

	<p>To make a one time donation to Integral Zen by credit or debit card. (preferred)<br /><a class="et_pb_button" href="/product?product_id=2" >One time donation by credit/debit card</a> </p>
	</div>
	
	<br style="clear:both;">
	<div>
	<h3>One Time Donation (Paypal)</h3>
		<a href="/product?product_id=2" style="float:left; margin:20px; margin-top: 0px;" ><img width="100" height="100" src="/uploads/small/DBZ-buddha2_2vlavbus.jpg" /></a>

	<p>To make a one time donation to Integral Zen by paypal.<br /><a class="et_pb_button" href="https://paypal.me/integralzen" >One time donation by paypal</a> </p>
	</div>	
	<br /><br />
	<p><strong>Note:  If you are unable to use a credit or a debit card or paypal, we have a transferwise account with foreign bank info (contact <a href="mailto:info@integralzen.org">info@integralzen.org</a> for details). </strong></p>

	</div>
	</div>
	<div class="uk-width-2-3@m"><div style="padding: 20px">
	
	<!--
<b>Existing donors have agreed to provide $15,000 for a matching fundraiser this year.  Until Dec 31, 2020, all donations will be doubled. </b> 		

				
<h3>Adjusting our long-term vision to the times</h3>
<div style="float:right;">
<a href="http://www.coolfundraisingideas.net/" alt="Fundraising Thermometer">
<img border="0" src="https://www.coolfundraisingideas.net/thermometer/thermometer.php?currency=dollar&goal=30000&raised=<?php echo $replace_values['total_raised']; ?>&color=red&size=large">
</a>
</div> 
<p>2020 without question was a reset of business-as-usual for Integral Zen. Here is
what we have been doing:</p>
<ul>

<li>We started the year with a full list of
retreats and workshops, but one by one, all our US, and most international retreats were
cancelled.</li>
<li>In March, we started our Sunday Weekly Integral Dharma Talks
which are still running.</li>
<li>We broadened the Integral Zen “Whole Spectrum of Shadows,” webinars to
5 courses, and are currently presenting the 2nd course.</li>
<li>We offered a virtual 3-day online Meditation Workshop.</li>
<li>We started new small virtual shadow workgroups.</li>
<li>We expanded the group of Integral Zen Lay Teachers who are working
directly one on one with individuals.</li>
<li>Our website has been steadily improving, and we've edited past webinars into self-paced courses, which enable
the teachings to be available to more people.</li>
</ul>
<p>Looking back on 2020 prompts questions about fundraising needs. First, will we return to “normal”?
Second, how normal will “normal” be? What new needs are arising in this new world, as new opportunities emerge for
us to serve those who are suffering?</p>
<p>“Don’t know mind” is the way.</p>
<p>We are planning to offer live retreats when it is safe to do so, but it
remains unclear how soon things will return to some semblance of normality.</p>

<h3>Fundraising and big picture plans </h3>
<p>Because we do not have a physical center, we are in relatively good
shape, but here are some ways we can prepare to be anti-fragile in these unpredictable times:</p>
<ul>
<li>We need some computer and audio-visual equipment for Doshin to increase the quality of our online programming.</li>
<li>Doshin is working on a book, and we would like to support him in the process of
writing, and potentially publishing.</li>
<li>We need to hire a graphic designer to help give a facelift to our website.</li>
<li>We have many wonderful volunteers, and yet, we need to pay critical staff.</li>
<li>We would like to offer “scholarships” to enable
underprivileged attendees to attend retreats and programs.</li>
</ul>
<p>In today’s tragically politically polarized world, a huge need is emerging
among the people who are drawn to our teachings. A huge number of
people are struggling with negative self-image, traumas, depression,
shadows, feelings of worthlessness, and deeper self-loathing.</p> 
<p>For 17 years, we have been developing powerful tools that address these issues. We have helped many people heal,
develop, and grow beyond these crippling problems.</p>
<p>Ironically, many of these issues spring from the collective beliefs and values of the groups we belong to. Social media is spreading these through the postmodern
world in a way and at a speed that has never occurred before. The need to bring new treatment tools to those who suffer with these new forms of self-loathing is great, the suffering is real, and we have good medicine.</p>
<p>We would like to help as many people as we can who suffer from these
issues. </p>
<p>We need to
pass on these Integral Zen teachings, skills, and tools to the next
generation of Integral Zen Lay Teachers so that they can continue to
liberate all beings.</p>
<p>If our society continues to polarize into
more rigid addictions and allergies, we will see deepening internal
conflicts that explode in external violence. In the midst of this polarization, there will be many new opportunities that arise for Integral Zen
to bring clarity, compassion, and wisdom without becoming another cult.</p>
<p>We intend to begin saving resources to
purchase land and a building to conduct retreats and pass on the teaching. It
would be wonderful to have resources in the bank in case an unusual opportunity arises.</p>

<p>Please consider giving generously for the benefit of all beings.</p>

<p>In gratitude and service,</p>

<p><img src="/uploads/small/DOshin-9%20(1).jpg" style="float:left; margin-right: 20px;"><strong>Doshin Hannya Michael Nelson Roshi<br />
<i>Founder and spiritual leader of Integral Zen.</i></strong></p>

-->


<h3>Dana: the priceless practice of giving generously</h3>
<p>Traditionally in Buddhism the precious Lineage teachings are a gift of unconditional love -
given freely, beyond price, with no expectation of receiving anything in return. Historically,
Buddhist monks have no possessions, their lives an embodiment of the Teachings they have
received. This Eastern custom of dana (giving generously) directly involves the community, who
respond by giving the monks money and food so they can survive and continue teaching.
In the East, such giving and receiving is woven into the collective-focused culture. In the
Individual culture of the west, no such tradition has evolved. Western Dharma teachers must
wrestle with this dilemma. The pressing question is: “How can I survive without such a
culturally sanctioned custom of community support for Dharma Teachers?”.</p>

<ul>
<li>I could charge for the Dharma teachings.</li>
<li>I could teach part time and make a living some other way.</li>
<li>I can ask for donations for teaching, which often provide funds that fall short of
surviving.</li>
<li>I can spend time building the organization and its resources rather than teaching
Dharma.</li>
<li>I can start and maintain a fundraising campaign to support the teachers, which means I
divert precious time away from teaching the Dharma.</li>
<li>I can supplement teaching the Dharma with teaching other things that I can charge for.</li>
</ul>

<p>As a lineage holder with all the responsibility to keep the Dharma pure and uncorrupted, if I
create an expectation of payment anywhere in the organization, then the purity of giving
generously from a heart enlightened by wisdom – absolute clarity and selfless compassion - is
lost. Teaching the Dharma becomes a business. Unconditional love becomes conditional. The
Dharma must be free from the three poisons: ignorance, attachment and aversion, and their
cultural expressions of unconsciousness, greed and hate.</p>

<p>Out of this need to create a means of livelihood, in our Sangha, our community, we have
created a new form of the ancient Dharma Teaching of “Giving Generously from the heart”.
We have given this old teaching a new name: The Reciprocity of Generosity. It is an opportunity to receive these teachings, given generously with no expectation of the teachers
receiving anything in return; and then look into your own heart and see if you have the
resources and want to respond with the same spirit of generosity. This practice from the
groundless ground of unconditional love is a direct way to deepen your own practice of insight
and compassion, contribute positive karma, and make the world more healthy and whole.
Integral Zen is a non-profit religious organization dedicated to helping all beings end war,
conflict, and suffering, beginning within and expanding to our relationships with each other.
Your gifts of Dana support the teachers and teachings, the Sangha, the larger Dharma
community, and the depth of your own awakening.</p>

<p>With deepest Gratitude, we practice the Reciprocity of Generosity. Remember there are many
ways to give with this generosity. Some have money, others have time, others have talents and
still others have important contacts. We welcome all gifts given generously from an open heart
with unconditional love. We will put them all to good use in bringing these priceless teachings
into these interesting times.</p>

<p>I recently heard Jack Kornfield ask: “Have you ever seen anyone be unhappy when they are
giving generously from the heart?” What a lovely question to deeply consider.</p>

<p>In gratitude and service,</p>

<p><img src="/uploads/small/DOshin-9%20(1).jpg" style="float:left; margin-right: 20px;"><strong>Doshin Hannya Michael Nelson Roshi<br />
<i>Founder and spiritual leader of Integral Zen.</i></strong></p>



	
		</div>
	</div>
</div>	
	
<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>