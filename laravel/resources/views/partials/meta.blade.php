@php
    use App\Support\Tenant;

    $metaSchool = $school ?? Tenant::get();
    $brand = $metaSchool?->name ?? config('app.name');
    $page = isset($metaTitle) ? $metaTitle : ($title ?? null);
    $documentTitle = filled($page) ? $page.' - '.$brand : $brand;

    if (filled($metaDescription ?? null)) {
        $description = $metaDescription;
    } elseif ($metaSchool) {
        $description = $metaSchool->name.($metaSchool->motto ? ' — '.$metaSchool->motto : '').'. Report cards, attendance, timetable, fees, and notices.';
    } else {
        $description = config('app.name').'. One desk for every school’s people, classes, results, and fees.';
    }
    $description = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($description)) ?? '', 160);

    $theme = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $metaSchool?->primary_color) ? $metaSchool->primary_color : '#a01010';
    $image = asset('favicon/android-chrome-512x512.png');
    $robots = $metaRobots ?? (auth()->check() ? 'noindex, nofollow' : 'index, follow');
@endphp
<title>{{ $documentTitle }}</title>
<meta name="description" content="{{ $description }}">
<meta name="theme-color" content="{{ $theme }}">
<meta name="application-name" content="{{ $brand }}">
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ url()->current() }}">

<meta property="og:title" content="{{ $documentTitle }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:site_name" content="{{ $brand }}">
<meta property="og:image" content="{{ $image }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $documentTitle }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">

<link rel="icon" href="{{ asset('favicon/favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon/favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon/favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('favicon/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('favicon/site.webmanifest') }}">
