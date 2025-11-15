<?php
// theme/galactictribune/serve.php - RouteHelper format routes for galactictribune theme

$routes = [
    'dynamic' => [
        // GalacticTribune theme specific routes
        // Has custom views: explorer, get-spawned, point-info
        
        '/explorer' => ['view' => 'views/explorer'],
        '/get-spawned' => ['view' => 'views/get-spawned'],
        '/get-unspawned-children' => ['view' => 'views/get-unspawned-children'],
        '/point-info' => ['view' => 'views/point-info'],
    ],
    
    'custom' => [
        // Custom routes can be added here as needed
    ],
];