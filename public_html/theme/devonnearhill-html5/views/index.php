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
        <div class="absolute inset-x-0 bottom-0 h-1/2 bg-gray-100"></div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div class="relative shadow-xl sm:rounded-2xl sm:overflow-hidden">
            <div class="absolute inset-0">
              <img class="h-full w-full object-cover" src="/uploads/devonheroresized_j3mk9xlr.jpg" alt="Devon Near Hill">
            </div>
            <div class="relative px-4 py-16 sm:px-6 sm:py-24 lg:py-32 lg:px-8">
              <h1 class="text-center text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                <span class="block text-white">&nbsp;</span>
                <span class="block text-white">Online Brazilian Zouk Lambada</span>
              </h1>
              <p class="mt-6 max-w-lg mx-auto text-center text-xl text-indigo-200 sm:max-w-3xl">
                &nbsp;
              </p>
              <div class="mt-10 max-w-sm mx-auto sm:max-w-none sm:flex sm:justify-center">
                <div class="space-y-4 sm:space-y-0 sm:mx-auto ">
                  <a href="/events" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-indigo-700 bg-white hover:bg-indigo-50 sm:px-8">
                    See Courses
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

<div class="py-12 bg-white">
  <div class="max-w-xl mx-auto px-4 sm:px-6 lg:max-w-7xl lg:px-8">
    <h2 class="sr-only">Recorded Classes</h2>
    <dl class="space-y-10 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-8">
      <div>
        <dt>
          <p class="mt-5 text-lg leading-6 font-medium text-gray-900">Recorded Classes</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          Find it challenging to make it onto live zoom sessions or live in a dance wasteland? We've got you covered. All my courses are hi-def, succinctly edited, and vouched for by many!
        </dd>
      </div>

      <div>
        <dt>
          <p class="mt-5 text-lg leading-6 font-medium text-gray-900">Specificity without Dogma ✨</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          Frustrated with vague descriptions of "energy"? I like to get specific, detached of dogma, with vibrant imagery that shows you all possibilities informed by anatomy and physics.
        </dd>
      </div>

      <div>
        <dt>
          <p class="mt-5 text-lg leading-6 font-medium text-gray-900">Hilarious Delivery 🤣</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          I'm pretty funny - just ask my therapists and my students.
        </dd>
      </div>
    </dl>
  </div>
</div>

<div class="relative bg-white pt-16 pb-32 overflow-hidden">
  <div class="mt-6">
    <div class="lg:mx-auto lg:max-w-7xl lg:px-8 lg:grid lg:grid-cols-2 lg:grid-flow-col-dense lg:gap-24">
      <div class="px-4 max-w-xl mx-auto sm:px-6 lg:py-32 lg:max-w-none lg:mx-0 lg:px-0 lg:col-start-2">
        <div>
          <div class="mt-6">
            <h2 class="text-3xl font-extrabold tracking-tight text-gray-900">
              My focus is on quality of movement, healthy body awareness, comfort, and longevity.
            </h2>
            <div class="mt-6">
              <a href="/events" class="inline-flex px-4 py-2 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                Learn More
              </a>
            </div>
          </div>
        </div>
      </div>
      <div class="mt-12 sm:mt-16 lg:mt-0 lg:col-start-1">
        <div class="pr-4 -ml-48 sm:pr-6 md:-ml-16 lg:px-0 lg:m-0 lg:relative lg:h-full">
          <img class="w-full rounded-xl shadow-xl ring-1 ring-black ring-opacity-5 lg:absolute lg:right-0 lg:h-full lg:w-auto lg:max-w-none" src="https://devonnearhill.com/uploads/medium/Decon_zouk_5_x74ki5j.jpg" alt="Devon Near Hill dancing">
        </div>
      </div>
    </div>
  </div>
</div>

<section class="bg-gray-600">
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 md:border-r md:border-gray-900 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            Oh my god where do I even begin? Devon is, and always was, an awesome person and a great teacher. Every class makes me feel like I'm being taken gently by the hand, constantly soothed with humor and charm as I walk through the valley of the shadow of death, AKA Zouk head movements. She breaks it down so simply, using concepts from every day life and tips that were surely earned through years and years of experience as a dancer. She makes complex movements attainable for everyone who has the will to learn and practice. 10 out of 5 recommend.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Tom Lev</div>
              <div class="text-base font-medium text-indigo-200">Founder of Sotaki Dance School</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 md:border-l lg:pl-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            Devon Near-Hill is an amazing teacher and dancer! I really enjoyed her EFO course and look forward to continuing to learn from her. She is so genuine, which is something I value VERY highly in my teachers--fun, creative, and always brings joy out of her "mistakes." I love how brightly her personality and genuine spirit shine through even distance/online learning! Not to mention, she has years of experience with zouk and other dance forms, beautiful technique and flow, and such a bright energy. Thank you for the learning, feedback, and laughter you brought me during this time when I wasn't very inspired to dance or train❤️
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Rose Curtis</div>
              <div class="text-base font-medium text-indigo-200">Movement Therapist</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
  </div>
