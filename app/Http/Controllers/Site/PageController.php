<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Support\SiteSettings;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return view('site.home', [
            'featured' => Service::active()->where('is_featured', true)->with('category')->orderBy('sort_order')->take(6)->get(),
            'staff' => Staff::bookable()->orderBy('sort_order')->take(4)->get(),
        ]);
    }

    public function prices(): View
    {
        return view('site.prices', [
            'categories' => $this->categories(),
        ]);
    }

    public function team(): View
    {
        return view('site.team', [
            'staff' => Staff::bookable()
                ->with(['services' => fn ($q) => $q->active()->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function sitemap(SiteSettings $site): Response
    {
        $urls = $site->get('noindex') ? [] : [route('home'), route('prices'), route('team'), route('booking.create')];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .collect($urls)->map(fn (string $url) => '  <url><loc>'.e($url).'</loc></url>')->implode("\n")."\n"
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function categories()
    {
        return ServiceCategory::where('is_active', true)
            ->with(['services' => fn ($q) => $q->active()->orderBy('sort_order')])
            ->whereHas('services', fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->get();
    }
}
