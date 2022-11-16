<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	$logic_path = LibraryFunctions::get_logic_file_path('schedule-meeting.php');
	require_once ($logic_path);

	$session = SessionControl::get_instance();

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Homepage',
	);
	$page->public_header($hoptions);

	echo PublicPageTW::BeginPage('');
	
	$formwriter = new FormWriterMasterTW('form1');
	
	?>
	

<!-- Blog section -->
      <div class="relative bg-gray-50 py-16 sm:py-24 lg:py-32">
        <div class="relative">
          <div class="text-center mx-auto max-w-md px-4 sm:max-w-3xl sm:px-6 lg:px-8 lg:max-w-7xl">
            <!--<h2 class="text-base font-semibold tracking-wider text-cyan-600 uppercase">Learn</h2>-->
            <p class="mt-2 text-3xl font-extrabold text-gray-900 tracking-tight sm:text-4xl">
              Integral Zen One-on-one Meetings
            </p>
            <p class="mt-5 mx-auto max-w-prose text-xl text-gray-500">
			One-on-one meetings are given free-of-charge for the benefit of all beings.  However, Integral Zen does rely on the generous support of our community, so <a href="/dana">please consider making a donation</a> to support this important work. 
            </p>
          </div>
          <div class="mt-12 mx-auto max-w-md px-4 grid gap-8 sm:max-w-lg sm:px-6 lg:px-8 lg:grid-cols-3 lg:max-w-7xl">
            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="https://integralzen.org/uploads/medium/shadow_session_9agjna3.jpg" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Roshi
                    </a>
                  </p>
                  <a href="#" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Pre-arranged meeting with Doshin
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      Meetings with Doshin are all pre-arranged and by invitation only.

						If you have been invited to make an appointment...
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                  			<?php 
							if($replace_values['doshin_appt'] == 'yes'){
								echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/?appointmentType=category:Session%20with%20Doshin');
							}
							else if($replace_values['doshin_appt'] == 'login'){
								echo '<p>Please <a href="/login">log in</a> to book a session with Doshin.</p>';
							}
							else if($replace_values['doshin_appt'] == 'no'){
								echo '<p>Meetings with Doshin are available to existing students only.</p>';
							}							
							?> 
                </div>
              </div>
            </div>

            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="/uploads/medium/kodo_nhv8mskf.jpg" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Lay Teacher
                    </a>
                  </p>
                  <a href="https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Introductory Meeting [1 hour]
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      If you are new to Integral Zen and this is your first time scheduling an appointment, choose this option. This is your opportunity to ask any questions you may have about your meditation, Integral Zen, or Integral Awakening: Waking Up, Growing Up, Fucking Up, Cleaning Up and Showing Up as an awake, compassionate, whole and healthy human being.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                  <?php echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers'); ?>
                </div>
              </div>
            </div>

            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="/uploads/medium/kodo3_a9w3xq9f.JPG" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Lay Teacher
                    </a>
                  </p>
                  <a href="https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      One on One [1 hour]
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      This is a private meeting between a Zen student and an Integral Zen lay teacher or teacher in training. The intention is to advise students and answer questions about their practice. If you are a returning student wanting to check in about your practice you should choose this option. Questions should be limited to your Integral meditation practice, which includes: Waking up, Growing up, Fucking up, Cleaning up and Showing up as a fully awake, compassionate, healthy and whole human being.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                                    <?php echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers'); ?>
                </div>
              </div>
            </div>
			
			
            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="/uploads/medium/Mondo-Zen-UK-Retreat-1-1024x577.jpg" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Lay Teacher
                    </a>
                  </p>
                  <a href="https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Awareness Intervention [1-2 hour] (by Invitation Only)
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                      This is a meeting between a Zen student and an Integral Zen lay teacher or teacher in training. The intention is to guide students with language into an experience of “wu.” What is wu? Wu is a Chinese word, Japanese is “mu”. It is difficult to translate into English. The best translation for our purposes of Integral Awakening is “nothingness.” It is ironic that a direct experience of nothingness, the emptiness that isn’t empty but awake, can change your life.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                                    <?php echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/schedule.php?appointmentType=category%3AIntegral+Zen+Lay+Teachers'); ?>
                </div>
              </div>
            </div>


            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="/uploads/medium/Janel_True_Face_Activity-23_p9828a1.JPEG" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Apprentice
                    </a>
                  </p>
                  <a href="mailto:integralzenjanel@gmail.com" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Touching the True Face Session [1 hour]
                    </p>
                    <p class="mt-3 text-base text-gray-500">
                       Continuing in the footsteps of his teacher JunPo Roshi, Doshin has begun teaching a small group of apprentices what he calls the practice of “Touching the True Face.” JunPo used to say: “Your angst is your liberation.” Doshin extended this teaching and says: “Your conflicts can liberate you.” Your very conflicts can themselves be a Dharma gate that opens to a direct experience of awakening to the realization of no-self and the embodiment of pure selfless awakened mind. If you are interested in experiencing “Touching the True Face” for yourself please contact us at integralzenjanel@gmail.com.
                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                                    <?php echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/?appointmentType=33836571'); ?>
                </div>
              </div>
            </div>			
			

            <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
              <div class="flex-shrink-0">
                <img class="h-48 w-full object-cover" src="/uploads/small/choan_na5w4n9q.jpg" alt="">
              </div>
              <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                <div class="flex-1">
                  <p class="text-sm font-medium text-cyan-600">
                    <a href="#" class="hover:underline">
                      Doshin, Choan, Janel
                    </a>
                  </p>
                  <a href="https://integralzen.as.me/?appointmentType=38855090" class="block mt-2">
                    <p class="text-xl font-semibold text-gray-900">
                      Transmuting Poisonous Emotions into Healing Medicines [1 hour]
                    </p>
                    <p class="mt-3 text-base text-gray-500">
The Sanskrit word kleshas, often translated into English as: poisons, negative emotions, vexations, afflictions, defilements, disturbing emotions, mind poisons, neurosis, and unwholesome roots.The very roots of  samsaric existence, these poisons are conscious and unconscious biases for and against specific things representing the conflicts of dualistic little mind, that can be discerned and witnessed by nondual big mind. It is possible to cultivate skillful means to transmute these poisons into medicine.


                    </p>
                  </a>
                </div>
                <div class="mt-6 flex items-center">
                                    <?php echo $formwriter->new_button('Schedule a Meeting', 'https://integralzen.as.me/?appointmentType=38855090'); ?>
                </div>
              </div>
            </div>		
		
			
          </div>
        </div>
      </div>





	


		<?php

	echo PublicPageTW::EndPage();

	$page->public_footer(array('track'=>TRUE));
?>
