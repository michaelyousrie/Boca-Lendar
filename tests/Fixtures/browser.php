<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;

$public = realpath(__DIR__.'/../../public');
$file = realpath($public.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($file && str_starts_with($file, $public.'/') && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'boca_e2e') {
    throw new RuntimeException('This server only runs against the browser test database.');
}

Vite::useHotFile(storage_path('framework/testing-vite.hot'));
Http::preventStrayRequests();
$app->handleRequest(Request::capture());
