<?php
// theme/zoukroom/serve.php - RouteHelper format routes for zoukroom theme

$routes = [
    'dynamic' => [
        // ZoukRoom theme - event-focused theme
        // Has event-specific views
        
        '/event/{slug}' => ['model' => 'Event', 'model_file' => 'data/events_class'],
        '/events' => ['view' => 'views/events'],
    ],
    
    'custom' => [
        // Custom routes can be added here as needed
    ],
];