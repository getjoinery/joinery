<?php

	PathHelper::requireOnce('includes/AdminPage.php');

	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/urls_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> null,
		'page_title' => 'Help',
		'readable_title' => 'Help',
		'breadcrumbs' => array(
			'Help'=>'',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'Help';
	//$options['altlinks'] = array('Edit Url'=>'/admin/admin_url_edit?url_url_id='.$url->key);
	$page->begin_box($options);

	?>
	<h3>Video walkthrough</h3>
	<iframe src="https://player.vimeo.com/video/496493024" width="640" height="344" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>
	<h3>Basic Architecture</h3>
	<p>This software runs on a hosted server at <a href="https://linode.com">Linode</a> ($5/month), and all files are probably cached at <a href="https://cloudflare.com">Cloudflare</a> (free).  In addition, email sending is handled with <a href="https://mailgun.com">Mailgun</a> (free), Captcha is either <a href="https://www.hcaptcha.com/">hCaptcha</a> (recommended, free) or <a href="https://developers.google.com/recaptcha/">Google ReCaptcha</a> (free), and payments use the <a href="https://stripe.com/">Stripe Api</a>.</p>

	<p>Optionally, there may be mailing list synchronization with <a href="https://mailchimp.com//">Mailchimp</a>, and appointment integration with <a href="https://www.acuityscheduling.com/">Acuity</a> or <a href="https://calendly.com/">Calendly</a>.</p>

	<p>Https is provided by <a href="https://letsencrypt.org/">Let's Encrypt</a> (free).</p>

	<p>The software itself is written in PHP, and uses a Postgresql database.  It is not like Wordpress in that it is designed to be altered by anyone.  To reprogram functionality, you must use a PHP developer, but any halfway competent PHP developer should have no trouble understanding everything.<p>

	<p>However, MOST of the *content* in this software can be edited by anyone using the admin interface.</p>

	<p>The site requires no ongoing maintenance.</p>

	<p>This site can be separated into three areas:
	<ol>
	<li>The public area</li>
	<li>The member only profile area</li>
	<li>The admin area</li>
	</ol>
	</p>

	<h4>The public area</h4>
	<p>The public area is where users to the website can view all of the publicly available pages, sign up, log in, sign up for your newsletter, reset their password, and buy/register for things.</p>

	<p>Notes:</p>
	<ul>
	<li>The entire site is timezone aware.  If a user has selected a time zone, all of the content on the site will either be displayed in their local time zone or in the case of in-person events times will be displayed in both local time and user time.</li>
	<li>To log into the website, simply go to the login page and type in your username and password.  If you do not have your password, there is a link on that page to reset your password, which consists of receiving an email with a special link in it.</li>
	<li> Users may register first, or they may do anything on the website (like sign up for the newsletter) and an account is created for them automatically.</li>
	<li>If a user signs up for the newsletter, and an API key is present for Mailchimp, that user will automatically get added to a Mailchimp list also.</li>
	</ul>

	<h4>The member only area</h4>
	<p>The member only area is where users are sent when they log in.  Nobody can access any of this area without logging in first.  The link to get to this area is in the top right of most of the website pages "My Profile".  Once users log into this area, they can edit their user info, set their contact preferences, change their password, see their messages, see their event registrations, see their order history, and make and cancel subscriptions.</p>

	<p>Notes:</p>
	<ul>
	<li>Subscriptions (recurring donations) can be seen here, canceled, or the user can create a new one.</li>
	<li>All of the users event registrations can be seen on this page. By clicking on the event name, they can see the past sessions (or all sessions for self paced courses) and they can withdraw from an event/course.   Withdrawing from an event/course is the only way to stop receiving emails about it. <i><b>Note!  Unsubscribing from the newsletter will not prevent people from getting event/course updates.  If they do not want to receive any more course or event updates, they must withdraw.</b></i></li>
	</ul>

	<h4>The admin area</h4>
	<p>The admin area is where administrators log on to...do admin stuff.  This area will look different depending on what your permission level is. The permission system runs from 0 to 10, with zero being normal users, 10 being master administrator.  Usually, people are either 0, 5, 8, or 10.</p>
	<p>There are several sections that you can see on the left:</p>
	<ul>
	<li><b>Users (and groups): </b>All of the users in the system can be found here. If you click on a user, you can see all of their payments, registrations, and other activity.  There is also a group subsection, and here you can see all of the groups. There are many reasons that groups are formed.  Some examples: during sign-up, users can be put into geographical groups, waiting lists for events are implemented as groups, and other groups are used to (for example) limit appointments to only a subset of users.</li>
	<li><b>Emails: </b>The emails section is where all bulk emails are sent (if you are not using another provider like Mailchimp).  </li>
	<li><b>Products: </b>The products section are where things are created that can be purchased.  Single donations, subscriptions, event registrations, and all other product purchases have to have an existing product in the system.  Products can also be put into groups for convenience.</li>
	<li><b>Orders: </b>The orders section lists all of the orders that have gone through the system. Every order is here except subscriptions, where only the first one for each user is recorded.  All subsequent subscription payments are done at stripe, and they are not reflected here.  There is also a "stripe payments" section, which pulls all of this information from stripe and includes all subscription payments.  Finally, there is a "cart logs" section for troubleshooting.</li>
	<li><b>Events: </b>The events section is where all events and courses are found in managed.  More info below.</li>
	<li><b>Files: </b>The files section is the place where all files (including pictures) are uploaded.  <i><b>Note! Do not upload files which contain sensitive information.  There is some security implemented, but the files section should only be used as a place to put files to make them available to post and or link.</b></i></li>
	<li><b>Videos: </b>This system does not host videos.  The videos section is simply a place to keep a record of all available videos so that they can be listed and attached to events or courses.  The videos themselves remain hosted on either YouTube or Vimeo.</li>
	<li><b>Page Content: </b>These are individual pages on the website.  All pages in this section have a url that starts with /pages/.  More details below.</li>
	<li><b>Blog: </b>There will be a blog section here if the blog is turned on in "settings".  It's a normal blog, with comments and various choices about comment privacy in "settings".</li>
	<li><b>Statistics: </b>There are some basic statistics here, including unique visitors, signups by date, etc.  There is also an errors section that records errors that users have received while using the website. </li>
	<li><b>Urls: </b>This is a url forwarding section.  You can specify an incoming URL and then where you want the  user to be redirected to.  This is mostly useful for when pages have moved.</li>
	<li><b>Settings: </b>This section contains various settings for the website like whether to turn on comments or what email templates to use for certain purchases or events.</li>
	</ul>

	<h4>Events</h4>
	<p>A description of the various fields on an event.</p>
	<ul>
	<li><b>Event name</b> Whatever the event will be called on the website</li>
	<li><b>Main image</b> The image that will go along with the event on the website.</li>
	<li><b>Event location</b> Any description of where this event will happen. You can write anything.</li>
	<li><b>Max signups</b> This is the maximum number of registrations that will be allowed.  When this number is reached, either registration will stop or users will be offered the opportunity to join the waiting list (if that is allowed).</li>
	<li><b>Event short decription</b> This is a short couple of sentences describing the event to use on the search results page.</li>
	<li><b>External register link</b> If users will register for this event somewhere else (like a retreat center), put that link here.</li>
	<li><b>Led by</b> Who is leading this event?</li>
	<li><b>Event timezone</b> If this event happens in person, we will display the local time next to the user's time on the website so that people don't get confused.  We will also use this time to calculate other people's times in their own time zone.</li>
	<li><b>Status</b> Active means not cancelled and not completed.  Cancelled means cancelled.  Completed means this event will show up as completed on the website (not just in the past).</li>
	<li><b>Visibility</b> Live is live, Live but unlisted means that you can send people the link but it won't appear anywhere on the website, hidden means nobody will be able to reach it.</li>
	<li><b>Show calendar link</b> Do you want to show calendar links on the my profile page?  You would not want to do this if this is a weekly or long term series of events (we don't want someone's calendar blocked off for 6 months).</li>
	<li><b>Registration</b> Registration cannot be turned on unless the event has been connected with a product or an external register link is provided.</li>
	<li><b>Waiting list</b> If this is "allowed", after the event reaches the max sign-ups, new  registrations will go on a waiting list.  You can find that list in the "groups" section under "users".</li>
	<li><b>Session display style</b> Condensed means that when someone visits the event page in the my profile section, all of the sessions will be listed in order. Separate means that all sessions will be on their own page (use separate for online courses)</li>
	<li><b>Event start/end time</b> This is the earliest time at which the event begins and the latest time at which the last session ends.</li>
	<li><b>Event description</b>  Full HTML description of the event</li>
	<li><b>Info only for registrants</b> Full HTML information that will be displayed to users in the my profile section after they have registered. Use this area to, for example, post the zoom link, address, or other instructions.</li>
	</ul>

	<h4>Products</h4>
	<ul>
	<li><b>Active</b> If a product is disabled, it cannot be purchased.</li>
	<li><b>Product name</b> However you want it described on the website</li>
	<li><b>Product name</b> This appears on the product page to tell people what they're getting.</li>
	<li><b>Event registration?</b> If this product is an event/course registration, choose the event here.</li>
	<li><b>Subscription?</b> Choose whether this product will be billed once or as a subscription monthly.</li>
	<li><b>Pricing</b> Choose whether the product has one price, multiple prices, or whether the user chooses.</li>
	<li><b>Price</b> This is the amount the product costs to fully purchase it.  Whole dollar amounts only.</li>
	<li><b>Max number that can be added to the cart</b> How many can one person purchase at the same time?</li>
	<li><b>Purchase expires after (days)</b> How many days until whatever was purchased expires (usually, an event registration)?</li>
	<li><b>Product group</b> Choose the group that most closely matches.  This doesn't do anything...it is just for display purposes.</li>
	<li><b>Info to collect</b> What information do you want to collect when the user purchases this product.  For example, if the event will be recorded, then recording consent should be chosen here. Choose the right combination from the checkboxes.</li>
	<li><b>Product description</b> One or two sentences describing what the person is buying.</li>
	</ul>

	<h4>How do I...?</h4>
	<p><b>How do I post a new event/course?</b>
	<ol>
	<li>Go to Events->Future Events->New Event.</li>
	<li>Enter the needed information, see above "Events" for a description of fields.  Choose "closed" for registration right now.</li>
	<li>Choose "submit" to save the event.</li>
	<li>If registration will happen locally, we will need to create a product.  If it will happen on another website, go back and enter an "external register link".</li>
	<li>Create a new product, even if the cost is $0.  Go to Products->New Product.</li>
	<li>See above "Products" for a description of the fields.</li>
	<li>Make sure you choose the event from the drop down under "event registration?".</li>
	<li>Choose submit to create the product.</li>
	<li>If there will be more than one price available, add as many product versions as needed to cover all options.</li>
	<li>Whenever you are ready, you can now go back to the event and make it live and open registration.</li>
	<li>(Optional) Click the "Sessions" tab and add sessions as necessary.  If there are sessions in the system, users will get a notification on their "My profile" page telling them when the next session is.  </li>
	<li>Please visit the public event page and also add yourself to the event and check the "My Profile" page to make sure everything appears correct.</li>
	</ol></p>

	<p><b>How do I delete things?</b> If you are a master administrator, you can permanently delete most content using the "delete" link on the item page.  Other users cannot delete things.</p>
	<?php

	$page->end_box();

	$page->admin_footer();
?>

