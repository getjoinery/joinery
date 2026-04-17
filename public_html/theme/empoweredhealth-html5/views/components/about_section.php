<?php
/**
 * About Section with Letter Component
 *
 * Two-column about section with content on the left and a letter/testimonial box on the right.
 * Copied from empoweredhealthtn.com
 */

$heading = $component_config['heading'] ?? 'About Empowered Health';
$intro_paragraph = $component_config['intro_paragraph'] ?? '';
$main_content = $component_config['main_content'] ?? '';
$list_intro = $component_config['list_intro'] ?? '';
$list_items = $component_config['list_items'] ?? [];
$button_text = $component_config['button_text'] ?? 'Contact to Book';
$button_url = $component_config['button_url'] ?? '/contact';
$letter_title = $component_config['letter_title'] ?? 'Letter from Heath';
$letter_content = $component_config['letter_content'] ?? '';
$letter_signature = $component_config['letter_signature'] ?? '';

// Default list items if none configured
if (empty($list_items)) {
    $list_items = [
        ['item' => 'longer appointments'],
        ['item' => 'personalized service that takes all of you into account'],
        ['item' => 'visits when you need them'],
        ['item' => 'annual health improvement: goal-setting and planning'],
        ['item' => 'and lots more...']
    ];
}

// Default letter content if not configured
if (empty($letter_content)) {
    $letter_content = "As a nurse practitioner for over a decade, I see two things wrong with modern medicine: strict time limits mean visits where the patient cannot be heard, and the idea of just taking more pills to fix our problems.\n\nI created Empowered Health for those of us who want to treat the underlying causes of our illness. During our longer appointments, we will talk about a range of options, with western medicine being only one of them. We will design a plan together to meet your health goals and then coach and modify the plan as needed. On your journey to great health, you will learn to listen to your body and mind, hence the name: Empowered Health.";
}
?>

<section class="appointment-area">
    <div class="container">
        <div class="row justify-content-between align-items-center pb-120 appointment-wrap">
            <div class="col-lg-5 col-md-6 appointment-left mt-30">
                <h1>
                    <?= htmlspecialchars($heading) ?>
                </h1>

                <?php if ($intro_paragraph): ?>
                <p>
                    <?= htmlspecialchars($intro_paragraph) ?>
                </p>
                <?php endif; ?>

                <?php if ($main_content): ?>
                <p>
                    <?= nl2br(htmlspecialchars($main_content)) ?>
                </p>
                <?php endif; ?>

                <?php if ($list_intro): ?>
                <p>
                    <?= htmlspecialchars($list_intro) ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($list_items)): ?>
                <ul class="unordered-list">
                    <?php foreach ($list_items as $item): ?>
                    <li><?= htmlspecialchars($item['item'] ?? '') ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <p></p>
                <?php if ($button_text): ?>
                <a href="<?= htmlspecialchars($button_url) ?>" class="primary-btn text-uppercase"><?= htmlspecialchars($button_text) ?></a>
                <?php endif; ?>

            </div>
            <div class="col-lg-6 col-md-6 appointment-right pt-60 pb-60 mb-30">
                <h3 class="pb-20 text-center mb-30"><?= htmlspecialchars($letter_title) ?></h3>
                <div class="pb-30 pr-30 pl-30">
                    <?php
                    $paragraphs = explode("\n\n", $letter_content);
                    foreach ($paragraphs as $paragraph):
                        $paragraph = trim($paragraph);
                        if ($paragraph):
                    ?>
                    <p><?= nl2br(htmlspecialchars($paragraph)) ?></p>
                    <?php
                        endif;
                    endforeach;
                    ?>

                    <?php if ($letter_signature): ?>
                    <p>
                        Sincerely,<br>
                        <strong><?= htmlspecialchars($letter_signature) ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
