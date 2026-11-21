<?php
/**
 * Kumpulan ikon SVG dan elemen visual ArenaKu.
 *
 * Semua ikon memakai currentColor agar otomatis mengikuti warna teks.
 * Tidak ada dependensi eksternal (tanpa icon font / CDN).
 */

function akEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function svgIcon($name, $class = 'icon')
{
    static $icons = [
        'arrow-right'   => '<path d="M4 12h15M13 6l6 6-6 6"/>',
        'arrow-left'    => '<path d="M20 12H5M11 18l-6-6 6-6"/>',
        'arrow-up-right'=> '<path d="M7 17 17 7M8 7h9v9"/>',
        'search'        => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'pin'           => '<path d="M12 21s6-5.3 6-11a6 6 0 1 0-12 0c0 5.7 6 11 6 11Z"/><circle cx="12" cy="10" r="2.2"/>',
        'calendar'      => '<rect x="3.5" y="5" width="17" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'clock'         => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'star'          => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
        'star-filled'   => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z" fill="currentColor" stroke="none"/>',
        'check'         => '<path d="m5 12.5 4.2 4.2L19 6.5"/>',
        'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12.2 2.7 2.7L16.5 9"/>',
        'x'             => '<path d="m6 6 12 12M18 6 6 18"/>',
        'x-circle'      => '<circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7M15.5 8.5l-7 7"/>',
        'alert'         => '<path d="M12 3.5 21 20H3L12 3.5Z"/><path d="M12 9v5M12 17.2v.2"/>',
        'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8v.2"/>',
        'menu'          => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'         => '<path d="m6 6 12 12M18 6 6 18"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/>',
        'users'         => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M17.5 14.2a6.5 6.5 0 0 1 4 5.8"/>',
        'ticket'        => '<path d="M3.5 9a2.5 2.5 0 0 0 0 5v4.5a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5V14a2.5 2.5 0 0 1 0-5V4.5A1.5 1.5 0 0 0 19 3H5a1.5 1.5 0 0 0-1.5 1.5V9Z"/><path d="M14 7v2M14 12v2M14 17v1"/>',
        'qr'            => '<rect x="3.5" y="3.5" width="6" height="6" rx="1"/><rect x="14.5" y="3.5" width="6" height="6" rx="1"/><rect x="3.5" y="14.5" width="6" height="6" rx="1"/><path d="M14.5 14.5h2.5v2.5h-2.5zM21 14.5v6h-6M14.5 21h1"/>',
        'bank'          => '<path d="M3 10 12 4l9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/>',
        'wallet'        => '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path d="M3 10h18M16 15h2"/>',
        'shield'        => '<path d="M12 3 4.5 6v6c0 4.5 3 7.4 7.5 9 4.5-1.6 7.5-4.5 7.5-9V6L12 3Z"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/>',
        'zap'           => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
        'receipt'       => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'logout'        => '<path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10"/><path d="M14 8l4 4-4 4M8 12h10"/>',
        'grid'          => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.2"/>',
        'field'         => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M12 5v14"/><circle cx="12" cy="12" r="3.2"/><path d="M3 9h3v6H3M21 9h-3v6h3"/>',
        'list'          => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.2M4 12h.2M4 18h.2"/>',
        'card'          => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M7 15h3"/>',
        'chart'         => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'plus'          => '<path d="M12 5v14M5 12h14"/>',
        'trash'         => '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l.8 13h9.4l.8-13M10 11v5M14 11v5"/>',
        'edit'          => '<path d="m4 20 4.5-1L20 7.5 16.5 4 5 15.5 4 20Z"/><path d="m14.5 6 3.5 3.5"/>',
        'copy'          => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'filter'        => '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/>',
        'external'      => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'spark'         => '<path d="M12 2.5 14 9l6.5 3-6.5 3-2 6.5L10 15l-6.5-3L10 9l2-6.5Z"/>',
        'lock'          => '<rect x="4.5" y="10" width="15" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'eye'           => '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'       => '<path d="M4 4l16 16"/><path d="M9.9 5.3A9.6 9.6 0 0 1 12 5.1c6 0 9.5 6.2 9.5 6.2a17.8 17.8 0 0 1-3.4 4.1M6.6 6.9A17.7 17.7 0 0 0 2.5 11.3s3.5 6.2 9.5 6.2a9.9 9.9 0 0 0 4.4-1.1"/><path d="M10.1 10.2a2.8 2.8 0 0 0 3.7 3.7"/>',
        'phone'         => '<path d="M7 3h3l1.5 4-2 1.5a14 14 0 0 0 6 6L17 12.5l4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 3 5.2 2 2 0 0 1 5 3h2Z"/>',
        'mail'          => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'whistle'       => '<path d="M14 7h7v4h-3a5 5 0 1 1-4-4Z"/><circle cx="9" cy="14" r="5"/><path d="M9 14h.01"/>',
    ];

    $body = isset($icons[$name]) ? $icons[$name] : $icons['spark'];
    return '<svg class="' . akEscape($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $body . '</svg>';
}

function sportTheme($sport)
{
    $key = strtolower(trim((string) $sport));
    if (strpos($key, 'futsal') !== false || strpos($key, 'sepak') !== false || strpos($key, 'soccer') !== false) {
        return 'futsal';
    }
    if (strpos($key, 'badminton') !== false || strpos($key, 'bulu') !== false) {
        return 'badminton';
    }
    if (strpos($key, 'basket') !== false) {
        return 'basket';
    }
    if (strpos($key, 'voli') !== false || strpos($key, 'volley') !== false) {
        return 'voli';
    }
    return 'generic';
}

function sportIcon($sport, $class = 'icon')
{
    static $icons = [
        'futsal' => '<circle cx="12" cy="12" r="8.5"/><path d="m12 7.4 3.7 2.7-1.4 4.3H9.7L8.3 10.1 12 7.4Z"/><path d="M12 3.5v3.9M20.5 10l-3.8 1.3M18 19.7l-2.8-3.2M6 19.7l2.8-3.2M3.5 10l3.8 1.3"/>',
        'badminton' => '<path d="M12 21 5.5 7.8 12 3.5l6.5 4.3L12 21Z"/><path d="M8.2 10.2h7.6M9.5 13.6h5"/><path d="M12 21v-7.4"/>',
        'basket' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5v17"/><path d="M6 5.5c3.6 3.5 3.6 9.5 0 13M18 5.5c-3.6 3.5-3.6 9.5 0 13"/>',
        'voli' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5c2.5 3.7 2.5 7.5 0 11.2M12 20.5c-2.5-3.7-2.5-7.5 0-11.2M4.2 8.4c4.2.7 7.1 3.1 8.4 7.5M19.8 8.4c-4.2.7-7.1 3.1-8.4 7.5"/>',
        'generic' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.5"/><path d="M12 3.5v3M12 17.5v3M3.5 12h3M17.5 12h3"/>',
    ];

    $theme = sportTheme($sport);
    $body = $icons[$theme];

    return '<svg class="' . akEscape($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $body . '</svg>';
}

function courtDiagram($class = 'court-diagram')
{
    return '<svg class="' . akEscape($class) . '" viewBox="0 0 440 280" fill="none" stroke="currentColor" '
        . 'stroke-width="1.4" aria-hidden="true">'
        . '<rect x="1" y="1" width="438" height="278" rx="18"/>'
        . '<path d="M220 1v278"/>'
        . '<circle cx="220" cy="140" r="46"/>'
        . '<circle cx="220" cy="140" r="2.5" fill="currentColor" stroke="none"/>'
        . '<path d="M1 62h54v156H1M439 62h-54v156h54"/>'
        . '<path d="M55 62a52 52 0 0 1 0 156M385 62a52 52 0 0 0 0 156"/>'
        . '<path d="M1 1h18M1 1v18M439 1h-18M439 1v18M1 279h18M1 279v-18M439 279h-18M439 279v-18"/>'
        . '</svg>';
}

function brandLogo($href = '/arenaku/index.php', $light = false)
{
    $classes = 'brand' . ($light ? ' brand-light' : '');
    $mark = '<img src="' . akEscape(assetUrl('img/logo-mark.png')) . '?v=1" alt="" width="40" height="40" decoding="async">';

    return '<a href="' . akEscape($href) . '" class="' . $classes . '">'
        . '<span class="brand-mark">' . $mark . '</span>'
        . '<span class="brand-word">Arena<span>Ku</span></span>'
        . '</a>';
}

/**
 * Render visual cover lapangan. Memakai foto lokal bila file tersedia,
 * jika tidak memakai komposisi warna + ikon sesuai cabang olahraga.
 */
function courtCover($court, $extraClass = '')
{
    $sport = isset($court['sport_name']) ? (string) $court['sport_name'] : '';
    $theme = sportTheme($sport);
    $label = $sport !== '' ? $sport : 'Lapangan';
    $name = isset($court['name']) ? (string) $court['name'] : $label;
    $number = str_pad((string) (isset($court['id']) ? (int) $court['id'] : 0), 2, '0', STR_PAD_LEFT);
    $imageUrl = function_exists('courtImageUrl') ? courtImageUrl($court) : '';
    $imageHtml = '';
    $photoClass = '';

    if ($imageUrl !== '') {
        $fallbackUrl = function_exists('courtImageFallbackUrl') ? courtImageFallbackUrl() : '';
        $onError = $fallbackUrl !== ''
            ? ' onerror="this.onerror=null;this.src=\'' . akEscape($fallbackUrl) . '\';"'
            : '';
        $imageHtml = '<img src="' . akEscape($imageUrl) . '" alt="' . akEscape($name) . '" loading="lazy" decoding="async"' . $onError . '>'
            . '<span class="cover-scrim"></span>';
        $photoClass = ' has-photo';
    }

    return '<div class="court-cover theme-' . akEscape($theme) . $photoClass . ' ' . akEscape($extraClass) . '">'
        . $imageHtml
        . '<span class="cover-index">' . $number . '</span>'
        . '<span class="cover-sport">' . akEscape($label) . '</span>'
        . '<span class="cover-icon">' . sportIcon($sport, 'icon') . '</span>'
        . '</div>';
}
