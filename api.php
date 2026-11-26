<?php
/**
 * fpp-legacy-proxy API.  All routes live under /api/plugin/fpp-legacy-proxy/
 *
 *   GET  devices                    merged list: your devices + FPP Proxy Settings
 *   POST devices                    add / update one of your devices  {id?, name, host, port?, notes?}
 *   POST devices/delete             remove one of your devices        {id}
 *   POST devices/fpp                rename or hide an FPP-sourced one {target, name?, hidden?}
 *   GET  status/:target             quick TCP reachability check
 *   GET  live/:target?path=/        current read-only values (Live mode)
 *   POST view/:target/:mode?next=   switch a device between modern and classic view
 *   GET|POST d/:target/**           the device's pages, modernised
 *
 * :target is "host" or "host:port" and must be one of the listed devices.
 * Route names must not collide with FPP's own /plugin/:RepoName/* routes
 * (icon, page, privacy, releaseNotes, settings/:SettingName, updates, upgrade):
 * FPP registers those first and its :params are optional, so e.g. "settings"
 * would be answered by FPP ("Invalid setting name"), never by this plugin.
 * Plugin settings are FPP plugin settings (config/plugin.fpp-legacy-proxy), saved by the
 * PrintSetting* helpers through FPP's own /api/plugin/<repo>/settings/<name> route.
 *
 * Safety:
 *  - State-changing routes are POST-only, and action requests (POSTs, and device
 *    URLs carrying a query) are refused when the browser says another site sent
 *    them (Sec-Fetch-Site: cross-site/same-site), so a page open elsewhere can't
 *    make the browser reboot a controller through FPP.
 *  - FPP loads this file on EVERY /api request, so it only declares functions:
 *    no top-level code, and every global name starts with fpplegacyproxy_ / FppLegacyProxy.
 */
require_once __DIR__ . '/lib/FppLegacyProxy.php';

function getEndpointsfpplegacyproxy()
{
    $eps = array(
        array('method' => 'GET', 'endpoint' => 'devices', 'callback' => 'fpplegacyproxy_devices'),
        array('method' => 'POST', 'endpoint' => 'devices', 'callback' => 'fpplegacyproxy_device_save'),
        array('method' => 'POST', 'endpoint' => 'devices/delete', 'callback' => 'fpplegacyproxy_device_delete'),
        array('method' => 'POST', 'endpoint' => 'devices/fpp', 'callback' => 'fpplegacyproxy_device_fpp'),
        array('method' => 'GET', 'endpoint' => 'status/:target', 'callback' => 'fpplegacyproxy_status'),
        array('method' => 'GET', 'endpoint' => 'live/:target', 'callback' => 'fpplegacyproxy_live'),
        array('method' => 'POST', 'endpoint' => 'view/:target/:mode', 'callback' => 'fpplegacyproxy_view'),
        array('method' => 'GET', 'endpoint' => 'd/:target/**', 'callback' => 'fpplegacyproxy_device_page'),
        array('method' => 'POST', 'endpoint' => 'd/:target/**', 'callback' => 'fpplegacyproxy_device_page'),
    );
    foreach ($eps as &$ep) {
        $ep['callback'] = 'fpplegacyproxy_safe_' . substr($ep['callback'], strlen('fpplegacyproxy_'));
    }
    return $eps;
}

// Each route runs inside a guard: an unexpected PHP error becomes a clear JSON
// (or HTML, for device pages) error with status 500 and a line in the plugin
// log, instead of a 200 response with a broken body.
function fpplegacyproxy_cross_site()
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    return $site === 'cross-site' || $site === 'same-site';
}

