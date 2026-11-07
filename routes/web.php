<?php

use Illuminate\Support\Facades\Route;

Route::view('/login', 'app')->name('login');

Route::get('/robots.txt', function () {
    $sitemap = rtrim(config('app.url'), '/').'/sitemap.xml';

    $body = implode("\n", [
        'User-agent: *',
        'Allow: /',
        'Allow: /login',
        'Disallow: /app',
        'Disallow: /w/',
        'Disallow: /api/',
        'Disallow: /sanctum/',
        'Disallow: /invite/',
        '',
        'Sitemap: '.$sitemap,
        '',
    ]);

    return response($body, 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
})->name('robots');

Route::get('/sitemap.xml', function () {
    $base = rtrim(config('app.url'), '/');
    $urls = [
        ['loc' => $base.'/', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['loc' => $base.'/login', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ];

    $body = '<?xml version="1.0" encoding="UTF-8"?>'
        ."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        ."\n";

    foreach ($urls as $url) {
        $body .= '  <url>'
            ."\n"
            .'    <loc>'.e($url['loc']).'</loc>'
            ."\n"
            .'    <changefreq>'.$url['changefreq'].'</changefreq>'
            ."\n"
            .'    <priority>'.$url['priority'].'</priority>'
            ."\n"
            .'  </url>'
            ."\n";
    }

    $body .= '</urlset>';

    return response($body, 200, [
        'Content-Type' => 'application/xml; charset=UTF-8',
    ]);
})->name('sitemap');

Route::view('/{any?}', 'app')->where('any', '^(?!api(?:/|$)|sanctum(?:/|$)|up$).*');
