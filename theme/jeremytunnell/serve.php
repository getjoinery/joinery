<?php
// theme/jeremytunnell/serve.php - RouteHelper format routes for jeremytunnell theme

$routes = [
    'dynamic' => [
        // JeremyTunnell theme specific routes
        // Blog-focused theme with custom styling
        
        // Blog routes (theme has blog.php and post.php views)
        '/blog' => ['view' => 'blog'],
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class'],
    ],
    
    'custom' => [
        // Custom routes can be added here as needed
    ],
];