function fpplegacyproxy_guard($fn, $html = false)
{
    try {
        if (!$html && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && fpplegacyproxy_cross_site()) {
            http_response_code(403);
            return json(array('status' => false, 'message' => 'Refused: this request came from another website.'));
        }
        return call_user_func($fn);
    } catch (\Throwable $e) {
        $msg = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        try {
            (new FppLegacyProxy())->log("ERROR in $fn: $msg");
        } catch (\Throwable $ignored) {
            error_log("fpp-legacy-proxy: $msg");
        }
        http_response_code(500);
        if ($html) {
            header('Content-Type: text/html; charset=utf-8');
            return '<!DOCTYPE html><meta charset="utf-8"><title>Plugin error</title>'
                . '<p>Legacy Controller Proxy hit an error: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
                . '</p><p>Details are in the plugin log (plugin-fpp-legacy-proxy.log).</p>';
        }
        return json(array('status' => false, 'message' => "Plugin error: $msg"));
    }
}
function fpplegacyproxy_safe_devices() { return fpplegacyproxy_guard('fpplegacyproxy_devices'); }
function fpplegacyproxy_safe_device_save() { return fpplegacyproxy_guard('fpplegacyproxy_device_save'); }
function fpplegacyproxy_safe_device_delete() { return fpplegacyproxy_guard('fpplegacyproxy_device_delete'); }
function fpplegacyproxy_safe_device_fpp() { return fpplegacyproxy_guard('fpplegacyproxy_device_fpp'); }
function fpplegacyproxy_safe_status() { return fpplegacyproxy_guard('fpplegacyproxy_status'); }
function fpplegacyproxy_safe_live() { return fpplegacyproxy_guard('fpplegacyproxy_live'); }
function fpplegacyproxy_safe_view() { return fpplegacyproxy_guard('fpplegacyproxy_view'); }
function fpplegacyproxy_safe_device_page() { return fpplegacyproxy_guard('fpplegacyproxy_device_page', true); }

// ---------------------------------------------------------------- helpers
function fpplegacyproxy_input()
{
    $raw = file_get_contents('php://input');
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : $_POST;
}

