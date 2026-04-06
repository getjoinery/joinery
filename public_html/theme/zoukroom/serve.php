<?php
// theme/zoukroom/serve.php - RouteHelper format routes for zoukroom theme

$routes = [
    'dynamic' => [
        '/event/{slug}' => ['model' => 'Event', 'model_file' => 'data/events_class'],
    ],

    'custom' => [],
];
