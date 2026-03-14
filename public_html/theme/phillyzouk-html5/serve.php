<?php
// theme/phillyzouk-html5/serve.php - RouteHelper format routes

$routes = [
    'dynamic' => [
        '/blog' => ['view' => 'views/blog'],
        '/blog/tag/{tag}' => ['view' => 'views/blog'],
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class'],
        '/events' => ['view' => 'views/events'],
    ],

    'custom' => [],
];
