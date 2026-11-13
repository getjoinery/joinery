<?php
	// The home page: hero, introduction, benefits, the retreat video, events
	// and testimonials. Styled by assets/css/xandyliberato.css.
	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page ?? false,
		'title' => 'Homepage',
	));

	echo PublicPage::BeginPage('');

	$quote_mark = 'M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z';
?>

<div class="xl-hero">
	<img src="https://xandyliberato.com/static_files/Home1.png" alt="Xandy Liberato">
	<div class="xl-hero-spacer"></div>
</div>

<section class="xl-intro">
	<div class="xl-intro-head">
		<h2 class="xl-heading xl-serif">Welcome to the Liberato Portal</h2>
		<p>Dance as a source of wellbeing through online courses, workshops, private classes, retreats, books and articles.</p>
	</div>
	<dl class="xl-features">
		<div><dt class="xl-serif">Liberato Method</dt><dd>Experience Xandy Liberato's dance training method.</dd></div>
		<div><dt class="xl-serif">Retreats</dt><dd>Concentrated study.</dd></div>
		<div><dt class="xl-serif">Zouk Training</dt><dd>Classes tailored just for Zouk.</dd></div>
		<div><dt class="xl-serif">Mentorship</dt><dd>Improve all aspects of yourself, not just dance.</dd></div>
	</dl>
</section>

<section class="xl-benefits">
	<h2 class="xl-heading xl-serif">Benefits of The Liberato Method</h2>
	<div class="xl-benefits-grid">
		<div class="xl-serif">
			<p>Increased body awareness</p>
			<p>Increased self-confidence and confidence towards others</p>
			<p>More empathetic communication</p>
			<p>More conscious relationships</p>
			<p>Listening development</p>
		</div>
		<div><img src="https://xandyliberato.com/static_files/effects-of-liberato-700.png" alt="Effects of Liberato Method"></div>
		<div class="xl-serif">
			<p>Sensitivity development</p>
			<p>Increased enjoyment ability</p>
			<p>Experiences of freedom</p>
			<p>Changes in the way of dancing and living</p>
			<p>Increased social abilities</p>
			<p>Change of mindset</p>
		</div>
	</div>
</section>

<section class="xl-band">
	<div class="xl-retreat">
		<h2 class="xl-heading xl-serif">Our Retreat</h2>
		<div class="xl-video">
			<iframe src="https://www.youtube.com/embed/BBYizZLqYkM" title="Xandy Liberato Retreats" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
		</div>
	</div>
</section>

<section class="xl-events">
	<h2 class="xl-heading xl-serif">Our Next Events</h2>
</section>

<section class="xl-band">
	<div class="xl-testimonials-head">
		<h2 class="xl-heading xl-serif">Testimonials</h2>
	</div>
	<div class="xl-quotes">
		<div class="xl-quote">
			<blockquote>
				<div class="xl-quote-text">
					<svg fill="currentColor" viewBox="0 0 32 32" aria-hidden="true"><path d="<?php echo $quote_mark; ?>"></path></svg>
					<p>The retreat completely changed my life and my vision of the world. Now I feel so much silence and love. And I feel free in dance. My creativity and flow woke up. Thank you all!</p>
				</div>
				<footer>Daniel<span>Retreat participant</span></footer>
			</blockquote>
		</div>
		<div class="xl-quote">
			<blockquote>
				<div class="xl-quote-text">
					<svg fill="currentColor" viewBox="0 0 32 32" aria-hidden="true"><path d="<?php echo $quote_mark; ?>"></path></svg>
					<p>Me siento muy conmovida por las personas increíbles que forman parte del personal, todo el mundo. Me sentí SEGURA y eso significa mucho para mí.</p>
				</div>
				<footer>Angelique<span>Student</span></footer>
			</blockquote>
		</div>
	</div>
</section>

<section class="xl-band">
	<div class="xl-quotes xl-quotes-ruled">
		<div class="xl-quote">
			<blockquote>
				<div class="xl-quote-text">
					<svg fill="currentColor" viewBox="0 0 32 32" aria-hidden="true"><path d="<?php echo $quote_mark; ?>"></path></svg>
					<p>The retreat was a transformation point in my life. I feel like I am awake, refreshed, inspired, and filled with emotion. Thank you for the beautiful experience.</p>
				</div>
				<footer>Lubna<span>Retreat Participant</span></footer>
			</blockquote>
		</div>
		<div class="xl-quote">
			<blockquote>
				<div class="xl-quote-text">
					<svg fill="currentColor" viewBox="0 0 32 32" aria-hidden="true"><path d="<?php echo $quote_mark; ?>"></path></svg>
					<p>Sinto-me como se as portas que não sabia que existiam tivessem se aberto. Ainda não sei bem o que isso significa, mas sinto que a minha vida mudou para melhor. Este foi um evento que mudou a minha vida, e não tenho palavras para dizer o quanto sou grato por ter feito parte disso. Obrigado!</p>
				</div>
				<footer>Christoffer<span>Student</span></footer>
			</blockquote>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();

	$page->public_footer(array('track' => TRUE));
?>
