{source}
<?php
defined('_JEXEC') or die;

/*
 * LottoExpert Full Site Audit Scanner — Joomla/Sourcerer version.
 * Paste into a Joomla article using the Sourcerer plugin.
 * IMPORTANT: Keep this article unpublished or access-restricted when not actively using it.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Session\Session;

$app   = Factory::getApplication();
$input = $app->input;

// ── Config ───────────────────────────────────────────────────────────────────
$config = [
    'site_root'        => 'https://lottoexpert.net',
    'batch_limit'      => 10,
    'max_queue_size'   => 25000,
    'request_timeout'  => 4,
    'allowed_host'     => 'lottoexpert.net',
    'allowed_hosts'    => ['lottoexpert.net', 'www.lottoexpert.net'],
    'ignore_patterns'  => [
        '/logout',
        '/login?return=',
        '/component/users',
        '/administrator',
        '/cart',
        '/checkout',
        '/?print=',
        '&print=',
        'format=feed',
        'tmpl=component',
        '#',
        'mailto:',
        'tel:',
        'javascript:',
    ],
    'sitemap_timeout'    => 30,
    'sitemap_candidates' => [
        'https://lottoexpert.net/sitemap_xml.xml',
    ],
];

// ── Scan options ─────────────────────────────────────────────────────────────
function leAuditGetDefaultOptions(): array
{
    return [
        'check_broken_internal'       => true,  // record 404/5xx internal pages as broken links
        'check_broken_internal_links' => true,  // HEAD-check internal links found on pages (proactive, separate from crawl)
        'check_broken_external'       => true,  // HEAD-check external links found on pages
        'check_broken_images'   => true,  // HEAD-check image src URLs found on pages
        'check_seo'             => true,  // title, meta description, canonical, H1, noindex
        'check_speed'           => true,  // flag pages loading > 3 s
        'check_alt_text'        => true,  // flag images missing alt attribute
        'store_passed_pages'    => false, // keep full item data for pages with no issues/warnings
    ];
}

// ── Core functions ───────────────────────────────────────────────────────────

function leAuditNormalizeUrl(string $url, array $config): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');

    if ($url === '') {
        return '';
    }

    foreach ($config['ignore_patterns'] as $pattern) {
        if (stripos($url, $pattern) !== false) {
            return '';
        }
    }

    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    if (strpos($url, '/') === 0) {
        $url = rtrim($config['site_root'], '/') . $url;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }

    $parts = parse_url($url);

    if (empty($parts['host'])) {
        return '';
    }

    $host = strtolower($parts['host']);

    if (!in_array($host, $config['allowed_hosts'], true)) {
        return '';
    }

    $scheme = 'https';
    $path   = $parts['path'] ?? '/';
    $query  = $parts['query'] ?? '';

    $path = preg_replace('#/+#', '/', $path);

    $clean = $scheme . '://' . $config['allowed_host'] . $path;

    if ($query !== '') {
        parse_str($query, $queryArray);

        $blockedQueryKeys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'msclkid', 'print', 'tmpl', 'format',
        ];

        foreach ($blockedQueryKeys as $blockedKey) {
            unset($queryArray[$blockedKey]);
        }

        if (!empty($queryArray)) {
            ksort($queryArray);
            $clean .= '?' . http_build_query($queryArray);
        }
    }

    $root = 'https://' . $config['allowed_host'];
    return rtrim($clean, '/') === $root ? $root . '/' : rtrim($clean, '/');
}

function leAuditSessionGet(string $key, $default)
{
    $val = $_SESSION[$key] ?? null;
    return is_array($val) ? $val : $default;
}

function leAuditSessionSet(string $key, array $data): void
{
    $_SESSION[$key] = $data;
}

function leAuditInitState(array $config): array
{
    $queue   = leAuditSessionGet('le_queue', []);
    $results = leAuditSessionGet('le_results', []);

    if (empty($queue)) {
        $home  = leAuditNormalizeUrl($config['site_root'] . '/', $config);
        $queue = [
            'created_at'    => gmdate('c'),
            'updated_at'    => gmdate('c'),
            'pending'       => [$home],
            'seen'          => [$home => true],
            'scanned'       => [],
            'referrers'     => [],
            'ext_pending'   => [],
            'ext_seen'      => [],
            'ext_referrers' => [],
        ];
        leAuditSessionSet('le_queue', $queue);
    }

    if (empty($results)) {
        $results = [
            'created_at'   => gmdate('c'),
            'updated_at'   => gmdate('c'),
            'items'        => [],
            'summary'      => [],
            'broken_links' => [],
        ];
        leAuditSessionSet('le_results', $results);
    }

    return [$queue, $results];
}

function leAuditFetchUrl(string $url, array $config, ?int $timeout = null): array
{
    $timeoutSeconds = $timeout ?? (int) $config['request_timeout'];
    $response = [
        'url'              => $url,
        'status'           => 0,
        'final_url'        => $url,
        'content_type'     => '',
        'body'             => '',
        'error'            => '',
        'load_time_ms'     => 0,
        'response_headers' => '',
    ];

    $start = microtime(true);

    if (!function_exists('curl_init')) {
        $response['error'] = 'cURL is not available on this server.';
        return $response;
    }

    $ch               = curl_init();
    $headersCollected = '';

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'LottoExpert Private Audit Scanner/2.0',
        CURLOPT_HEADER         => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headersCollected) {
            $headersCollected .= $header;
            return strlen($header);
        },
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $response['error'] = curl_error($ch);
    } else {
        $response['body'] = (string) $body;
    }

    $response['status']           = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response['final_url']        = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $response['content_type']     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $response['load_time_ms']     = (int) round((microtime(true) - $start) * 1000);
    $response['response_headers'] = $headersCollected;

    curl_close($ch);
    return $response;
}

function leAuditExtractTag(string $html, string $tag): string
{
    if (preg_match('#<' . preg_quote($tag, '#') . '[^>]*>(.*?)</' . preg_quote($tag, '#') . '>#is', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function leAuditExtractMetaDescription(string $html): string
{
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function leAuditExtractCanonical(string $html): string
{
    if (preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)["\'][^>]*>#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    if (preg_match('#<link[^>]+href=["\']([^"\']*)["\'][^>]+rel=["\']canonical["\'][^>]*>#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function leAuditExtractLinks(string $html, string $baseUrl, array $config): array
{
    $links = [];
    if (preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $href) {
            $n = leAuditNormalizeUrl($href, $config);
            if ($n !== '') {
                $links[] = $n;
            }
        }
    }
    return array_values(array_unique($links));
}

function leAuditExtractExternalLinks(string $html, array $config): array
{
    $links = [];
    if (!preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
        return $links;
    }
    foreach ($m[1] as $href) {
        $href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
        if ($href === '') {
            continue;
        }
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href;
        }
        if (!preg_match('#^https?://#i', $href)) {
            continue;
        }
        $parts = parse_url($href);
        if (empty($parts['host'])) {
            continue;
        }
        $host = strtolower($parts['host']);
        if (in_array($host, $config['allowed_hosts'], true)) {
            continue; // internal link — handled by the main crawler
        }
        $clean = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/');
        if (!empty($parts['query'])) {
            $clean .= '?' . $parts['query'];
        }
        $links[] = $clean;
        if (count($links) >= 50) {
            break; // cap per page to avoid queue bloat
        }
    }
    return array_values(array_unique($links));
}

function leAuditHeadCheckUrl(string $url): int
{
    if (!function_exists('curl_init')) {
        return 0;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'LottoExpert Private Audit Scanner/2.0',
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function leAuditExtractImages(string $html, string $baseUrl, array $config): array
{
    $images = [];
    if (!preg_match_all('#<img\s[^>]*src=["\']([^"\']+)["\']#i', $html, $m)) {
        return $images;
    }
    foreach ($m[1] as $src) {
        $src = html_entity_decode(trim($src), ENT_QUOTES, 'UTF-8');
        if ($src === '' || strpos($src, 'data:') === 0) {
            continue;
        }
        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
        } elseif (strpos($src, '/') === 0) {
            $src = rtrim($config['site_root'], '/') . $src;
        } elseif (!preg_match('#^https?://#i', $src)) {
            $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
        }
        if (!preg_match('#^https?://#i', $src)) {
            continue;
        }
        $images[] = $src;
        if (count($images) >= 30) {
            break; // cap per page to avoid queue bloat
        }
    }
    return array_values(array_unique($images));
}

function leAuditCountImagesWithoutAlt(string $html): int
{
    $count = 0;
    if (preg_match_all('#<img\s[^>]*>#i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('#\balt\s*=#i', $tag)) {
                $count++;
            }
        }
    }
    return $count;
}

function leAuditCountH1(string $html): int
{
    return preg_match_all('#<h1\b[^>]*>(.*?)</h1>#is', $html) ?: 0;
}

function leAuditExtractH1Text(string $html): string
{
    if (preg_match('#<h1\b[^>]*>(.*?)</h1>#is', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function leAuditHasNoindex(string $html, string $responseHeaders = ''): bool
{
    if (preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex[^"\']*["\']#i', $html)) {
        return true;
    }
    if ($responseHeaders !== '' && preg_match('#x-robots-tag:[^\r\n]*noindex#i', $responseHeaders)) {
        return true;
    }
    return false;
}

function leAuditCheckUrl(string $url, array $config, array $options = []): array
{
    $checkSeo     = $options['check_seo']      ?? true;
    $checkSpeed   = $options['check_speed']    ?? true;
    $checkAltText = $options['check_alt_text'] ?? true;

    $fetch = leAuditFetchUrl($url, $config);
    $html  = $fetch['body'];

    $title       = $checkSeo     ? leAuditExtractTag($html, 'title')          : '';
    $description = $checkSeo     ? leAuditExtractMetaDescription($html)        : '';
    $canonical   = $checkSeo     ? leAuditExtractCanonical($html)              : '';
    $h1Count     = $checkSeo     ? leAuditCountH1($html)                       : 0;
    $h1Text      = $checkSeo     ? leAuditExtractH1Text($html)                 : '';
    $noindex     = $checkSeo     ? leAuditHasNoindex($html, $fetch['response_headers']) : false;
    $imgsNoAlt   = $checkAltText ? leAuditCountImagesWithoutAlt($html)         : 0;

    $titleLen = mb_strlen($title, 'UTF-8');
    $descLen  = mb_strlen($description, 'UTF-8');

    $issues   = [];
    $warnings = [];

    if ($fetch['status'] === 0) {
        $issues[] = 'Connection failed: ' . $fetch['error'];
    } elseif ($fetch['status'] === 404) {
        $issues[] = '404 Not Found';
    } elseif ($fetch['status'] >= 500) {
        $issues[] = 'Server error ' . $fetch['status'];
    } elseif ($fetch['status'] >= 400) {
        $issues[] = 'Client error ' . $fetch['status'];
    }

    if ($checkSeo && $fetch['status'] >= 200 && $fetch['status'] < 300) {
        if ($title === '') {
            $issues[] = 'Missing title tag';
        } elseif ($titleLen < 10) {
            $warnings[] = 'Page title is too short (' . $titleLen . ' chars) — Google recommends 50–70 characters. A very short title gives search engines almost no information about the page topic. Expand your title to clearly describe the page content.';
        } elseif ($titleLen > 70) {
            $warnings[] = 'Page title is too long (' . $titleLen . ' chars) — Google typically truncates titles longer than 70 characters in search results, cutting off part of your message. Edit the title to 50–70 characters.';
        }

        if ($description === '') {
            $issues[] = 'Missing meta description';
        } elseif ($descLen < 50) {
            $warnings[] = 'Meta description is too short (' . $descLen . ' chars) — Search engines use this text as the snippet shown under your page title in results. A snippet under 50 characters is too brief to attract clicks. Write a 50–165 character description that summarises the page and encourages users to click.';
        } elseif ($descLen > 165) {
            $warnings[] = 'Meta description is too long (' . $descLen . ' chars) — Google will cut off descriptions longer than ~165 characters, leaving an incomplete sentence in search results. Trim your description to under 165 characters.';
        }

        if ($canonical === '') {
            $warnings[] = 'Missing canonical tag — A canonical tag (<link rel="canonical" href="…">) tells search engines which version of this URL is the "official" one, preventing duplicate-content penalties when the same page is reachable via multiple URLs. Add a self-referencing canonical tag to the <head> of this page.';
        }

        if ($h1Count === 0) {
            $issues[] = 'Missing H1 tag';
        } elseif ($h1Count > 1) {
            $warnings[] = 'Multiple H1 headings found (' . $h1Count . ') — Each page should have exactly one H1 tag that clearly states the main topic. Having several H1s confuses search engines about the primary subject. Keep one H1 and use H2/H3 for sub-headings.';
        }

        if ($noindex) {
            $warnings[] = 'Page has a noindex directive — This page is actively instructing search engines NOT to index it (via a <meta name="robots" content="noindex"> tag or an X-Robots-Tag HTTP header). If this is unintentional, remove the noindex directive so the page can appear in search results.';
        }
    }

    if ($checkAltText && $imgsNoAlt > 0 && $fetch['status'] >= 200 && $fetch['status'] < 300) {
        $warnings[] = $imgsNoAlt . ' image(s) are missing alt text — The alt attribute on an <img> tag describes the image to search engines and screen-reader users. Missing alt text harms SEO and accessibility. Add a short, descriptive alt="…" to every image on this page.';
    }

    if ($checkSpeed && $fetch['load_time_ms'] > 3000 && $fetch['status'] >= 200 && $fetch['status'] < 300) {
        $warnings[] = 'Slow page load time (' . $fetch['load_time_ms'] . ' ms) — Google uses page speed as a ranking factor; pages taking over 3 seconds to load are penalised and have higher visitor bounce rates. Investigate large images, render-blocking scripts, or slow server response times.';
    }

    return [
        'url'                     => $url,
        'status'                  => $fetch['status'],
        'final_url'               => $fetch['final_url'],
        'load_time_ms'            => $fetch['load_time_ms'],
        'title'                   => $title,
        'title_length'            => $titleLen,
        'meta_description'        => $description,
        'meta_description_length' => $descLen,
        'canonical'               => $canonical,
        'h1_count'                => $h1Count,
        'h1_text'                 => $h1Text,
        'noindex'                 => $noindex,
        'images_without_alt'      => $imgsNoAlt,
        'issues'                  => $issues,
        'warnings'                => $warnings,
        'scanned_at'              => gmdate('c'),
        'discovered_links'          => leAuditExtractLinks($html, $url, $config),
        'discovered_external_links' => leAuditExtractExternalLinks($html, $config),
        'discovered_images'         => leAuditExtractImages($html, $url, $config),
    ];
}

function leAuditLoadSitemapUrls(array $config): array
{
    $urls           = [];
    $sitemapsToRead = [];
    $sitemapsRead   = [];
    $diagnostics    = [];

    foreach ($config['sitemap_candidates'] as $sitemapUrl) {
        $sitemapUrl = trim((string) $sitemapUrl);
        if ($sitemapUrl !== '') {
            $sitemapsToRead[] = $sitemapUrl;
        }
    }

    while (!empty($sitemapsToRead)) {
        $currentSitemap = array_shift($sitemapsToRead);

        if ($currentSitemap === '' || isset($sitemapsRead[$currentSitemap])) {
            continue;
        }

        $sitemapsRead[$currentSitemap] = true;
        $fetch = leAuditFetchUrl($currentSitemap, $config, $config['sitemap_timeout']);

        if ($fetch['status'] < 200 || $fetch['status'] >= 300 || $fetch['body'] === '') {
            $errDetail     = $fetch['error'] !== '' ? ': ' . $fetch['error'] : '';
            $diagnostics[] = 'SKIP ' . $currentSitemap . ' (HTTP ' . $fetch['status'] . $errDetail . ')';
            continue;
        }

        $body = $fetch['body'];

        if (!preg_match_all('#<loc>(.*?)</loc>#is', $body, $matches)) {
            $diagnostics[] = 'SKIP ' . $currentSitemap . ' (no <loc> entries found)';
            continue;
        }

        // Log a sample of the first 3 raw <loc> values to aid debugging
        $sampleLocs = array_slice($matches[1], 0, 3);
        foreach ($sampleLocs as &$s) {
            $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
        }
        unset($s);
        $diagnostics[] = 'SAMPLE locs from ' . $currentSitemap . ': ' . implode(' | ', $sampleLocs);

        $isSitemapIndex = stripos($body, '<sitemapindex') !== false
                       || (stripos($body, '<sitemap>') !== false && stripos($body, '<url>') === false);
        $foundUnique    = [];
        $subSitemaps    = 0;

        foreach ($matches[1] as $loc) {
            $loc = trim(html_entity_decode(strip_tags($loc), ENT_QUOTES, 'UTF-8'));

            if ($loc === '') {
                continue;
            }

            if ($isSitemapIndex) {
                if (!isset($sitemapsRead[$loc])) {
                    $sitemapsToRead[] = $loc;
                    $subSitemaps++;
                }
                continue;
            }

            // Treat any <loc> pointing to an .xml file on an allowed host as a sub-sitemap
            $locHost = strtolower((string) parse_url($loc, PHP_URL_HOST));
            $locPath = (string) parse_url($loc, PHP_URL_PATH);
            if (in_array($locHost, $config['allowed_hosts'], true) && preg_match('#\.xml$#i', $locPath)) {
                if (!isset($sitemapsRead[$loc])) {
                    $sitemapsToRead[] = $loc;
                    $subSitemaps++;
                }
                continue;
            }

            $normalized = leAuditNormalizeUrl($loc, $config);
            if ($normalized !== '') {
                $urls[]                    = $normalized;
                $foundUnique[$normalized]  = true;
            }
        }

        if ($isSitemapIndex) {
            $diagnostics[] = 'INDEX ' . $currentSitemap . ' → queued ' . $subSitemaps . ' sub-sitemaps';
        } elseif ($subSitemaps > 0) {
            $diagnostics[] = 'MIXED ' . $currentSitemap . ' → ' . count($foundUnique) . ' page URLs + queued ' . $subSitemaps . ' sub-sitemaps';
        } else {
            $foundUrls     = count($matches[1]);
            $diagnostics[] = 'OK ' . $currentSitemap . ' → ' . count($foundUnique) . ' unique page URLs (' . $foundUrls . ' raw)';
        }
    }

    return [
        'urls'        => array_values(array_unique($urls)),
        'diagnostics' => $diagnostics,
    ];
}

function leAuditAddUrlsToQueue(array &$queue, array $urls, array $config, string $sourceUrl = ''): int
{
    $added = 0;

    if (!isset($queue['referrers'])) {
        $queue['referrers'] = [];
    }

    foreach ($urls as $url) {
        $normalized = leAuditNormalizeUrl($url, $config);

        if ($normalized === '') {
            continue;
        }

        if (isset($queue['seen'][$normalized]) || isset($queue['scanned'][$normalized])) {
            continue;
        }

        if (count($queue['seen']) >= $config['max_queue_size']) {
            break;
        }

        $queue['pending'][]         = $normalized;
        $queue['seen'][$normalized] = true;

        if ($sourceUrl !== '' && !isset($queue['referrers'][$normalized])) {
            $queue['referrers'][$normalized] = $sourceUrl;
        }

        $added++;
    }

    $queue['pending']    = array_values(array_unique($queue['pending']));
    $queue['updated_at'] = gmdate('c');
    return $added;
}

function leAuditBuildSummary(array $results): array
{
    $summary = [
        'total_scanned'        => 0,
        'critical_pages'       => 0,
        'warning_pages'        => 0,
        'passed_pages'         => 0,
        'not_found_pages'      => 0,
        'server_error_pages'   => 0,
        'redirect_pages'       => 0,
        'missing_titles'       => 0,
        'missing_descriptions' => 0,
        'missing_canonicals'   => 0,
        'noindex_pages'        => 0,
        'slow_pages'           => 0,
        'images_missing_alt'   => 0,
        'avg_load_time_ms'     => 0,
        'broken_links_total'   => !empty($results['broken_links']) ? count($results['broken_links']) : 0,
    ];

    if (empty($results['items']) || !is_array($results['items'])) {
        return $summary;
    }

    $totalLoadTime = 0;

    foreach ($results['items'] as $item) {
        $summary['total_scanned']++;

        if (!empty($item['issues'])) {
            $summary['critical_pages']++;
        } elseif (!empty($item['warnings'])) {
            $summary['warning_pages']++;
        } else {
            $summary['passed_pages']++;
        }

        if ((int) $item['status'] === 404) {
            $summary['not_found_pages']++;
        }
        if ((int) $item['status'] >= 500) {
            $summary['server_error_pages']++;
        }
        if ((int) $item['status'] >= 300 && (int) $item['status'] < 400) {
            $summary['redirect_pages']++;
        }
        if (trim((string) $item['title']) === '') {
            $summary['missing_titles']++;
        }
        if (trim((string) $item['meta_description']) === '') {
            $summary['missing_descriptions']++;
        }
        if (trim((string) $item['canonical']) === '') {
            $summary['missing_canonicals']++;
        }
        if (!empty($item['noindex'])) {
            $summary['noindex_pages']++;
        }
        if ((int) $item['load_time_ms'] > 3000) {
            $summary['slow_pages']++;
        }
        if (!empty($item['images_without_alt']) && (int) $item['images_without_alt'] > 0) {
            $summary['images_missing_alt']++;
        }

        $totalLoadTime += (int) $item['load_time_ms'];
    }

    if ($summary['total_scanned'] > 0) {
        $summary['avg_load_time_ms'] = (int) round($totalLoadTime / $summary['total_scanned']);
    }

    // Add pages that were scanned but not stored (store_passed_pages = false)
    $extraPassed = (int) ($results['passed_pages_count'] ?? 0);
    $summary['passed_pages']  += $extraPassed;
    $summary['total_scanned'] += $extraPassed;

    return $summary;
}

function leAuditExportCsv(array $results): void
{
    $headers = [
        'URL', 'Status', 'Load Time MS', 'Title', 'Title Length',
        'Meta Description', 'Meta Description Length', 'Canonical',
        'H1 Count', 'H1 Text', 'Noindex', 'Images Without Alt',
        'Issues', 'Warnings', 'Scanned At',
    ];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="full-site-audit-export.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, $headers);

    if (!empty($results['items'])) {
        foreach ($results['items'] as $item) {
            fputcsv($fp, [
                $item['url']                      ?? '',
                $item['status']                   ?? '',
                $item['load_time_ms']             ?? '',
                $item['title']                    ?? '',
                $item['title_length']             ?? '',
                $item['meta_description']         ?? '',
                $item['meta_description_length']  ?? '',
                $item['canonical']                ?? '',
                $item['h1_count']                 ?? '',
                $item['h1_text']                  ?? '',
                !empty($item['noindex']) ? 'Yes' : 'No',
                $item['images_without_alt']       ?? 0,
                implode(' | ', $item['issues']   ?? []),
                implode(' | ', $item['warnings'] ?? []),
                $item['scanned_at']               ?? '',
            ]);
        }
    }

    fclose($fp);
    exit;
}

// ── Handle actions ───────────────────────────────────────────────────────────
[$queue, $results] = leAuditInitState($config);

// Load persisted scan options (or defaults for new sessions / old sessions)
$scanOptions = leAuditSessionGet('le_scan_options', []);
if (empty($scanOptions)) {
    $scanOptions = leAuditGetDefaultOptions();
}
// Back-fill any option keys added after the session was created
foreach (leAuditGetDefaultOptions() as $k => $v) {
    if (!array_key_exists($k, $scanOptions)) {
        $scanOptions[$k] = $v;
    }
}

$message = '';
$action  = $input->getCmd('audit_action', '');

if ($action !== '' && !Session::checkToken('post')) {
    $message = 'Invalid session token. Refresh the page and try again.';
} elseif ($action === 'save_options') {
    $scanOptions = [
        'check_broken_internal'       => (bool) $input->getInt('opt_broken_internal',       0),
        'check_broken_internal_links' => (bool) $input->getInt('opt_broken_internal_links', 0),
        'check_broken_external'       => (bool) $input->getInt('opt_broken_external',       0),
        'check_broken_images'   => (bool) $input->getInt('opt_broken_images',   0),
        'check_seo'             => (bool) $input->getInt('opt_seo',             0),
        'check_speed'           => (bool) $input->getInt('opt_speed',           0),
        'check_alt_text'        => (bool) $input->getInt('opt_alt_text',        0),
        'store_passed_pages'    => (bool) $input->getInt('opt_store_passed',    0),
    ];
    leAuditSessionSet('le_scan_options', $scanOptions);
    $message = 'Scan options saved. These will apply to all future scan batches.';
} elseif ($action === 'reset') {
    unset($_SESSION['le_queue'], $_SESSION['le_results']);
    [$queue, $results] = leAuditInitState($config);
    $message = 'Audit reset successfully.';
} elseif ($action === 'stop_all') {
    $queue['pending']          = [];
    if (isset($queue['ext_pending']))      { $queue['ext_pending']      = []; }
    if (isset($queue['int_link_pending'])) { $queue['int_link_pending'] = []; }
    leAuditSessionSet('le_queue', $queue);
    $message = '🛑 Scan stopped. All pending URLs have been cleared. Results collected so far are preserved. Click Reset Audit to start a completely fresh scan.';
} elseif ($action === 'discover_sitemap') {
    $sitemapResult = leAuditLoadSitemapUrls($config);
    $sitemapUrls   = $sitemapResult['urls'];
    $diagnostics   = $sitemapResult['diagnostics'];
    $added         = leAuditAddUrlsToQueue($queue, $sitemapUrls, $config);
    leAuditSessionSet('le_queue', $queue);
    $alreadyQueued = count($sitemapUrls) - $added;
    $diagText      = !empty($diagnostics) ? ' Details: ' . implode(' | ', $diagnostics) : '';
    $message = 'Sitemap discovery complete. Found ' . count($sitemapUrls) . ' page URLs. '
             . 'Added ' . (int) $added . ' new URLs to queue'
             . ($alreadyQueued > 0 ? ' (' . $alreadyQueued . ' already queued).' : '.') . $diagText;
} elseif ($action === 'scan_batch' || $action === 'scan_batch_ajax') {
    @set_time_limit(180);
    @ignore_user_abort(true);

    // Re-read scan options from the embedded batch form fields and re-persist them.
    // This ensures settings survive across batches even if the Joomla session is
    // regenerated between requests (common in Sourcerer-embedded articles).
    $optionKeys = array_keys(leAuditGetDefaultOptions());
    if ($input->getInt('batch_opt_check_seo', -1) !== -1) {
        foreach ($optionKeys as $k) {
            $scanOptions[$k] = (bool) $input->getInt('batch_opt_' . $k, 0);
        }
        leAuditSessionSet('le_scan_options', $scanOptions);
    }

    $processed    = 0;
    $newUrlsFound = 0;
    $batchLimit   = (int) $config['batch_limit'];

    // Ensure ext queue fields exist for sessions created before this feature
    if (!isset($queue['ext_pending']))        { $queue['ext_pending']        = []; }
    if (!isset($queue['ext_seen']))           { $queue['ext_seen']           = []; }
    if (!isset($queue['ext_referrers']))      { $queue['ext_referrers']      = []; }

    // Ensure int-link HEAD-check queue fields exist for sessions created before this feature
    if (!isset($queue['int_link_pending']))   { $queue['int_link_pending']   = []; }
    if (!isset($queue['int_link_seen']))      { $queue['int_link_seen']      = []; }
    if (!isset($queue['int_link_referrers'])) { $queue['int_link_referrers'] = []; }

    while ($processed < $batchLimit && !empty($queue['pending'])) {
        $url        = array_shift($queue['pending']);
        $normalized = leAuditNormalizeUrl($url, $config);

        if ($normalized === '') {
            continue;
        }

        if (isset($queue['scanned'][$normalized])) {
            continue;
        }

        $result              = leAuditCheckUrl($normalized, $config, $scanOptions);
        $discoveredLinks     = $result['discovered_links'];
        $discoveredExtLinks  = $result['discovered_external_links'] ?? [];
        $discoveredImages    = $result['discovered_images'] ?? [];
        unset($result['discovered_links'], $result['discovered_external_links'], $result['discovered_images']);

        $hasIssues   = !empty($result['issues']);
        $hasWarnings = !empty($result['warnings']);
        $isPassing   = !$hasIssues && !$hasWarnings;

        // Optionally skip storing passed items to reduce session size
        if ($isPassing && !($scanOptions['store_passed_pages'] ?? false)) {
            $results['passed_pages_count'] = ($results['passed_pages_count'] ?? 0) + 1;
        } else {
            $result['source_page'] = $queue['referrers'][$normalized] ?? '';
            $results['items'][$normalized] = $result;
        }
        $queue['scanned'][$normalized] = true;

        $newUrlsFound += leAuditAddUrlsToQueue($queue, $discoveredLinks, $config, $normalized);

        // Queue external links and images for HEAD-check (only if those checks are enabled)
        $extAssets = [];
        if ($scanOptions['check_broken_external'] ?? true) {
            $extAssets = array_merge($extAssets, $discoveredExtLinks);
        }
        if ($scanOptions['check_broken_images'] ?? true) {
            $extAssets = array_merge($extAssets, $discoveredImages);
        }
        foreach ($extAssets as $extUrl) {
            if (!isset($queue['ext_seen'][$extUrl]) && count($queue['ext_seen']) < 10000) {
                $queue['ext_pending'][]          = $extUrl;
                $queue['ext_seen'][$extUrl]      = true;
                $queue['ext_referrers'][$extUrl] = $normalized;
            }
        }

        // Queue internal links for dedicated HEAD-check (proactive; separate from main crawl)
        if ($scanOptions['check_broken_internal_links'] ?? true) {
            foreach ($discoveredLinks as $intLinkUrl) {
                if (!isset($queue['int_link_seen'][$intLinkUrl]) && count($queue['int_link_seen']) < 20000) {
                    $queue['int_link_pending'][]              = $intLinkUrl;
                    $queue['int_link_seen'][$intLinkUrl]      = true;
                    $queue['int_link_referrers'][$intLinkUrl] = $normalized;
                }
            }
        }

        // Record this URL as a broken link if it came from a known source page
        if ($scanOptions['check_broken_internal'] ?? true) {
            $isBroken = ($result['status'] === 0 || $result['status'] === 404
                         || ($result['status'] >= 400 && $result['status'] < 600));
            if ($isBroken) {
                $referrer = $queue['referrers'][$normalized] ?? '';
                if ($referrer !== '') {
                    if (!isset($results['broken_links'])) {
                        $results['broken_links'] = [];
                    }
                    $results['broken_links'][] = [
                        'source_page' => $referrer,
                        'broken_url'  => $normalized,
                        'status'      => $result['status'],
                        'found_at'    => gmdate('c'),
                        'link_type'   => 'internal',
                    ];
                }
            }
        }

        $processed++;
    }

    // Check external links and images (up to 10 per batch via fast HEAD requests)
    $extChecked = 0;
    while ($extChecked < 10 && !empty($queue['ext_pending'])) {
        $extUrl    = array_shift($queue['ext_pending']);
        $extStatus = leAuditHeadCheckUrl($extUrl);
        $isBroken  = ($extStatus === 0 || $extStatus === 404
                      || ($extStatus >= 400 && $extStatus < 600));
        if ($isBroken) {
            $extReferrer = $queue['ext_referrers'][$extUrl] ?? '';
            if ($extReferrer !== '') {
                if (!isset($results['broken_links'])) {
                    $results['broken_links'] = [];
                }
                $isImage = (bool) preg_match('#\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)(\?.*)?$#i', $extUrl);
                $results['broken_links'][] = [
                    'source_page' => $extReferrer,
                    'broken_url'  => $extUrl,
                    'status'      => $extStatus,
                    'found_at'    => gmdate('c'),
                    'link_type'   => $isImage ? 'image' : 'external',
                ];
            }
        }
        $extChecked++;
    }

    // HEAD-check internal links found on pages (up to 10 per batch; separate from main crawl)
    $intLinkChecked = 0;
    while ($intLinkChecked < 10 && !empty($queue['int_link_pending'])) {
        $intLinkUrl = array_shift($queue['int_link_pending']);

        // Skip URLs already fully crawled — check_broken_internal handles those
        if (isset($queue['scanned'][$intLinkUrl])) {
            $intLinkChecked++;
            continue;
        }

        $intLinkStatus = leAuditHeadCheckUrl($intLinkUrl);
        $isBroken      = ($intLinkStatus === 0 || $intLinkStatus === 404
                          || ($intLinkStatus >= 400 && $intLinkStatus < 600));
        if ($isBroken) {
            $intLinkReferrer = $queue['int_link_referrers'][$intLinkUrl] ?? '';
            if ($intLinkReferrer !== '') {
                if (!isset($results['broken_links'])) {
                    $results['broken_links'] = [];
                }
                $results['broken_links'][] = [
                    'source_page' => $intLinkReferrer,
                    'broken_url'  => $intLinkUrl,
                    'status'      => $intLinkStatus,
                    'found_at'    => gmdate('c'),
                    'link_type'   => 'internal',
                ];
            }
        }
        $intLinkChecked++;
    }

    $results['summary']    = leAuditBuildSummary($results);
    $results['updated_at'] = gmdate('c');
    $queue['updated_at']   = gmdate('c');
    leAuditSessionSet('le_queue', $queue);
    leAuditSessionSet('le_results', $results);

    $pendingAfter       = count($queue['pending']);
    $extPendingAfter    = count($queue['ext_pending'] ?? []);
    $intLinkPendingAfter = count($queue['int_link_pending'] ?? []);
    $message = 'Batch complete. Scanned ' . (int) $processed . ' pages. '
             . (int) $newUrlsFound . ' new pages discovered. '
             . (int) $pendingAfter . ' pages still pending. '
             . (int) $extChecked . ' external asset(s) checked, '
             . (int) $extPendingAfter . ' still queued. '
             . (int) $intLinkChecked . ' internal link(s) HEAD-checked, '
             . (int) $intLinkPendingAfter . ' still queued.';

    if ($action === 'scan_batch_ajax') {
        $ajaxPendingCount = count($queue['pending'] ?? []);
        $ajaxSeenCount    = count($queue['seen']    ?? []);
        $ajaxScannedCount = count($queue['scanned'] ?? []);
        $ajaxProgressPct  = ($ajaxSeenCount > 0)
            ? min(100, (int) round(($ajaxScannedCount / $ajaxSeenCount) * 100))
            : 0;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode([
            'ok'           => true,
            'pending'      => $ajaxPendingCount,
            'scanned'      => $ajaxScannedCount,
            'seen'         => $ajaxSeenCount,
            'progress_pct' => $ajaxProgressPct,
            'message'      => $message,
            'done'         => ($ajaxPendingCount === 0),
        ]);
        exit;
    }
} elseif ($action === 'export_csv') {
    $results['summary'] = leAuditBuildSummary($results);
    leAuditExportCsv($results); // streams CSV and exits
}

// ── Reload state for display ─────────────────────────────────────────────────
$results = leAuditSessionGet('le_results', []);
$queue   = leAuditSessionGet('le_queue', []);
$summary = leAuditBuildSummary($results);

$pendingCount = !empty($queue['pending']) ? count($queue['pending']) : 0;
$seenCount    = !empty($queue['seen'])    ? count($queue['seen'])    : 0;
$scannedCount = !empty($queue['scanned']) ? count($queue['scanned']) : 0;
$progressPct  = ($seenCount > 0) ? min(100, (int) round(($scannedCount / $seenCount) * 100)) : 0;

$criticalItems    = [];
$warningItems     = [];
$passedItems      = [];
$recentItems      = [];
$brokenLinkItems  = [];

if (!empty($results['items'])) {
    $allItems = array_values($results['items']);

    foreach ($allItems as $item) {
        if (!empty($item['issues'])) {
            $criticalItems[] = $item;
        } elseif (!empty($item['warnings'])) {
            $warningItems[] = $item;
        } else {
            $passedItems[] = $item;
        }
    }

    usort($allItems, fn($a, $b) => strcmp($b['scanned_at'] ?? '', $a['scanned_at'] ?? ''));
    $recentItems = array_slice($allItems, 0, 25);
}

$criticalItems   = array_slice($criticalItems, 0, 100);
$warningItems    = array_slice($warningItems, 0, 100);
$passedItems     = array_slice($passedItems, 0, 100);

if (!empty($results['broken_links'])) {
    $brokenLinkItems = array_slice($results['broken_links'], 0, 500);
}

$baseUrl = Uri::current();
$token   = Session::getFormToken();
?>

[[style]]
.le-audit-wrap {
    max-width: 1280px;
    margin: 32px auto;
    padding: 0 18px;
    font-family: Arial, Helvetica, sans-serif;
    color: #172033;
}

.le-audit-card {
    background: #ffffff;
    border: 1px solid #dfe5ef;
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 18px;
    box-shadow: 0 8px 24px rgba(23, 32, 51, 0.06);
}

.le-audit-title {
    font-size: 30px;
    line-height: 1.2;
    margin: 0 0 8px;
    color: #101828;
}

.le-audit-subtitle {
    font-size: 16px;
    line-height: 1.6;
    color: #526070;
    margin: 0;
}

.le-audit-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
}

.le-audit-button {
    appearance: none;
    border: 0;
    border-radius: 999px;
    padding: 12px 18px;
    font-weight: 700;
    cursor: pointer;
    background: #1a73e8;
    color: #ffffff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    font-size: 15px;
}

.le-audit-button:hover,
.le-audit-button:focus {
    background: #1558b0;
    color: #ffffff;
}

.le-audit-button.secondary {
    background: #eef4ff;
    color: #174ea6;
}

.le-audit-button.secondary:hover,
.le-audit-button.secondary:focus {
    background: #dbeafe;
    color: #174ea6;
}

.le-audit-button.danger {
    background: #b42318;
    color: #ffffff;
}

.le-audit-button:disabled { opacity: .6; cursor: default; }

.le-audit-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.le-audit-metric {
    background: #f8fafc;
    border: 1px solid #e5eaf2;
    border-radius: 14px;
    padding: 16px;
}

.le-audit-metric strong {
    display: block;
    font-size: 28px;
    color: #101828;
    margin-bottom: 4px;
}

.le-audit-metric span {
    display: block;
    color: #667085;
    font-size: 14px;
}

.le-audit-alert {
    padding: 14px 16px;
    border-radius: 14px;
    margin: 16px 0 0;
    background: #fff7e6;
    border: 1px solid #ffd591;
    color: #7a4b00;
    font-weight: 700;
    word-break: break-word;
}

.le-audit-table-wrap {
    overflow-x: auto;
    border: 1px solid #e5eaf2;
    border-radius: 14px;
}

.le-audit-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
}

.le-audit-table th,
.le-audit-table td {
    text-align: left;
    vertical-align: top;
    padding: 12px;
    border-bottom: 1px solid #e5eaf2;
    font-size: 14px;
    line-height: 1.45;
}

.le-audit-table th {
    background: #f8fafc;
    font-weight: 800;
    color: #344054;
}

.le-audit-url { max-width: 340px; word-break: break-word; }

.le-audit-pill {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
}

.le-audit-pill.critical { background: #fee4e2; color: #b42318; }
.le-audit-pill.warning  { background: #fff7e6; color: #946200; }
.le-audit-pill.pass     { background: #dcfae6; color: #067647; }

.le-audit-small { color: #667085; font-size: 13px; line-height: 1.5; }

.le-audit-code {
    background: #101828;
    color: #f9fafb;
    padding: 12px;
    border-radius: 12px;
    overflow-x: auto;
    font-size: 13px;
}

.le-audit-progress-bar-wrap {
    background: #e5eaf2;
    border-radius: 999px;
    height: 18px;
    overflow: hidden;
    margin: 10px 0 6px;
}

.le-audit-progress-bar {
    height: 18px;
    border-radius: 999px;
    background: #1a73e8;
    transition: width 0.4s ease;
}

.le-audit-progress-label {
    font-size: 14px;
    font-weight: 700;
    color: #344054;
}

@media (max-width: 900px) {
    .le-audit-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 560px) {
    .le-audit-grid    { grid-template-columns: 1fr; }
    .le-audit-title   { font-size: 24px; }
    .le-audit-button  { width: 100%; }
}
[[/style]]

[[div class="le-audit-wrap"]]

    [[section class="le-audit-card"]]
        [[h1 class="le-audit-title"]]LottoExpert Full Site Audit Scanner[[/h1]]
        [[p class="le-audit-subtitle"]]
            Private crawler for LottoExpert. Discovers internal URLs from the sitemap (including nested sitemap index files), crawls internal links, scans 10 URLs per batch, auto-continues without timeouts, and reports SEO, crawlability, metadata, canonical, H1, noindex, image alt, speed, and broken-page issues.
        [[/p]]

        [[div style="margin-top:18px;border:1px solid #d0d7de;border-radius:8px;padding:16px 20px;"]]
            [[p style="margin:0 0 4px;font-weight:700;font-size:15px;color:#1a73e8;"]]&#9881; Scan Options[[/p]]
            [[form method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" style="margin-top:10px;"]]
                [[input type="hidden" name="audit_action" value="save_options"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[p class="le-audit-small" style="margin:0 0 10px;"]]
                    Select which checks to run. Disabling checks you don&rsquo;t need makes each batch faster and prevents gateway timeouts on large sites.
                [[/p]]
                [[div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px 24px;margin-bottom:14px;"]]
                    <?php
                    $opts = [
                        ['opt_broken_internal',       'check_broken_internal',       '🔗 Broken Internal Pages',       'Flag crawled pages that return 404/5xx'],
                        ['opt_broken_internal_links', 'check_broken_internal_links', '🔍 Internal Link Checker',        'HEAD-check internal links found on each page for broken destinations'],
                        ['opt_broken_external',       'check_broken_external',       '🌐 Broken External Links',        'HEAD-check outbound links to other sites'],
                        ['opt_broken_images',         'check_broken_images',         '🖼 Missing Images',               'HEAD-check image src URLs (logos, photos)'],
                        ['opt_seo',                   'check_seo',                   '📄 SEO Checks',                   'Title, meta description, canonical, H1, noindex'],
                        ['opt_speed',                 'check_speed',                 '⚡ Page Speed',                   'Flag pages loading over 3 seconds'],
                        ['opt_alt_text',              'check_alt_text',              '♿ Alt Text',                     'Flag images missing alt attribute'],
                        ['opt_store_passed',          'store_passed_pages',          '✅ Store Passed Pages',           'Keep full data for pages with no issues (uses more memory)'],
                    ];
                    foreach ($opts as [$fieldName, $optKey, $label, $desc]) :
                        $checked = ($scanOptions[$optKey] ?? false) ? ' checked' : '';
                    ?>
                    [[label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;"]]
                        [[input type="checkbox" name="<?php echo $fieldName; ?>" value="1"<?php echo $checked; ?> style="margin-top:3px;flex-shrink:0;"]]
                        [[span]][[strong]]<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>[[/strong]] [[span class="le-audit-small"]]<?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?>[[/span]][[/span]]
                    [[/label]]
                    <?php endforeach; ?>
                [[/div]]
                [[button class="le-audit-button secondary" type="submit" style="font-size:14px;padding:8px 16px;"]]Save Options[[/button]]
                [[span id="leOptsSaved" style="display:none;margin-left:12px;font-weight:700;color:#1a6b2a;"]]&#10003; Options saved[[/span]]
            [[/form]]
        [[/div]]

        <?php if ($message !== '') : ?>
            [[div class="le-audit-alert"]]<?php echo nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')); ?>[[/div]]
        <?php endif; ?>

        [[div class="le-audit-actions"]]
            [[form id="leAutoScanForm" method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"]]
                [[input type="hidden" name="audit_action" value="scan_batch"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[button class="le-audit-button" type="submit"]]Scan Next 10 URLs[[/button]]
            [[/form]]

            [[button id="leBtnAutoScan" class="le-audit-button secondary" type="button"]]Auto Scan Until Finished[[/button]]

            [[button id="leBtnStopScan" class="le-audit-button secondary" type="button" style="display:none;"]]⏹ Stop Auto Scan[[/button]]

            [[form id="leStopAllForm" method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return leStopAllConfirm();"]]
                [[input type="hidden" name="audit_action" value="stop_all"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[button class="le-audit-button danger" type="submit"]]🛑 Stop All[[/button]]
            [[/form]]

            [[form method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"]]
                [[input type="hidden" name="audit_action" value="discover_sitemap"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[button class="le-audit-button secondary" type="submit"]]Discover Sitemap URLs[[/button]]
            [[/form]]

            [[form method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>"]]
                [[input type="hidden" name="audit_action" value="export_csv"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[button class="le-audit-button secondary" type="submit"]]Export CSV[[/button]]
            [[/form]]

            [[form method="post" action="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Reset the full audit queue and results?');"]]
                [[input type="hidden" name="audit_action" value="reset"]]
                [[input type="hidden" name="<?php echo $token; ?>" value="1"]]
                [[button class="le-audit-button danger" type="submit"]]Reset Audit[[/button]]
            [[/form]]
        [[/div]]
    [[/section]]

    [[section class="le-audit-card"]]
        [[h2]]Scan Progress[[/h2]]
        [[div class="le-audit-progress-bar-wrap"]]
            [[div id="leProgressBar" class="le-audit-progress-bar" style="width:<?php echo (int) $progressPct; ?>%"]][[/div]]
        [[/div]]
        [[p id="leProgressLabel" class="le-audit-progress-label"]]
            <?php echo (int) $progressPct; ?>% complete &mdash;
            <?php echo (int) $scannedCount; ?> scanned of
            <?php echo (int) $seenCount; ?> discovered &mdash;
            <?php echo (int) $pendingCount; ?> pending
        [[/p]]
    [[/section]]

    [[section class="le-audit-card"]]
        [[h2]]Audit Overview[[/h2]]
        [[div class="le-audit-grid"]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $scannedCount; ?>[[/strong]][[span]]URLs Scanned[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $pendingCount; ?>[[/strong]][[span]]URLs Pending[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $seenCount; ?>[[/strong]][[span]]Total Discovered[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['critical_pages']; ?>[[/strong]][[span]]Critical Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['warning_pages']; ?>[[/strong]][[span]]Warning Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['passed_pages']; ?>[[/strong]][[span]]Passed Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['missing_titles']; ?>[[/strong]][[span]]Missing Titles[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['missing_descriptions']; ?>[[/strong]][[span]]Missing Descriptions[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['not_found_pages']; ?>[[/strong]][[span]]404 Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['redirect_pages']; ?>[[/strong]][[span]]Redirect Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['missing_canonicals']; ?>[[/strong]][[span]]Missing Canonicals[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['noindex_pages']; ?>[[/strong]][[span]]Noindex Pages[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['slow_pages']; ?>[[/strong]][[span]]Slow Pages (&gt;3 s)[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['images_missing_alt']; ?>[[/strong]][[span]]Pages w/ Missing Alt[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['avg_load_time_ms']; ?> ms[[/strong]][[span]]Avg Load Time[[/span]][[/div]]
            [[div class="le-audit-metric"]][[strong]]<?php echo (int) $summary['broken_links_total']; ?>[[/strong]][[span]]Broken Links &amp; Missing Images[[/span]][[/div]]
        [[/div]]
    [[/section]]

    [[section id="leAutorunRunning" class="le-audit-card" style="display:none"]]
        [[h2]]Auto Scan Running[[/h2]]
        [[p class="le-audit-small"]]
            Auto scan is active &mdash; [[span id="leAutorunPending"]][[/span]] URLs still pending.
            Leave this tab open until the progress bar reaches 100%.
        [[/p]]
    [[/section]]

    [[section id="leAutorunDone" class="le-audit-card" style="display:none"]]
        [[h2]]Auto Scan Complete[[/h2]]
        [[p class="le-audit-small"]]All discovered URLs have been scanned. Review the results below.[[/p]]
    [[/section]]

    [[section class="le-audit-card"]]
        [[h2]]Recently Scanned (last 25)[[/h2]]
        <?php if (empty($recentItems)) : ?>
            [[p class="le-audit-small"]]No URLs scanned yet. Run a batch scan to see results here.[[/p]]
        <?php else : ?>
            [[div class="le-audit-table-wrap"]]
                [[table class="le-audit-table"]]
                    [[thead]]
                        [[tr]]
                            [[th]]Status[[/th]]
                            [[th]]URL[[/th]]
                            [[th]]Result[[/th]]
                            [[th]]Load[[/th]]
                            [[th]]Scanned At[[/th]]
                        [[/tr]]
                    [[/thead]]
                    [[tbody]]
                        <?php foreach ($recentItems as $item) : ?>
                            <?php
                            if (!empty($item['issues'])) {
                                $pc = 'critical'; $pl = 'Issues';
                            } elseif (!empty($item['warnings'])) {
                                $pc = 'warning'; $pl = 'Warnings';
                            } else {
                                $pc = 'pass'; $pl = 'Pass';
                            }
                            ?>
                            [[tr]]
                                [[td]][[span class="le-audit-pill <?php echo $pc; ?>"]]<?php echo (int) $item['status']; ?>[[/span]][[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td]][[span class="le-audit-pill <?php echo $pc; ?>"]]<?php echo htmlspecialchars($pl, ENT_QUOTES, 'UTF-8'); ?>[[/span]][[/td]]
                                [[td]]<?php echo (int) $item['load_time_ms']; ?> ms[[/td]]
                                [[td class="le-audit-small"]]<?php echo htmlspecialchars($item['scanned_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                            [[/tr]]
                        <?php endforeach; ?>
                    [[/tbody]]
                [[/table]]
            [[/div]]
        <?php endif; ?>
    [[/section]]

    <?php if ($scanOptions['check_seo'] || $scanOptions['check_broken_internal'] || $scanOptions['check_speed'] || $scanOptions['check_alt_text']) : ?>
    [[section class="le-audit-card"]]
        [[h2]]Critical Issues[[/h2]]
        <?php if (empty($criticalItems)) : ?>
            [[p]][[span class="le-audit-pill pass"]]PASS[[/span]] No critical issues found yet.[[/p]]
        <?php else : ?>
            [[div class="le-audit-table-wrap"]]
                [[table class="le-audit-table"]]
                    [[thead]]
                        [[tr]]
                            [[th]]Status[[/th]][[th]]URL[[/th]][[th]]Issues[[/th]]
                            [[th]]Found On (source page)[[/th]]
                            [[th]]Title[[/th]][[th]]H1 Text[[/th]][[th]]Meta Description[[/th]]
                            [[th]]Canonical[[/th]][[th]]Load[[/th]]
                        [[/tr]]
                    [[/thead]]
                    [[tbody]]
                        <?php foreach ($criticalItems as $item) : ?>
                            [[tr]]
                                [[td]][[span class="le-audit-pill critical"]]<?php echo (int) $item['status']; ?>[[/span]][[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td]]<?php echo htmlspecialchars(implode(' | ', $item['issues']), ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td class="le-audit-url"]]<?php
                                    $srcPage = $item['source_page'] ?? '';
                                    if ($srcPage !== '') {
                                        echo '[[a href="' . htmlspecialchars($srcPage, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener"]]' . htmlspecialchars($srcPage, ENT_QUOTES, 'UTF-8') . '[[/a]]';
                                    } else {
                                        echo '[[span class="le-audit-small" style="color:#888;"]]—[[/span]]';
                                    }
                                ?>[[/td]]
                                [[td]]<?php echo htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td]]<?php echo htmlspecialchars($item['h1_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td]]<?php echo htmlspecialchars($item['meta_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td class="le-audit-url"]]<?php echo htmlspecialchars($item['canonical'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td]]<?php echo (int) $item['load_time_ms']; ?> ms[[/td]]
                            [[/tr]]
                        <?php endforeach; ?>
                    [[/tbody]]
                [[/table]]
            [[/div]]
        <?php endif; ?>
    [[/section]]
    <?php endif; ?>

    <?php if ($scanOptions['check_broken_internal'] || ($scanOptions['check_broken_internal_links'] ?? false) || $scanOptions['check_broken_external'] || $scanOptions['check_broken_images']) : ?>
    [[section class="le-audit-card"]]
        [[h2]]Broken Links &amp; Missing Images Found[[/h2]]
        [[p class="le-audit-small"]]
            These are links and images that the crawler found on your site but that returned an error when fetched.
            The <strong>Type</strong> column tells you what kind of problem it is: an internal link on your own site that is broken, a link to an external website that is returning an error, or an image file (such as a logo or photo) that could not be loaded.
            The <strong>Page On Your Site</strong> column shows exactly which page you need to edit to fix or remove the bad link or image.
            The <strong>Error Code</strong> explains what kind of failure occurred (see key below).
        [[/p]]
        [[p class="le-audit-small"]]
            [[strong]]Error code key:[[/strong]]
            [[strong]]404[[/strong]] = Not Found (the file or page no longer exists) &mdash;
            [[strong]]410[[/strong]] = Gone (permanently deleted) &mdash;
            [[strong]]500[[/strong]] = Server Error (the destination server crashed) &mdash;
            [[strong]]503[[/strong]] = Service Unavailable (server overloaded or down) &mdash;
            [[strong]]0[[/strong]] = Connection Failed (the domain could not be reached at all).
        [[/p]]
        <?php if (empty($brokenLinkItems)) : ?>
            [[p]][[span class="le-audit-pill pass"]]PASS[[/span]] No broken links or missing images detected yet.[[/p]]
        <?php else : ?>
            [[div class="le-audit-table-wrap"]]
                [[table class="le-audit-table"]]
                    [[thead]]
                        [[tr]]
                            [[th]]Type[[/th]]
                            [[th]]Error Code[[/th]]
                            [[th]]Page On Your Site (go here to fix or remove the problem)[[/th]]
                            [[th]]Broken URL / Missing Image (the bad address)[[/th]]
                            [[th]]When Detected[[/th]]
                        [[/tr]]
                    [[/thead]]
                    [[tbody]]
                        <?php foreach ($brokenLinkItems as $bl) : ?>
                            <?php
                                $statusCode = (int) $bl['status'];
                                $statusLabel = match(true) {
                                    $statusCode === 0   => '0 – Connection Failed',
                                    $statusCode === 400 => '400 – Bad Request',
                                    $statusCode === 401 => '401 – Unauthorised',
                                    $statusCode === 403 => '403 – Forbidden',
                                    $statusCode === 404 => '404 – Not Found',
                                    $statusCode === 405 => '405 – Method Not Allowed',
                                    $statusCode === 410 => '410 – Gone (Deleted)',
                                    $statusCode === 429 => '429 – Too Many Requests',
                                    $statusCode === 500 => '500 – Server Error',
                                    $statusCode === 502 => '502 – Bad Gateway',
                                    $statusCode === 503 => '503 – Service Unavailable',
                                    $statusCode === 504 => '504 – Gateway Timeout',
                                    $statusCode >= 400 && $statusCode < 500 => $statusCode . ' – Client Error',
                                    $statusCode >= 500 => $statusCode . ' – Server Error',
                                    default             => (string) $statusCode,
                                };
                                $foundAt = '';
                                if (!empty($bl['found_at'])) {
                                    $ts = strtotime($bl['found_at']);
                                    $foundAt = $ts ? date('d M Y, H:i', $ts) . ' UTC' : $bl['found_at'];
                                }
                                $linkType = $bl['link_type'] ?? 'internal';
                                $typeLabel = match($linkType) {
                                    'image'    => '🖼 Missing Image',
                                    'external' => '🔗 External Link',
                                    default    => '🔗 Internal Link',
                                };
                            ?>
                            [[tr]]
                                [[td]]<?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td]][[span class="le-audit-pill critical"]]<?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>[[/span]][[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($bl['source_page'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($bl['source_page'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($bl['broken_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($bl['broken_url'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td class="le-audit-small"]]<?php echo htmlspecialchars($foundAt, ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                            [[/tr]]
                        <?php endforeach; ?>
                    [[/tbody]]
                [[/table]]
            [[/div]]
        <?php endif; ?>
    [[/section]]
    <?php endif; ?>

    <?php if ($scanOptions['check_seo'] || $scanOptions['check_speed'] || $scanOptions['check_alt_text']) : ?>
    [[section class="le-audit-card"]]
        [[h2]]Warnings[[/h2]]
        [[p class="le-audit-small"]]
            These pages loaded successfully but have one or more SEO or performance issues that should be fixed. Each warning below explains exactly what the problem is and what you need to do to correct it.
        [[/p]]
        <?php if (empty($warningItems)) : ?>
            [[p]][[span class="le-audit-pill pass"]]PASS[[/span]] No warnings found yet.[[/p]]
        <?php else : ?>
            [[div class="le-audit-table-wrap"]]
                [[table class="le-audit-table"]]
                    [[thead]]
                        [[tr]]
                            [[th]]Status[[/th]][[th]]Page URL[[/th]][[th]]Warning Details (what is wrong and how to fix it)[[/th]]
                            [[th]]Title Length[[/th]][[th]]Desc. Length[[/th]]
                            [[th]]H1 Count[[/th]][[th]]Imgs No Alt[[/th]][[th]]Load[[/th]]
                        [[/tr]]
                    [[/thead]]
                    [[tbody]]
                        <?php foreach ($warningItems as $item) : ?>
                            [[tr]]
                                [[td]][[span class="le-audit-pill warning"]]<?php echo (int) $item['status']; ?>[[/span]][[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td]]
                                    [[ul style="margin:0;padding-left:18px;"]]
                                        <?php foreach ($item['warnings'] as $w) : ?>
                                            [[li]]<?php echo htmlspecialchars($w, ENT_QUOTES, 'UTF-8'); ?>[[/li]]
                                        <?php endforeach; ?>
                                    [[/ul]]
                                [[/td]]
                                [[td]]<?php echo (int) $item['title_length']; ?>[[/td]]
                                [[td]]<?php echo (int) $item['meta_description_length']; ?>[[/td]]
                                [[td]]<?php echo (int) $item['h1_count']; ?>[[/td]]
                                [[td]]<?php echo (int) ($item['images_without_alt'] ?? 0); ?>[[/td]]
                                [[td]]<?php echo (int) $item['load_time_ms']; ?> ms[[/td]]
                            [[/tr]]
                        <?php endforeach; ?>
                    [[/tbody]]
                [[/table]]
            [[/div]]
        <?php endif; ?>
    [[/section]]
    <?php endif; ?>

    <?php if ($scanOptions['store_passed_pages'] ?? false) : ?>
    [[section class="le-audit-card"]]
        [[h2]]Passed Pages (no issues, no warnings) &mdash; showing up to 100[[/h2]]
        <?php if (empty($passedItems)) : ?>
            [[p class="le-audit-small"]]No passed pages recorded yet.[[/p]]
        <?php else : ?>
            [[div class="le-audit-table-wrap"]]
                [[table class="le-audit-table"]]
                    [[thead]]
                        [[tr]]
                            [[th]]Status[[/th]][[th]]URL[[/th]][[th]]Title[[/th]][[th]]Load[[/th]][[th]]Scanned At[[/th]]
                        [[/tr]]
                    [[/thead]]
                    [[tbody]]
                        <?php foreach ($passedItems as $item) : ?>
                            [[tr]]
                                [[td]][[span class="le-audit-pill pass"]]<?php echo (int) $item['status']; ?>[[/span]][[/td]]
                                [[td class="le-audit-url"]]
                                    [[a href="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"]]
                                        <?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>
                                    [[/a]]
                                [[/td]]
                                [[td]]<?php echo htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                                [[td]]<?php echo (int) $item['load_time_ms']; ?> ms[[/td]]
                                [[td class="le-audit-small"]]<?php echo htmlspecialchars($item['scanned_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>[[/td]]
                            [[/tr]]
                        <?php endforeach; ?>
                    [[/tbody]]
                [[/table]]
            [[/div]]
        <?php endif; ?>
    [[/section]]
    <?php endif; ?>

    [[section class="le-audit-card"]]
        [[h2]]CSV Export[[/h2]]
        [[p class="le-audit-small"]]
            Use the &ldquo;Export CSV&rdquo; button above to download all scanned results as a CSV file directly to your computer.
        [[/p]]
    [[/section]]

[[/div]]

[[div id="leAuditMeta" data-pending="<?php echo (int) $pendingCount; ?>" style="display:none"]][[/div]]
[[script]]
(function () {
    var AUTORUN_KEY = 'leAuditAutorun';
    var SCROLL_KEY  = 'leAuditScrollY';
    var LS_OPT_KEY  = 'leAuditScanOptions';
    var meta    = document.getElementById('leAuditMeta');
    var pending = meta ? parseInt(meta.getAttribute('data-pending') || '0', 10) : 0;

    // ── Scan options: field-name → option-key mapping ──────────────────────
    var leOptMap = {
        'opt_broken_internal':       'check_broken_internal',
        'opt_broken_internal_links': 'check_broken_internal_links',
        'opt_broken_external':       'check_broken_external',
        'opt_broken_images':         'check_broken_images',
        'opt_seo':                   'check_seo',
        'opt_speed':                 'check_speed',
        'opt_alt_text':              'check_alt_text',
        'opt_store_passed':          'store_passed_pages'
    };

    // Read current checkbox states into an options object
    function leGetOpts() {
        var opts = {};
        Object.keys(leOptMap).forEach(function (fn) {
            var el = document.querySelector('input[name="' + fn + '"]');
            opts[leOptMap[fn]] = el ? el.checked : true;
        });
        return opts;
    }

    // Apply an options object to the checkboxes on the page
    function leApplyOpts(opts) {
        Object.keys(leOptMap).forEach(function (fn) {
            var key = leOptMap[fn];
            var el  = document.querySelector('input[name="' + fn + '"]');
            if (el && opts.hasOwnProperty(key)) { el.checked = !!opts[key]; }
        });
    }

    // Inject batch_opt_* hidden fields into the batch form so PHP receives them
    function leInjectBatchOpts(form, opts) {
        form.querySelectorAll('input[data-le-batch-opt]').forEach(function (el) {
            el.parentNode.removeChild(el);
        });
        Object.keys(opts).forEach(function (k) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'batch_opt_' + k;
            inp.value = opts[k] ? '1' : '0';
            inp.setAttribute('data-le-batch-opt', '1');
            form.appendChild(inp);
        });
    }

    // Load opts from localStorage (or fall back to current checkboxes)
    function leLoadOpts() {
        try {
            var raw = localStorage.getItem(LS_OPT_KEY);
            return raw ? JSON.parse(raw) : leGetOpts();
        } catch (e) { return leGetOpts(); }
    }

    // Submit the batch form, always injecting the stored opts first.
    // NOTE: form.submit() does NOT fire the submit event, so we must call
    // leInjectBatchOpts explicitly here rather than relying on a submit listener.
    function leSubmitBatchForm(form) {
        leInjectBatchOpts(form, leLoadOpts());
        form.submit();
    }

    // ── On page load: apply stored options to the checkboxes ──────────────
    try {
        var stored = localStorage.getItem(LS_OPT_KEY);
        if (stored) { leApplyOpts(JSON.parse(stored)); }
    } catch (e) {}

    // ── Save Options form: save to localStorage + show inline confirmation ──
    var saveOptsInput = document.querySelector('input[name="audit_action"][value="save_options"]');
    if (saveOptsInput) {
        saveOptsInput.closest('form').addEventListener('submit', function () {
            var opts = leGetOpts();
            try { localStorage.setItem(LS_OPT_KEY, JSON.stringify(opts)); } catch (e) {}
            var msg = document.getElementById('leOptsSaved');
            if (msg) {
                msg.style.display = 'inline';
                setTimeout(function () { msg.style.display = 'none'; }, 4000);
            }
        });
    }

    // ── "Scan Next 10" button — inject opts on real submit-event click ─────
    var batchForm = document.getElementById('leAutoScanForm');
    if (batchForm) {
        batchForm.addEventListener('submit', function () {
            leInjectBatchOpts(this, leLoadOpts());
        });
    }

    // ── Button loading states ──────────────────────────────────────────────
    var buttons = document.querySelectorAll('.le-audit-button');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            if (!this.className.match(/danger/) && this.id !== 'leBtnAutoScan' && this.id !== 'leBtnStopScan') {
                this.innerHTML = 'Working\u2026';
                this.disabled = true;
            }
        });
    }

    // ── Progress bar live update ───────────────────────────────────────────
    function leUpdateProgress(pct, scanned, seen, pendingN) {
        var bar   = document.getElementById('leProgressBar');
        var label = document.getElementById('leProgressLabel');
        if (bar)   { bar.style.width = pct + '%'; }
        if (label) {
            label.textContent = pct + '% complete \u2014 '
                + scanned + ' scanned of '
                + seen    + ' discovered \u2014 '
                + pendingN + ' pending';
        }
    }

    // ── AJAX batch runner ──────────────────────────────────────────────────
    var runningBanner = document.getElementById('leAutorunRunning');
    var doneBanner    = document.getElementById('leAutorunDone');
    var pendingSpan   = document.getElementById('leAutorunPending');

    function leRunAjaxBatch() {
        if (sessionStorage.getItem(AUTORUN_KEY) !== '1') { return; }
        var ajaxForm = document.getElementById('leAutoScanForm');
        if (!ajaxForm) { return; }
        var opts = leLoadOpts();
        var fd   = new FormData(ajaxForm);
        fd.set('audit_action', 'scan_batch_ajax');
        Object.keys(opts).forEach(function (k) {
            fd.set('batch_opt_' + k, opts[k] ? '1' : '0');
        });
        fetch(ajaxForm.action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                leUpdateProgress(data.progress_pct, data.scanned, data.seen, data.pending);
                if (pendingSpan) { pendingSpan.textContent = data.pending; }
                if (sessionStorage.getItem(AUTORUN_KEY) !== '1') { return; }
                if (data.done || data.pending <= 0) {
                    sessionStorage.removeItem(AUTORUN_KEY);
                    sessionStorage.removeItem(SCROLL_KEY);
                    if (runningBanner) { runningBanner.style.display = 'none'; }
                    if (doneBanner)    { doneBanner.style.display = ''; }
                    var ab = document.getElementById('leBtnAutoScan');
                    var sb = document.getElementById('leBtnStopScan');
                    if (ab) { ab.innerHTML = 'Auto Scan Until Finished'; ab.disabled = false; }
                    if (sb) { sb.style.display = 'none'; }
                    return;
                }
                setTimeout(leRunAjaxBatch, 300);
            })
            .catch(function () {
                // On any error (504, network failure, JSON parse) wait and retry
                if (sessionStorage.getItem(AUTORUN_KEY) === '1') {
                    setTimeout(leRunAjaxBatch, 3000);
                }
            });
    }

    // ── "Auto Scan Until Finished" button ─────────────────────────────────
    var autoBtn = document.getElementById('leBtnAutoScan');
    if (autoBtn) {
        autoBtn.addEventListener('click', function () {
            sessionStorage.setItem(AUTORUN_KEY, '1');
            this.innerHTML = 'Auto Scan Running\u2026';
            this.disabled = true;
            var sb = document.getElementById('leBtnStopScan');
            if (sb) { sb.style.display = ''; }
            if (runningBanner) {
                runningBanner.style.display = '';
                if (pendingSpan) { pendingSpan.textContent = pending; }
            }
            leRunAjaxBatch();
        });
    }

    // ── "Stop Auto Scan" button ────────────────────────────────────────────
    var stopBtn = document.getElementById('leBtnStopScan');
    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            sessionStorage.removeItem(AUTORUN_KEY);
            sessionStorage.removeItem(SCROLL_KEY);
            this.style.display = 'none';
            var running = document.getElementById('leAutorunRunning');
            if (running) { running.style.display = 'none'; }
            var ab = document.getElementById('leBtnAutoScan');
            if (ab) { ab.innerHTML = 'Auto Scan Until Finished'; ab.disabled = false; }
        });
    }

    // ── "Stop All" form — clear autorun state before server-side queue wipe ─
    window.leStopAllConfirm = function () {
        sessionStorage.removeItem(AUTORUN_KEY);
        sessionStorage.removeItem(SCROLL_KEY);
        return confirm('Stop all scanning and clear the pending queue? Results collected so far will be kept.');
    };

    // ── On page load: restart AJAX loop if auto-scan was interrupted ───────
    if (sessionStorage.getItem(AUTORUN_KEY) === '1') {
        var savedY = parseInt(sessionStorage.getItem(SCROLL_KEY) || '0', 10);
        if (savedY > 0) { window.scrollTo(0, savedY); }
        if (pending > 0) {
            if (stopBtn)  { stopBtn.style.display = ''; }
            if (autoBtn)  { autoBtn.innerHTML = 'Auto Scan Running\u2026'; autoBtn.disabled = true; }
            if (runningBanner) {
                runningBanner.style.display = '';
                if (pendingSpan) { pendingSpan.textContent = pending; }
            }
            setTimeout(leRunAjaxBatch, 1500);
        } else {
            sessionStorage.removeItem(AUTORUN_KEY);
            sessionStorage.removeItem(SCROLL_KEY);
            if (doneBanner) { doneBanner.style.display = ''; }
        }
    }
})();
[[/script]]
{/source}