</section>

<section class="bg-gray-600">
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 md:border-r md:border-gray-900 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
           I wholeheartedly recommend The Efficient Follow Online – both for Devon in general as an instructor for Brazilian Zouk &amp; Lambada, and also for the "Remixing Head Movement- Level 3" online course I took! Devon is a genius when it comes to deconstructing head movement and her workshops &amp; course taught me a whole new way to think about, analyze, and practice head movement in a way that opened up so many new possibilities!! So grateful for the amount of thought and care she puts into her teaching.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Shane Rasnak</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 md:border-l lg:pl-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            I really enjoyed the warm up exercises and learning how to strengthen the corresponding muscle groups to prevent injuries. As someone with neck issues, you really made Zouk head movement feel accessible and possible. I didn't have any anxiety or worry that I would do anything to hurt myself during class which has been my experience in other workshops causing me to tense. I felt each movement and proper form in my body, which is a great foundational step. Additionally, each movement taught was broken down into digestible steps which made it feel seamless when the time came to try the full movement. Thank you!
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Samantha Feller</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
  </div>
</section>

<section class="bg-gray-600">
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 md:border-r md:border-gray-900 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
           There are a lot of visual illusions in zouk, some of the riskiest to emulate involving head movement. Devon's class is luxuriously paced, methodically laid out, and entertaining to watch and follow. It's perfect for people who want to learn it safely and thoroughly-which should be everyone, both leads and follows, who want to stay safe (and upright!) while incorporating this expressive vocabulary into their dance.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Larissa Archer</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 md:border-l lg:pl-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            I truly recommend anything that Devon does especially this class in particular. The amount of info provided in this class for one's needs and development when it comes to Zouk is truly invaluable. Definitely one of the best classes out there.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Efosa Uwa-Omede</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
  </div>
</section>

<section class="bg-gray-600">
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10 md:border-r md:border-gray-900 lg:pr-16">
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            Working with Devon has been both fun and highly effective. She is such a sweet and authentic person and is good at helping dancers make little tweaks that make a big difference in visual appeal and partner connection. I'm somewhat new to Zouk and Devon had great tips, tricks, and visualizations to help me get into the correct postures and frames that would look better, feel good to me and my partner, and help keep me safe. Ask her to show you how to offer libations to the gods and watch how your posture shifts! She gave me permission to try and mess up, which I apparently really needed. She creates a safe and playful space to move through hang-ups, find what works best for you and generally nerd out on dance. I really appreciate her awareness of how the the mental, emotional, and physical aspects of life and our experiences all contribute to our state of being and our dancing. I'm already excited for the next time she visits San Diego.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <div class="ml-4">
              <div class="text-base font-medium text-white">Vanessa Zimmerman</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0 md:border-l lg:pl-16">
    </div>
  </div>
</section>

<div class="bg-gray-800 mt-24">
  <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:py-16 lg:px-8 lg:flex lg:items-center">
    <div class="lg:w-0 lg:flex-1">
      <h2 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl" id="newsletter-headline">
        Want to book me for workshops?
      </h2>
      <p class="mt-3 max-w-3xl text-lg leading-6 text-gray-300">
        @devonsandrika / devonhasanemail@gmail.com
      </p>
    </div>
    <div class="mt-8 lg:mt-0 lg:ml-8">
      <form class="sm:flex">
        <div class="mt-3 rounded-md shadow sm:mt-0 sm:ml-3 sm:flex-shrink-0">
          <a href="mailto:devonhasanemail@gmail.com"><button type="button" class="w-full flex items-center justify-center px-5 py-3 border border-transparent text-base font-medium rounded-md text-white bg-indigo-500 hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-indigo-500">
            Get in Touch
          </button></a>
        </div>
      </form>
    </div>
  </div>
</div>

		<?php

	echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
