<?php
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('includes/PublicPageTW.php');
	ThemeHelper::includeThemeFile('includes/FormWriterPublicTW.php');

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
        <div class="absolute inset-x-0 bottom-0 h-1/2"></div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div class="relative shadow-xl sm:rounded-2xl sm:overflow-hidden">
            <div class="absolute inset-0">
              <img class="h-full w-full object-cover" src="https://integralzen.org/uploads/large/DOshin-5_tqjplrca.jpg" alt="Doshin Roshi">
             
            </div>
            <div class="relative px-4 py-16 sm:px-6 sm:py-24 lg:py-32 lg:px-8">
              <h1 class="text-center text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                <span class="block text-white">&nbsp;</span>
                <span class="block text-white">&nbsp;</span>
              </h1>
              <p class="mt-6 max-w-lg mx-auto text-center text-3xl text-white sm:max-w-3xl">
                "A shadow is the me I cannot see." - Doshin Roshi
              </p>
              <div class="mt-10 max-w-sm mx-auto sm:max-w-none sm:flex sm:justify-center">
                <div class="space-y-4 sm:space-y-0 sm:mx-auto ">
                  <a href="/events" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-indigo-700 bg-white hover:bg-indigo-50 sm:px-8">
                    See Courses
                  </a>
                  <!--<a href="#" class="flex items-center justify-center px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-500 bg-opacity-60 hover:bg-opacity-70 sm:px-8">
                    Live demo
                  </a>-->
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
              What is Integral Zen?
            </p>
            <p class="mt-5 mx-auto max-w-prose text-xl text-gray-500">
			We are about Waking Up, Growing Up, Cleaning Up, and Showing Up.
              We use the zazen, kinhin, and koans from traditional Rinzai Zen to wake up; we use Integral Theory as a map to help our ego grow up; and we use rigorous forms of individual and collective shadow work to clean up our misperceptions. The aim is to show up as an awake, compassionate, whole and healthy human being.
            </p>
          </div>
          <div class="mt-12 mx-auto max-w-md px-4 grid gap-8 sm:max-w-lg sm:px-6 lg:px-8 lg:grid-cols-3 lg:max-w-7xl">
            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="https://integralzen.org/uploads/small/What_color_is_your_shadow_flyer_1v2o63ut.jpg" alt="">
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
                      Shadow and Integral Theory Fundamentals Self-paced Course
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      This course is shorter and less intense than our "Whole Spectrum of Shadows" course.  It focuses on the basics of shadows in the context of integral theory.  

						In this course, we cover the nature of the ego and the formation of shadows, various tools we have to investigate and work with shadows, how to tell when we have shadows, and how shadows affect our relationship with ourselves and others.

						This online course is offered on a free/donation basis.
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
                <img class="h-48 w-full object-cover" src="https://integralzen.org/uploads/small/doshin-mic.jpg" alt="">
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
                      Live Dharma talks by Doshin
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      Happening every Sunday. Zen wisdom, deep conflicts, and a simple Integral framework to make sense of what is happening in the world.
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
                <img class="h-48 w-full object-cover" src="https://integralzen.org/uploads/doshinandjunpo_cropped_w2n7onl4.jpg" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      A Letter
                    </a>
                  </p>
                  <a href="/page/jun-po-roshi" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      In Memory of Jun Po
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      It is with deepest sadness and the greatest gratitude that I acknowledge JunPo’s Passing...
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