function fpplegacyproxy_result($ok, $msg, $extra = array())
{
    if (!$ok) {
        http_response_code(400);
    }
    return json(array_merge(array('status' => (bool) $ok, 'message' => $msg), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
}

function fpplegacyproxy_home_url()
{
    return '/plugin.php?_menu=status&plugin=' . FppLegacyProxy::PLUGIN . '&page=devices.php';
}

// Newest modification time of the plugin's static files, used to stamp their URLs
// (FPP serves JS/CSS with a one-year cache lifetime).
function fpplegacyproxy_static_version()
{
    $v = 0;
    foreach ((array) glob(__DIR__ . '/static/*') as $f) {
        $v = max($v, (int) @filemtime($f));
    }
    return (string) $v;
}

function fpplegacyproxy_static_url()
{
    return '/plugin.php?plugin=' . FppLegacyProxy::PLUGIN . '&nopage=1&file=static/';
}

function fpplegacyproxy_error_page($lp, $title, $detail, $code, $retry = '')
{
    global $settings;
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $ctx = json_encode(array('theme' => $settings['themeOverride'] ?? ''));
    $theme = $settings['themeOverride'] ?? '';
    return "<!DOCTYPE html><html lang='en'><head><meta charset='utf-8'>"
        . "<meta name='viewport' content='width=device-width, initial-scale=1'><meta name='color-scheme' content='light dark'>"
        . "<title>" . $e($title) . "</title>"
        . "<script>(function(){var o=" . json_encode($theme) . ",m=matchMedia('(prefers-color-scheme: dark)');"
        . "var t=(o==='light'||o==='dark')?o:(m.matches?'dark':'light');document.documentElement.setAttribute('data-bs-theme',t);})();</script>"
        . "<link rel='stylesheet' href='/css/fpp-bootstrap/dist/fpp-bootstrap-5-3.css'><link rel='stylesheet' href='/css/fpp-system-design.css'>"
        . "<link rel='stylesheet' href='/css/fpp-dark.css'><link rel='stylesheet' href='" . $e(fpplegacyproxy_static_url() . 'legacy-proxy.css&v=' . fpplegacyproxy_static_version()) . "'>"
        . "</head><body><div class='sp-bar'><a class='sp-home' href='" . $e(fpplegacyproxy_home_url()) . "'>&larr; All devices</a></div>"
        . "<main class='sp-card sp-error' style='max-width:640px;margin:1.5rem auto'><h1>" . $e($title) . "</h1><p>" . $e($detail) . "</p>"
        . ($retry ? "<a class='sp-btn' href='" . $e($retry) . "'>Try again</a>" : '') . "</main></body></html>";
}

// ------------------------------------------------------------ device list
function fpplegacyproxy_devices()
{
    $lp = new FppLegacyProxy();
    return json(array(
        'status' => true,
        'devices' => $lp->devices(),
        'includeFpp' => $lp->includeFpp(),
        'fppProxiesCount' => count($lp->fppProxies()),
        'storageWritable' => $lp->storageWritable(),
        'storagePath' => $lp->dataDir,
    ), JSON_INVALID_UTF8_SUBSTITUTE);
}

function fpplegacyproxy_device_save()
{
    $lp = new FppLegacyProxy();
    list($ok, $msg) = $lp->saveDevice(fpplegacyproxy_input());
    return fpplegacyproxy_result($ok, $msg, array('devices' => $lp->devices()));
}

function fpplegacyproxy_device_delete()
{
    $lp = new FppLegacyProxy();
    $in = fpplegacyproxy_input();
    list($ok, $msg) = $lp->deleteDevice((string) ($in['id'] ?? ''));
    return fpplegacyproxy_result($ok, $msg, array('devices' => $lp->devices()));
}

function fpplegacyproxy_device_fpp()
{
    $lp = new FppLegacyProxy();
    $in = fpplegacyproxy_input();
    list($ok, $msg) = $lp->overrideFpp((string) ($in['target'] ?? ''), $in);
    return fpplegacyproxy_result($ok, $msg, array('devices' => $lp->devices()));
}

// -------------------------------------------------------------- status
function fpplegacyproxy_status()
{
    $lp = new FppLegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    if (!$d) {
        return fpplegacyproxy_result(false, 'Unknown device');
    }
    header('Cache-Control: no-store');
    return json($lp->tcpCheck($d['host'], $d['port']));
}

function fpplegacyproxy_live()
{
    $lp = new FppLegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    $path = (string) ($_GET['path'] ?? '/');
    // Live mode only ever re-reads plain pages: on these firmwares a GET of an
    // action URL (even without a query) may perform the action.
    if (!$d || $path === '' || $path[0] !== '/' || strpbrk($path, "?#\r\n") !== false) {
        return fpplegacyproxy_result(false, 'Bad request');
    }
    try {
        list(, , $body) = $lp->deviceRequest($d['host'], $d['port'], 'GET', $path);
        $res = $lp->engine('live', array('html' => FppLegacyProxy::toUtf8($body)));
    } catch (RuntimeException $e) {
        http_response_code(502);
        return json(array('error' => $e->getMessage()));
    }
    header('Cache-Control: no-store');
    return json((object) ($res['values'] ?? array()));
}

function fpplegacyproxy_view()
{
    $lp = new FppLegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    $mode = (string) params('mode');
    if (!$d || !in_array($mode, array('modern', 'classic'), true)) {
        return fpplegacyproxy_result(false, 'Bad request');
    }
    $lp->setView($d['target'], $mode);
    $base = $d['url'];
    $next = (string) ($_GET['next'] ?? $base);
    header('Location: ' . (strpos($next, $base) === 0 ? $next : $base), true, 303);
    return '';
}

// --------------------------------------------------------- device pages
function fpplegacyproxy_device_page()
{
    global $settings;
    $lp = new FppLegacyProxy();

    // Work from the raw request line so the device gets exactly the bytes a
    // browser would have sent it (including a bare trailing "?").
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $prefix = FppLegacyProxy::API . '/d/';
    if (strpos($uri, $prefix) !== 0) {
        return fpplegacyproxy_error_page($lp, 'Bad request', 'Unexpected request path.', 400);
    }
    $rest = substr($uri, strlen($prefix));
    $slash = strpos($rest, '/');
    $q = strpos($rest, '?');
    if ($slash === false || ($q !== false && $q < $slash)) {
        // /d/<target> without the trailing slash: relative form actions need it.
        $target = $q === false ? $rest : substr($rest, 0, $q);
        header('Location: ' . $prefix . $target . '/' . ($q === false ? '' : substr($rest, $q)), true, 301);
        return '';
    }
    $target = rawurldecode(substr($rest, 0, $slash));
    $pathQs = substr($rest, $slash);

    $d = $lp->findDevice($target);
    if (!$d) {
        return fpplegacyproxy_error_page($lp, 'Device not in your list',
            "$target isn't one of your devices or in FPP's Proxy Settings. Add it on the Legacy Controller Proxy page first.", 403);
    }
    $base = $d['url'];
    $sub = ltrim(strtok($pathQs, '?'), '/');
    if (in_array(strtolower($sub), array('favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'), true)) {
        http_response_code(204);
        return '';
    }

    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    $isAction = $method === 'POST' || strpos($pathQs, '?') !== false;
    if ($isAction && fpplegacyproxy_cross_site()) {
        return fpplegacyproxy_error_page($lp, 'Request refused',
            'This change was sent by another website, so it was not passed to ' . $d['target']
            . '. Open the device from the Legacy Controller Proxy page and try again.', 403, $base);
    }
    $logPath = strtok($pathQs, '?'); // never log query strings: they can carry controller passwords
    $body = $method === 'POST' ? (string) file_get_contents('php://input') : '';
    try {
        list($status, $headers, $resp) = $lp->deviceRequest($d['host'], $d['port'], $method, $pathQs, $body,
            (string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    } catch (RuntimeException $e) {
        $lp->log("{$d['target']} $method $logPath: " . $e->getMessage());
        return fpplegacyproxy_error_page($lp, 'Device not responding',
            ucfirst($e->getMessage()) . '. If it was just rebooted, wait a moment and try again.', 502, $base);
    }

    header('Cache-Control: no-store');
    if (isset($headers['location'])) {
        $loc = $headers['location'];
        if (preg_match('#^https?://' . preg_quote($d['host'], '#') . '(?::\d+)?(/.*)?$#i', $loc, $m)) {
            $loc = $m[1] ?? '/';
        }
        if ($loc !== '' && $loc[0] === '/' && substr($loc, 0, 2) !== '//') {
            $loc = $base . substr($loc, 1);
        }
        header('Location: ' . $loc, true, $status >= 300 && $status < 400 ? $status : 302);
        if ($status >= 300 && $status < 400) {
            return '';
        }
    }

    $ctype = $headers['content-type'] ?? '';
    $looksHtml = stripos($ctype, 'html') !== false
        || ($ctype === '' && preg_match('/^\s*<(!doctype|html)/i', $resp));
    if (!$looksHtml) {
        http_response_code($status);
        header('Content-Type: ' . ($ctype !== '' ? $ctype : 'application/octet-stream'));
        return $resp;
    }

    $wasAction = strpos($pathQs, '?') !== false || $method === 'POST';
    $view = $lp->viewFor($d['target']);
    $ctx = array(
        'label' => $d['name'] !== $d['host'] ? $d['name'] . ' · ' . $d['target'] : $d['target'],
        'base' => $base,
        'target' => $d['target'],
        'host' => $d['host'],
        // Live mode only ever re-reads the device's main page after an action.
        'path' => $wasAction ? '/' : '/' . $sub,
        'live' => !$wasAction || $sub === '',
        'action' => $wasAction,
        'home' => fpplegacyproxy_home_url(),
        'static' => fpplegacyproxy_static_url(),
        'static_ver' => fpplegacyproxy_static_version(),
        'live_url' => FppLegacyProxy::API . '/live/' . $d['target'],
        'classic_url' => FppLegacyProxy::API . '/view/' . $d['target'] . '/classic?next=' . rawurlencode($base),
        'modern_url' => FppLegacyProxy::API . '/view/' . $d['target'] . '/modern?next=' . rawurlencode($base),
        'theme' => $settings['themeOverride'] ?? '',
    );
    try {
        $res = $lp->engine('page', array('html' => FppLegacyProxy::toUtf8($resp), 'ctx' => $ctx, 'view' => $view));
    } catch (RuntimeException $e) {
        $lp->log("engine unavailable, passing page through: " . $e->getMessage());
        http_response_code($status);
        header('Content-Type: text/html; charset=iso-8859-1');
        return $resp;
    }
    if (!empty($res['note'])) {
        $lp->log("{$d['target']} /$sub: {$res['note']}");
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    return $res['html'];
}
?>
