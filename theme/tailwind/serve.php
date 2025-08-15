<?php
// theme/tailwind/serve.php - RouteHelper format routes for tailwind theme

$routes = [
    'dynamic' => [
        // Tailwind theme specific routes only
        // Event-related routes (tailwind has event support views)
        '/event_waiting_list' => ['view' => 'views/event_waiting_list'],
    ],
    
    'custom' => [
        // Custom routes can be added here as needed
    ],
];