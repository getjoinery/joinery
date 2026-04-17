<?php
/**
 * Specialties Section Component
 *
 * Four-column grid of specialties/features with icons, titles, and descriptions.
 * Copied from empoweredhealthtn.com
 */

$heading = $component_config['heading'] ?? 'Our Specialties';
$subheading = $component_config['subheading'] ?? '';
$specialties = $component_config['specialties'] ?? [];

// Default specialties if none configured
if (empty($specialties)) {
    $specialties = [
        [
            'icon' => 'lnr-rocket',
            'title' => 'Telemedicine/Mobile Appointments',
            'description' => 'Empowered Health can be your primary care provider, with easy online appointments and mobile in-person visits.',
            'url' => '#'
        ],
        [
            'icon' => 'lnr-heart',
            'title' => 'Chronic Health Management',
            'description' => "We don't just prescribe pills and hope things get better. We work with you and our partners to manage all of the causes of your condition.",
            'url' => '#'
        ],
        [
            'icon' => 'lnr-bug',
            'title' => 'Stress Management',
            'description' => 'Stress is an underappreciated source of many health problems. We have special training in many stress-management modalities.',
            'url' => '#'
        ],
        [
            'icon' => 'lnr-users',
            'title' => 'Holistic Health Plans',
            'description' => 'Get the best of western medicine, combined with the latest in nutrition, stress management, massage, fitness, and counseling.',
            'url' => '#'
        ]
    ];
}
?>

<section class="facilities-area section-gap">
    <div class="container">
        <div class="row d-flex justify-content-center">
            <div class="menu-content pb-70 col-lg-7">
                <div class="title text-center">
                    <h1 class="mb-10"><?= htmlspecialchars($heading) ?></h1>
                    <?php if ($subheading): ?>
                    <p><?= htmlspecialchars($subheading) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="row">
            <?php foreach ($specialties as $specialty): ?>
            <div class="col-lg-3 col-md-6">
                <div class="single-facilities">
                    <?php if (!empty($specialty['icon'])): ?>
                    <span class="lnr <?= htmlspecialchars($specialty['icon']) ?>"></span>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($specialty['url'] ?? '#') ?>"><h4><?= htmlspecialchars($specialty['title'] ?? '') ?></h4></a>
                    <p>
                        <?= htmlspecialchars($specialty['description'] ?? '') ?>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
