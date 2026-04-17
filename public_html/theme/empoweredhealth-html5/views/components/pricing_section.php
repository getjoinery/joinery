<?php
/**
 * Pricing Section with Services Sidebar Component
 *
 * Dark background section with pricing cards on the left and a services sidebar on the right.
 * Copied from empoweredhealthtn.com
 */

$heading = $component_config['heading'] ?? 'Book a Visit';
$pricing_cards = $component_config['pricing_cards'] ?? [];
$sidebar_title = $component_config['sidebar_title'] ?? 'Services';
$services = $component_config['services'] ?? [];
$sidebar_link_text = $component_config['sidebar_link_text'] ?? 'Read more about our services';
$sidebar_link_url = $component_config['sidebar_link_url'] ?? '#';

// Default pricing cards if none configured
if (empty($pricing_cards)) {
    $pricing_cards = [
        [
            'title' => 'Standard Visits',
            'description' => '',
            'prices' => [
                ['service' => 'Telemedicine', 'price' => '$60'],
                ['service' => 'Mobile visit or Covid Testing (PCR or Rapid Covid Testing)', 'price' => '$100'],
                ['service' => 'Same day mobile visit or Covid Testing (PCR or Rapid Covid Testing)', 'price' => '$125']
            ],
            'button_text' => 'Contact to Book',
            'button_url' => '/contact'
        ],
        [
            'title' => 'Coronavirus Consult (free)',
            'description' => 'Short 15 minute visit if you think you might have Covid-19. Testing is also available at an additional charge.',
            'prices' => [
                ['service' => 'Coronavirus consult', 'price' => 'Free']
            ],
            'button_text' => 'Contact to Book',
            'button_url' => '/contact'
        ]
    ];
}

// Default services if none configured
if (empty($services)) {
    $services = [
        ['name' => 'Primary care/Telemedicine', 'url' => '#'],
        ['name' => 'Covid-19 Testing', 'url' => '#'],
        ['name' => 'Chronic health management', 'url' => '#'],
        ['name' => 'Stress management', 'url' => '#'],
        ['name' => 'Holistic health plans', 'url' => '#'],
        ['name' => 'Nutrition', 'url' => '#']
    ];
}
?>

<section class="offered-service-area section-gap">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 offered-left">
                <h1 class="text-white"><?= htmlspecialchars($heading) ?></h1>
                <div class="service-wrap row">
                    <?php foreach ($pricing_cards as $card): ?>
                    <div class="col-lg-6 col-md-6">
                        <div class="appointment-left sidebar-service-hr">
                            <h3 class="pb-20">
                                <?= htmlspecialchars($card['title'] ?? '') ?>
                            </h3>

                            <?php if (!empty($card['description'])): ?>
                            <p>
                                <?= htmlspecialchars($card['description']) ?>
                            </p>
                            <?php endif; ?>

                            <?php if (!empty($card['prices'])): ?>
                            <ul class="time-list">
                                <?php foreach ($card['prices'] as $price): ?>
                                <li class="d-flex justify-content-between">
                                    <span><?= htmlspecialchars($price['service'] ?? '') ?></span>
                                    <span><?= htmlspecialchars($price['price'] ?? '') ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>

                            <?php if (!empty($card['button_text'])): ?>
                            <a href="<?= htmlspecialchars($card['button_url'] ?? '/contact') ?>" class="primary-btn text-uppercase"><?= htmlspecialchars($card['button_text']) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="offered-right relative">
                    <div class="overlay overlay-bg"></div>
                    <h3 class="relative text-white"><?= htmlspecialchars($sidebar_title) ?></h3>
                    <ul class="relative dep-list">
                        <?php foreach ($services as $service): ?>
                        <li><a href="<?= htmlspecialchars($service['url'] ?? '#') ?>"><?= htmlspecialchars($service['name'] ?? '') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($sidebar_link_text): ?>
                    <a class="viewall-btn" href="<?= htmlspecialchars($sidebar_link_url) ?>"><?= htmlspecialchars($sidebar_link_text) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
