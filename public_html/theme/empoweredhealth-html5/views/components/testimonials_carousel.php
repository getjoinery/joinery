<?php
/**
 * Testimonials Carousel Component
 *
 * Full-width section with green overlay background and owl carousel testimonials.
 * Copied from empoweredhealthtn.com
 */

$heading = $component_config['heading'] ?? 'Hear from our happy patients';
$subheading = $component_config['subheading'] ?? '';
$testimonials = $component_config['testimonials'] ?? [];

// Default testimonials if none configured
if (empty($testimonials)) {
    $testimonials = [
        [
            'name' => 'Rico H.',
            'location' => 'Knoxville',
            'rating' => '5',
            'content' => "Heath is the best Nurse Practitioner in the Knoxville area! I had a procedure done around the holidays last year that made me feel uneasy going into. It also didn't help that I was new to the area but Heath made me feel right at home. From the pre-op to the post- op he was there to guide me every step of the way. If you are looking for someone who is an erudite and who cares about the well being of patients, Heath Tunnell is the N.P. I would recommend."
        ],
        [
            'name' => 'Caylor T.',
            'location' => 'Knoxville',
            'rating' => '5',
            'content' => "Heath has been an excellent healthcare provider. His options for treatment have always been cost effective and tailored specifically to my needs. He has always provided me with multiple treatment options and has guided me to the best solution. I highly recommend Heath and will continue to come to him."
        ],
        [
            'name' => 'Tildy S.',
            'location' => 'Online Patient',
            'rating' => '5',
            'content' => "Heath was super helpful while I had coronavirus the last couple weeks. Every time a symptom arose or changed he talked it through with me and explained what was going on and what I could do to help my body heal, including advice like body positions that helped keep my lungs healthy, what to eat to lessen the inflammation and help breathing, and which medications to consider taking, and those to avoid.\n\nHis experience and knowledge makes his advice trustworthy and also calming because I knew he'd seen all my symptoms loads of times before and often way way more severe, so I felt in good hands. It was really important to speak to someone I trusted because stress and panic make the symptoms worse, and Heath was always there when I was freaked out that my symptoms were getting worse.\n\nNow I'm almost fully recovered and I'm really grateful.\n\nThanks Heath!"
        ]
    ];
}
?>

<section class="feedback-area section-gap relative">
    <div class="overlay overlay-bg"></div>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12 pb-60 header-text text-center">
                <h1 class="mb-10 text-white"><?= htmlspecialchars($heading) ?></h1>
                <?php if ($subheading): ?>
                <p class="text-white">
                    <?= htmlspecialchars($subheading) ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <div class="row feedback-contents justify-content-center align-items-center">
            <div class="col-lg-6 feedback-left relative d-flex justify-content-center align-items-center">
            </div>
            <div class="col-lg-6 feedback-right">
                <div class="active-review-carusel owl-carousel owl-theme">
                    <?php foreach ($testimonials as $testimonial): ?>
                    <div class="single-feedback-carusel">
                        <div class="title d-flex flex-row">
                            <h4 class="text-white pb-10"><?= htmlspecialchars($testimonial['name'] ?? '') ?><?php if (!empty($testimonial['location'])): ?> - <?= htmlspecialchars($testimonial['location']) ?><?php endif; ?></h4>
                            <div class="star">
                                <?php
                                $rating = intval($testimonial['rating'] ?? 5);
                                for ($i = 0; $i < $rating; $i++):
                                ?>
                                <span class="fa fa-star checked"></span>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <?php
                        $content = $testimonial['content'] ?? '';
                        $paragraphs = explode("\n\n", $content);
                        foreach ($paragraphs as $paragraph):
                            $paragraph = trim($paragraph);
                            if ($paragraph):
                        ?>
                        <p class="text-white">
                            <?= nl2br(htmlspecialchars($paragraph)) ?>
                        </p>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
