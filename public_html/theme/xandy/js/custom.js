$(document).ready(function() {
	"use strict";
	var sliderTextMarginTop = ($(window).height() - ($("header").height() + $(".slider_text").height())) / 2;
	if (sliderTextMarginTop <= 50) {
		sliderTextMarginTop = 100;
	}
	if($(window).width()<=640)
	{
		sliderTextMarginTop=0;
	}
	$(".slider_text").css("margin-top", sliderTextMarginTop);
	$(window).resize(function() {
		sliderTextMarginTop = ($(window).height() - ($("header").height() + $(".slider_text").height())) / 2;
		if (sliderTextMarginTop <= 50) {
			sliderTextMarginTop = 100;
		}
	    if($(window).width()<=640)
		{
			sliderTextMarginTop=0;
		}
		$(".slider_text").css("margin-top", sliderTextMarginTop);
	});
	function centerModals() {
		$('.modal').each(function(i) {
			var $clone = $(this).clone().css('display', 'block').appendTo('body');
			var top = Math.round(($clone.height() - $clone.find('.modal-content').height()) / 2);
			top = top > 0 ? top : 0;
			$clone.remove();
			$(this).find('.modal-content').css("margin-top", top);
		});
	}
	$('.modal').on('show.bs.modal', centerModals);
	$(window).on('resize', centerModals);

	$('.slider').revolution({
		delay:9000,
		startwidth:960,
		startheight:700,
		startWithSlide:0,

		fullScreenAlignForce:"off",
		autoHeight:"off",
		minHeight:"off",

		shuffle:"off",

		onHoverStop:"on",

		thumbWidth:100,
		thumbHeight:50,
		thumbAmount:3,

		hideThumbsOnMobile:"off",
		hideNavDelayOnMobile:1500,
		hideBulletsOnMobile:"off",
		hideArrowsOnMobile:"off",
		hideThumbsUnderResoluition:0,

		hideThumbs:0,
		hideTimerBar:"off",

		keyboardNavigation:"on",

		navigationType:"bullet",
		navigationArrows:"solo",
		navigationStyle:"round",

		navigationHAlign:"center",
		navigationVAlign:"bottom",
		navigationHOffset:30,
		navigationVOffset:30,

		soloArrowLeftHalign:"left",
		soloArrowLeftValign:"center",
		soloArrowLeftHOffset:20,
		soloArrowLeftVOffset:0,

		soloArrowRightHalign:"right",
		soloArrowRightValign:"center",
		soloArrowRightHOffset:20,
		soloArrowRightVOffset:0,


		touchenabled:"on",
		swipe_velocity:"0.7",
		swipe_max_touches:"1",
		swipe_min_touches:"1",
		drag_block_vertical:"false",

		parallax:"mouse",
		parallaxBgFreeze:"on",
		parallaxLevels:[10,7,4,3,2,5,4,3,2,1],
		parallaxDisableOnMobile:"off",

		stopAtSlide:-1,
		stopAfterLoops:-1,
		hideCaptionAtLimit:0,
		hideAllCaptionAtLilmit:0,
		hideSliderAtLimit:0,

		dottedOverlay:"none",

		spinned:"spinner4",

		fullWidth:"off",
		forceFullWidth:"off",
		fullScreen:"off",
		fullScreenOffsetContainer:"#topheader-to-offset",
		fullScreenOffset:"0px",

		panZoomDisableOnMobile:"off",

		simplifyAll:"off",

		shadow:0

	});

	/******** Configured Wow Js for animation at time of loading *****/
    var wow = new WOW(
      {
        animateClass: 'animated',
        offset:       100,
        callback:     function(box) {
          console.log("WOW: animating <" + box.tagName.toLowerCase() + ">")
        }
      }
    );
    wow.init();

	/******** Configured Isotope Js for sortable gallery *****/
    var $grid = $('.grid').isotope({
		// options
		itemSelector: '.grid-item',
		layoutMode: 'fitRows',
		// layout mode options
		masonry: {
			columnWidth: 200
		}
	});

	var $grid2 = $('.grid2').isotope({
		// options
		itemSelector: '.grid-item2',
		layoutMode: 'fitRows',
		// layout mode options
		masonry: {
			columnWidth: 330
		}
	});

	// filter items on button click
	$('.filter-button-group').on( 'click', 'button', function() {
		$(".gallery-btn").removeClass("active");
		$(this).addClass("active");
	  var filterValue = $(this).attr('data-filter');
	  $grid.isotope({ 
	  	filter: filterValue, });
	});

	// filter items on button click
	$('.filter-button-group2').on( 'click', 'button', function() {
		$(".gallery-btn").removeClass("active");
		$(this).addClass("active");
	  var filterValue = $(this).attr('data-filter');
	  $grid2.isotope({ 
	  	filter: filterValue, });
	});

	/******** Configured Count Down Timer *****/
	$('[data-countdown]').each(function() {
		var $this = $(this), 
		finalDate = $(this).data('countdown');
		$this.countdown(finalDate, function(event) {
			$(".event-remain-days").html(event.strftime('%D'));
			$(".event-remain-hours").html(event.strftime('%H'));
			$(".event-remain-minutes").html(event.strftime('%M'));
			$(".event-remain-seconds").html(event.strftime('%S'));
		});
	});
	$(".testimonial-carousel").owlCarousel({
	    loop:true,
	    margin:30,
	    responsiveClass:true,
	    navText : ["<i class='fa fa-chevron-left'></i>","<i class='fa fa-chevron-right'></i>"],
	    responsive:{
	        0:{
	            items:1,
	            nav:true
	        },
	        600:{
	            items:3,
	            nav:false
	        },
	        1000:{
	            items:3,
	            nav:true,
	            loop:false
	        }
	    }
	});
	$(window).on("scroll", function() {
		if($(window).scrollTop() == 0) {
			$(".menu-style-1").removeClass("scroll");
		} else {
			$(".menu-style-1").addClass("scroll");
		}
	});

	$(".panel-heading").on("click",function(){
		$(".panel-heading").find(".collapsed").find("i").removeClass("fa-minus");
		$(".panel-heading").find(".collapsed").find("i").addClass("fa-plus");
		$(this).find("i").toggleClass("fa-minus");
		$(this).find("i").toggleClass("fa-plus");
	});
}); 