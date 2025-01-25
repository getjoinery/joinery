<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

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
        <div class="absolute inset-x-0 bottom-0 h-1/2 bg-gray-100"></div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div class="relative shadow-xl sm:rounded-2xl sm:overflow-hidden">
            <div class="absolute inset-0">
              <img class="h-full w-full object-cover" src="https://images.unsplash.com/photo-1521737852567-6949f3f9f2b5?ixid=MXwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHw%3D&ixlib=rb-1.2.1&auto=format&fit=crop&w=2830&q=80&sat=-100" alt="People working on laptops">
              <div class="absolute inset-0 bg-gray-400 mix-blend-multiply"></div>
            </div>
            <div class="relative px-4 py-16 sm:px-6 sm:py-24 lg:py-32 lg:px-8">
              <h1 class="text-center text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                <span class="block text-white">A great business</span>
                <!--<span class="block text-indigo-200">customer support</span>-->
              </h1>
              <p class="mt-6 max-w-lg mx-auto text-center text-xl text-indigo-200 sm:max-w-3xl">Anim aute id magna aliqua ad ad non deserunt sunt. Qui irure qui lorem cupidatat commodo. Elit sunt amet fugiat veniam occaecat fugiat aliqua.</p>
              <div class="mt-10 max-w-sm mx-auto sm:max-w-none sm:flex sm:justify-center">
                <div class="space-y-4 sm:space-y-0 sm:mx-auto sm:inline-grid sm:grid-cols-2 sm:gap-5">
                  <a href="#" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-indigo-700 bg-white hover:bg-indigo-50 sm:px-8"> Get started </a>
                  <a href="#" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-500 bg-opacity-60 hover:bg-opacity-70 sm:px-8"> Live demo </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>	
	
	
	
	
	

<!-- Blog section -->
      <div class="relative bg-gray-50 py-16 sm:py-24 lg:py-32">
        <div class="relative">
          <div class="text-center mx-auto max-w-md px-4 sm:max-w-3xl sm:px-6 lg:px-8 lg:max-w-7xl">
            <!--<h2 class="text-base font-semibold tracking-wider text-cyan-600 uppercase">Learn</h2>-->
            <p class="mt-2 text-3xl font-extrabold text-gray-900 tracking-tight sm:text-4xl">
              Who are we?
            </p>
            <p class="mt-5 mx-auto max-w-prose text-xl text-gray-500">
			We do great stuff.  Here's a list of all the great stuff.
            </p>
          </div>
          <div class="mt-12 mx-auto max-w-md px-4 grid gap-8 sm:max-w-lg sm:px-6 lg:px-8 lg:grid-cols-3 lg:max-w-7xl">
            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="https://media.istockphoto.com/photos/hiking-picture-id493499361" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Online Course
                    </a>
                  </p>
                  <a href="/event/shadow-and-integral-theory-fundamentals-self-paced-course" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Promo 1
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      Lots of stuff about it.
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

            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="https://media.istockphoto.com/photos/hiking-picture-id493499361" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Live Online
                    </a>
                  </p>
                  <a href="/event/sunday-integral-dharma-calls" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Promo 2
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      Lots of stuff about it.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                  <div class="flex-shrink-0">
                    <!--<a href="#">
                      <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1550525811-e5869dd03032?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="Brenna Goyette">
                    </a>-->
                  </div>
                  <!--<div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">
                      <a href="#" class="hover:underline">
                        Brenna Goyette
                      </a>
                    </p>
                    <div class="flex space-x-1 text-sm text-gray-500">
                      <time datetime="2020-03-10">
                        Mar 10, 2020
                      </time>
                      <span aria-hidden="true">
                        &middot;
                      </span>
                      <span>
                        4 min read
                      </span>
                    </div>
                  </div>-->
                </div>
              </div>
            </div>

            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="https://media.istockphoto.com/photos/hiking-picture-id493499361" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Promo 3
                    </a>
                  </p>
                  <a href="/page/jun-po-roshi" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Promo 3
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      Lots of stuff about it.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                  <div class="flex-shrink-0">
                    <!--<a href="#">
                      <img class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="Daniela Metz">
                    </a>-->
                  </div>
                  <!--<div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">
                      <a href="#" class="hover:underline">
                        Daniela Metz
                      </a>
                    </p>
                    <div class="flex space-x-1 text-sm text-gray-500">
                      <time datetime="2020-02-12">
                        Feb 12, 2020
                      </time>
                      <span aria-hidden="true">
                        &middot;
                      </span>
                      <span>
                        11 min read
                      </span>
                    </div>
                  </div>-->
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>








	
	
	
	

<div class="bg-gray-800 mt-24">
  <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:py-16 lg:px-8 lg:flex lg:items-center">
    <div class="lg:w-0 lg:flex-1">
      <h2 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl" id="newsletter-headline">
        Sign Up For Updates
      </h2>
      <p class="mt-3 max-w-3xl text-lg leading-6 text-gray-300">
        We have an occasional newsletter of upcoming events and online courses.
      </p>
    </div>
    <div class="mt-8 lg:mt-0 lg:ml-8">
      <form class="sm:flex">
        <!--<label for="email-address" class="sr-only">Email address</label>
        <input id="email-address" name="email-address" type="email" autocomplete="email" required class="w-full px-5 py-3 border border-transparent placeholder-gray-500 focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-white focus:border-white sm:max-w-xs rounded-md" placeholder="Enter your email">-->
        <div class="mt-3 rounded-md shadow sm:mt-0 sm:ml-3 sm:flex-shrink-0">
          <a href="/newsletter"><button type="button" class="w-full flex items-center justify-center px-5 py-3 border border-transparent text-base font-medium rounded-md text-white bg-indigo-500 hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-indigo-500">
            Get the Newsletter
          </button></a>
        </div>
      </form>
      <!--<p class="mt-3 text-sm text-gray-300">
        We care about the protection of your data. Read our
        <a href="#" class="text-white font-medium underline">
          Privacy Policy.
        </a>
      </p>-->
    </div>
  </div>
</div>




	


		<?php

	echo PublicPageTW::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
