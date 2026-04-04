<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));

	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

	$session = SessionControl::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('');

	?>

     <!-- Hero card -->
      <div class="relative">
        <div class="absolute inset-x-0 bottom-0 h-1/2"></div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div class="relative shadow-xl sm:rounded-2xl sm:overflow-hidden">
            <div class="absolute inset-0">
              <img class="h-full w-full object-cover" src="https://xandyliberato.com/static_files/Home1.png" alt="Xandy Liberato">
            </div>
            <div class="relative px-4 py-16 sm:px-6 sm:py-24 lg:py-32 lg:px-8">
              <h1 class="text-center text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                <span class="block text-white">&nbsp;</span>
                <span class="block text-white">&nbsp;</span>
              </h1>
              <p class="mt-6 max-w-lg mx-auto text-center text-xl text-indigo-200 sm:max-w-3xl">
                &nbsp;
              </p>
              <div class="mt-10 max-w-sm mx-auto sm:max-w-none sm:flex sm:justify-center">
                <div class="space-y-4 sm:space-y-0 sm:mx-auto ">
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

<div class="bg-white">
  <div class="max-w-7xl mx-auto py-16 px-4 sm:px-6 lg:py-24 lg:px-8">
    <div class="max-w-3xl mx-auto text-center">
      <h2 class="text-3xl font-extrabold text-blue-400 font-serif">Welcome to the Liberato Portal</h2>
      <p class="mt-4 text-lg text-gray-500">Dance as a source of wellbeing through online courses, workshops, private classes, retreats, books and articles.</p>
    </div>
    <dl class="mt-12 space-y-10 sm:space-y-0 sm:grid sm:grid-cols-2 sm:gap-x-6 sm:gap-y-12 lg:grid-cols-4 lg:gap-x-8">
      <div class="relative">
        <dt>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Liberato Method</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Experience Xandy Liberato's dance training method.</dd>
      </div>

      <div class="relative">
        <dt>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Retreats</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Concentrated study.</dd>
      </div>

      <div class="relative">
        <dt>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Zouk Training</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Classes tailored just for Zouk.</dd>
      </div>

      <div class="relative">
        <dt>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Mentorship</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Improve all aspects of yourself, not just dance.</dd>
      </div>
    </dl>
  </div>
</div>

<div class="py-12 text-blue-500">
	<div class="max-w-3xl mx-auto text-center mb-6">
      <h2 class="text-3xl font-extrabold text-blue-400 font-serif">Benefits of The Liberato Method</h2>
	  </div>
  <div class="max-w-xl mx-auto px-4 sm:px-6 lg:max-w-7xl lg:px-8">
    <h2 class="sr-only">Benefits</h2>
    <dl class="space-y-10 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-8">
      <div>
        <dt>
          <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased body awareness</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased self-confidence and confidence towards others</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">More empathetic communication</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">More conscious relationships</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Listening development</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">&nbsp;</dd>
      </div>

      <div>
        <dt>
		  <img src="https://xandyliberato.com/static_files/effects-of-liberato-700.png" alt="Effects of Liberato Method">
        </dt>
        <dd class="mt-2 text-base text-gray-500">&nbsp;</dd>
      </div>

      <div>
        <dt>
          <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Sensitivity development</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased enjoyment ability</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Experiences of freedom</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Changes in the way of dancing and living</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased social abilities</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Change of mindset</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">&nbsp;</dd>
      </div>
    </dl>
  </div>
</div>

<section class="bg-gray-600 h-auto">
	<div class="max-w-3xl mx-auto text-center mb-6 py-12">
      <h2 class="text-3xl font-extrabold text-white font-serif mb-6">Our Retreat</h2>

	<div class="relative">
      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 h-80">
        <iframe src="https://www.youtube.com/embed/BBYizZLqYkM" width="100%" height="100%" title="Xandy Liberato Retreats" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
      </div>
    </div>

	</div>
</section>

<!-- Events section -->
      <div class="relative bg-gray-50 py-16 sm:py-24 lg:py-32">
        <div class="relative">
          <div class="text-center mx-auto max-w-md px-4 sm:max-w-3xl sm:px-6 lg:px-8 lg:max-w-7xl">
            <p class="mt-2 text-3xl font-extrabold text-blue-400 font-serif sm:text-4xl">
              Our Next Events
            </p>
          </div>
          <div class="mt-12 mx-auto max-w-md px-4 grid gap-8 sm:max-w-lg sm:px-6 lg:px-8 lg:grid-cols-3 lg:max-w-7xl">
          </div>
        </div>
      </div>

<section class="bg-gray-600">
	<div class="max-w-3xl mx-auto text-center mb-6 pt-12">
      <h2 class="text-3xl font-extrabold text-white font-serif">Testimonials</h2>
	  </div>
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z"></path>
          </svg>
          <p class="relative">
            The retreat completely changed my life and my vision of the world. Now I feel so much silence and love. And I feel free in dance. My creativity and flow woke up. Thank you all!
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Daniel</div>
              <div class="text-base font-medium text-indigo-200">Retreat participant</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 lg:pl-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z"></path>
          </svg>
          <p class="relative">
            Me siento muy conmovida por las personas increíbles que forman parte del personal, todo el mundo. Me sentí SEGURA y eso significa mucho para mí.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Angelique</div>
              <div class="text-base font-medium text-indigo-200">Student</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
  </div>
</section>

<section class="bg-gray-600">
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z"></path>
          </svg>
          <p class="relative">
           The retreat was a transformation point in my life. I feel like I am awake, refreshed, inspired, and filled with emotion. Thank you for the beautiful experience.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Lubna</div>
              <div class="text-base font-medium text-indigo-200">Retreat Participant</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 md:border-l lg:pl-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z"></path>
          </svg>
          <p class="relative">
            Sinto-me como se as portas que não sabia que existiam tivessem se aberto. Ainda não sei bem o que isso significa, mas sinto que a minha vida mudou para melhor. Este foi um evento que mudou a minha vida, e não tenho palavras para dizer o quanto sou grato por ter feito parte disso. Obrigado!
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Christoffer</div>
              <div class="text-base font-medium text-indigo-200">Student</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
  </div>
</section>

		<?php

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
