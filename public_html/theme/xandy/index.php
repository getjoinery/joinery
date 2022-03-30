<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');
	
	require_once (LibraryFunctions::get_logic_file_path('events_logic.php'));

	$session = SessionControl::get_instance();

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPageTW::BeginPage('');
	
	?>
	
     <!-- Hero card -->
      <div class="relative">
        <div class="absolute inset-x-0 bottom-0 h-1/2"></div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div class="relative shadow-xl sm:rounded-2xl sm:overflow-hidden">
            <div class="absolute inset-0">
              <img class="h-full w-full object-cover" src="https://xandyliberato.com/static_files/Home1.png" alt="People working on laptops">
             
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
                  <!--<a href="/events" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-indigo-700 bg-white hover:bg-indigo-50 sm:px-8">
                    See Courses
                  </a>-->
                  <!--<a href="#" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-500 bg-opacity-60 hover:bg-opacity-70 sm:px-8">
                    Live demo
                  </a>-->
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
          <svg xmlns="http://www.w3.org/2000/svg" class="absolute h-6 w-6 text-rose-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
</svg>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Liberato Method</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Experience Xandy Liberato's dance training method.</dd>
      </div>

      <div class="relative">
        <dt>
          <svg xmlns="http://www.w3.org/2000/svg" class="absolute h-6 w-6 text-rose-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path d="M12 14l9-5-9-5-9 5 9 5z" />
  <path d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
</svg>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Retreats</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Concentrated study.</dd>
      </div>

      <div class="relative">
        <dt>
		  
          <svg xmlns="http://www.w3.org/2000/svg" class="absolute h-6 w-6 text-rose-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
</svg>
          <p class="ml-9 text-lg leading-6 font-medium text-blue-400 font-serif">Zouk Training</p>
        </dt>
        <dd class="mt-2 ml-9 text-base text-gray-500">Classes tailored just for Zouk.</dd>
      </div>

      <div class="relative">
        <dt>
          <svg xmlns="http://www.w3.org/2000/svg" class="absolute h-6 w-6 text-rose-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
</svg>
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
    <h2 class="sr-only">Recorded Classes</h2>
    <dl class="space-y-10 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-8">
      <div>
        <dt>
          <!--<div class="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white">
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
            </svg>
          </div>-->
          <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased body awareness</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased self-confidence and confidence towards others</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">More empathetic communication</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">More conscious relationships</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Listening development</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          &nbsp;
        </dd>
      </div>

      <div>
        <dt>
          <!--<div class="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white">
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
            </svg>
          </div>-->
		  <img src="https://xandyliberato.com/static_files/effects-of-liberato-700.png">
          <!--<p class="mt-5 text-lg leading-6 font-medium text-gray-900">Specificity without Dogma ✨</p>-->
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          &nbsp;
        </dd>
      </div>

      <div>
        <dt>
          <!--<div class="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white">
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>-->
          <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Sensitivity development</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased enjoyment ability</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Experiences of freedom</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Changes in the way of dancing and living</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">Increased social abilities</p>
		  <p class="mt-5 text-lg leading-6 font-medium text-blue-400 font-serif">	
Change of mindset</p>
        </dt>
        <dd class="mt-2 text-base text-gray-500">
          &nbsp;
        </dd>
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
    
								<!--<a href="#" data-toggle="modal" data-target="#videoModal">-->
  
	</div>

</section>	

	
	
	
	

<!-- Blog section -->
      <div class="relative bg-gray-50 py-16 sm:py-24 lg:py-32">
        <div class="relative">
          <div class="text-center mx-auto max-w-md px-4 sm:max-w-3xl sm:px-6 lg:px-8 lg:max-w-7xl">
            <!--<h2 class="text-base font-semibold tracking-wider text-cyan-600 uppercase">Learn</h2>-->
            <p class="mt-2 text-3xl font-extrabold text-blue-400 font-serif sm:text-4xl">
              Our Next Events
            </p>
            <!--<p class="mt-5 mx-auto max-w-prose text-xl text-gray-500">
			
            </p>-->
          </div>
          <div class="mt-12 mx-auto max-w-md px-4 grid gap-8 sm:max-w-lg sm:px-6 lg:px-8 lg:grid-cols-3 lg:max-w-7xl">
			<?php
			$numdisplayed = 0;
			foreach ($events as $event){
				$now = LibraryFunctions::get_current_time_obj('UTC');
				$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
				$numdisplayed++;
				if($numdisplayed == 4){
					break;
				}		  
				?>
		  
				<div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
				  <div class="flex-shrink-0">
				  <?php
					if($pic = $event->get_picture_link('small')){
						echo '<img class="h-48 w-full object-cover" src="'.$pic.'" alt="">';
					}
					?>
				  </div>
				  <div class="flex-1 bg-white p-6 flex flex-col justify-between">
					<div class="flex-1">
					  <!--<p class="text-sm font-medium text-cyan-600">
						<a href="<?php echo $event->get_url(); ?>" class="hover:underline">
						  Online Course
						</a>
					  </p>-->
					  <a href="<?php echo $event->get_url(); ?>" class="block mt-2">
						<p class="text-xl font-semibold text-gray-900">
						  <?php echo $event->get('evt_name'); ?>
						</p>
						<p class="mt-3 text-base text-gray-500">
						  <?php echo $event->get('evt_short_description'); ?>
						</p>
					  </a>
					</div>
					<div class="mt-6 flex items-center">
					  <div class="flex-shrink-0">
						<!--<a href="#">
						  <img class="h-10 w-10 rounded-full" src="https://integralzen.org/uploads/small/doshin-mic.jpg" alt="Roel Aufderehar">
						</a>-->
					  </div>
					  <!--<div class="ml-3">
						<p class="text-sm font-medium text-gray-900">
						  <a href="#" class="hover:underline">
							Roel Aufderehar
						  </a>
						</p>
						<div class="flex space-x-1 text-sm text-gray-500">
						  <time datetime="2020-03-16">
							Mar 16, 2020
						  </time>
						  <span aria-hidden="true">
							&middot;
						  </span>
						  <span>
							6 min read
						  </span>
						</div>
					  </div>-->
					</div>
				  </div>
				</div>
					<?php
					}
					?>
          </div>
        </div>
      </div>



