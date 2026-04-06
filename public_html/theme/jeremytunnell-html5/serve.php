<?php
// theme/jeremytunnell-html5/serve.php - RouteHelper format routes

$routes = [
    'dynamic' => [
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class'],
    ],

    'custom' => [],
];
