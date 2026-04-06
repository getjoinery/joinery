<?php
// theme/jeremytunnell/serve.php - RouteHelper format routes for jeremytunnell theme

$routes = [
    'dynamic' => [
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class'],
    ],

    'custom' => [],
];