<section class="bg-gray-600">
	<div class="max-w-3xl mx-auto text-center mb-6 pt-12">
      <h2 class="text-3xl font-extrabold text-white font-serif">Testimonials</h2>
	  </div>
  <div class="max-w-7xl mx-auto md:grid md:grid-cols-2 md:px-6 lg:px-8">
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10  lg:pr-16">
      <!--<div class="md:flex-shrink-0">
        <img class="h-12" src="https://tailwindui.com/img/logos/tuple-logo-indigo-300.svg" alt="Tuple">
      </div>-->
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            The retreat completely changed my life and my vision of the world. Now I feel so much silence and love. And I feel free in dance. My creativity and flow woke up. Thank you all!
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <!--<div class="flex-shrink-0 inline-flex rounded-full border-2 border-white">
              <img class="h-12 w-12 rounded-full" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
            </div>-->
            <div class="ml-4">
              <div class="text-base font-medium text-white">Daniel</div>
              <div class="text-base font-medium text-indigo-200">Retreat participant</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0  lg:pl-16">
      <!--<div class="md:flex-shrink-0">
        <img class="h-12" src="https://tailwindui.com/img/logos/workcation-logo-indigo-300.svg" alt="Workcation">
      </div>-->
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            Me siento muy conmovida por las personas increíbles que forman parte del personal, todo el mundo. Me sentí SEGURA y eso significa mucho para mí.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <!--<div class="flex-shrink-0 inline-flex rounded-full border-2 border-white">
              <img class="h-12 w-12 rounded-full" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
            </div>-->
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
    <div class="py-12 px-4 sm:px-6 md:flex md:flex-col md:py-16 md:pl-0 md:pr-10  lg:pr-16">
      <!--<div class="md:flex-shrink-0">
        <img class="h-12" src="https://tailwindui.com/img/logos/tuple-logo-indigo-300.svg" alt="Tuple">
      </div>-->
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
           The retreat was a transformation point in my life. I feel like I am awake, refreshed, inspired, and filled with emotion. Thank you for the beautiful experience.
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
            <!--<div class="flex-shrink-0 inline-flex rounded-full border-2 border-white">
              <img class="h-12 w-12 rounded-full" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
            </div>-->
            <div class="ml-4">
              <div class="text-base font-medium text-white">Lubna</div>
              <div class="text-base font-medium text-indigo-200">Retreat Participant</div>
            </div>
          </div>
        </footer>
      </blockquote>
    </div>
    <div class="py-12 px-4 border-t-2 border-indigo-900 sm:px-6 md:py-16 md:pr-0 md:pl-10 md:border-t-0  lg:pl-16">
      <!--<div class="md:flex-shrink-0">
        <img class="h-12" src="https://tailwindui.com/img/logos/workcation-logo-indigo-300.svg" alt="Workcation">
      </div>-->
      <blockquote class="mt-6 md:flex-grow md:flex md:flex-col">
        <div class="relative text-lg font-medium text-white md:flex-grow">
          <svg class="absolute top-0 left-0 transform -translate-x-3 -translate-y-2 h-8 w-8 text-indigo-600" fill="currentColor" viewBox="0 0 32 32">
            <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36c0 5.088 3.072 8.064 6.624 8.064 3.36 0 5.856-2.688 5.856-5.856 0-3.168-2.208-5.472-5.088-5.472-.576 0-1.344.096-1.536.192.48-3.264 3.552-7.104 6.624-9.024L9.352 4zm16.512 0c-4.8 3.456-8.256 9.12-8.256 15.36 0 5.088 3.072 8.064 6.624 8.064 3.264 0 5.856-2.688 5.856-5.856 0-3.168-2.304-5.472-5.184-5.472-.576 0-1.248.096-1.44.192.48-3.264 3.456-7.104 6.528-9.024L25.864 4z" />
          </svg>
          <p class="relative">
            Sinto-me como se as portas que não sabia que existiam tivessem se aberto. Ainda não sei bem o que isso significa, mas sinto que a minha vida mudou para melhor. Este foi um evento que mudou a minha vida, e não tenho palavras para dizer o quanto sou grato por ter feito parte disso. Obrigado!
          </p>
        </div>
        <footer class="mt-8">
          <div class="flex items-start">
           <!-- <div class="flex-shrink-0 inline-flex rounded-full border-2 border-white">
              <img class="h-12 w-12 rounded-full" src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
            </div>-->
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

	echo PublicPageTW::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
