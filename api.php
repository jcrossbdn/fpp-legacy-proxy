<?php
/**
 * fpp-legacy-proxy API.  All routes live under /api/plugin/fpp-legacy-proxy/
 *
 *   GET  devices                    merged list: your devices + FPP Proxy Settings
 *   POST devices                    add / update one of your devices  {id?, name, host, port?, notes?}
 *   POST devices/delete             remove one of your devices        {id}
 *   POST devices/fpp                rename or hide an FPP-sourced one {target, name?, hidden?}
 *   POST prefs                      {includeFppProxies?, defaultView?, connectTimeout?, readTimeout?, requestGapMs?}
 *   GET  status/:target             quick TCP reachability check
 *   GET  live/:target?path=/        current read-only values (Live mode)
 *   GET  view/:target/:mode?next=   switch a device between modern and classic view
 *   GET|POST d/:target/**           the device's pages, modernised
 *
 * :target is "host" or "host:port" and must be one of the listed devices.
 * Route names must not collide with FPP's own /plugin/:RepoName/* routes
 * (icon, page, privacy, releaseNotes, settings/:SettingName, updates, upgrade):
 * FPP registers those first and its :params are optional, so e.g. "settings"
 * would be answered by FPP ("Invalid setting name"), never by this plugin.
 * State-changing routes are POST-only so a link or <img> elsewhere can't trigger them.
 */
error_reporting(E_ALL & ~(E_NOTICE | E_WARNING | E_DEPRECATED));
ini_set('display_errors', false);
require_once __DIR__ . '/lib/LegacyProxy.php';

function getEndpointsfpplegacyproxy()
{
    $eps = array(
        array('method' => 'GET', 'endpoint' => 'devices', 'callback' => 'flp_devices'),
        array('method' => 'POST', 'endpoint' => 'devices', 'callback' => 'flp_device_save'),
        array('method' => 'POST', 'endpoint' => 'devices/delete', 'callback' => 'flp_device_delete'),
        array('method' => 'POST', 'endpoint' => 'devices/fpp', 'callback' => 'flp_device_fpp'),
        array('method' => 'POST', 'endpoint' => 'prefs', 'callback' => 'flp_settings'),
        array('method' => 'GET', 'endpoint' => 'status/:target', 'callback' => 'flp_status'),
        array('method' => 'GET', 'endpoint' => 'live/:target', 'callback' => 'flp_live'),
        array('method' => 'GET', 'endpoint' => 'view/:target/:mode', 'callback' => 'flp_view'),
        array('method' => 'GET', 'endpoint' => 'd/:target/**', 'callback' => 'flp_device_page'),
        array('method' => 'POST', 'endpoint' => 'd/:target/**', 'callback' => 'flp_device_page'),
    );
    foreach ($eps as &$ep) {
        $ep['callback'] = 'flp_safe_' . substr($ep['callback'], 4);
    }
    return $eps;
}

// Each route runs inside a guard: an unexpected PHP error becomes a clear JSON
// (or HTML, for device pages) error with status 500 and a line in the plugin
// log, instead of a 200 response with a broken body.
function flp_guard($fn, $html = false)
{
    try {
        return call_user_func($fn);
    } catch (\Throwable $e) {
        $msg = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        try {
            (new LegacyProxy())->log("ERROR in $fn: $msg");
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
function flp_safe_devices() { return flp_guard('flp_devices'); }
function flp_safe_device_save() { return flp_guard('flp_device_save'); }
function flp_safe_device_delete() { return flp_guard('flp_device_delete'); }
function flp_safe_device_fpp() { return flp_guard('flp_device_fpp'); }
function flp_safe_settings() { return flp_guard('flp_settings'); }
function flp_safe_status() { return flp_guard('flp_status'); }
function flp_safe_live() { return flp_guard('flp_live'); }
function flp_safe_view() { return flp_guard('flp_view'); }
function flp_safe_device_page() { return flp_guard('flp_device_page', true); }

// ---------------------------------------------------------------- helpers
function flp_input()
{
    $raw = file_get_contents('php://input');
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : $_POST;
}

function flp_result($ok, $msg, $extra = array())
{
    if (!$ok) {
        http_response_code(400);
    }
    return json(array_merge(array('status' => (bool) $ok, 'message' => $msg), $extra), JSON_INVALID_UTF8_SUBSTITUTE);
}

function flp_home_url()
{
    return '/plugin.php?_menu=status&plugin=' . LegacyProxy::PLUGIN . '&page=devices.php';
}

function flp_static_url()
{
    return '/plugin.php?plugin=' . LegacyProxy::PLUGIN . '&nopage=1&file=static/';
}

function flp_error_page($lp, $title, $detail, $code, $retry = '')
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
        . "<link rel='stylesheet' href='/css/fpp-dark.css'><link rel='stylesheet' href='" . $e(flp_static_url() . 'legacy-proxy.css') . "'>"
        . "</head><body><div class='sp-bar'><a class='sp-home' href='" . $e(flp_home_url()) . "'>&larr; All devices</a></div>"
        . "<main class='sp-card sp-error' style='max-width:640px;margin:1.5rem auto'><h1>" . $e($title) . "</h1><p>" . $e($detail) . "</p>"
        . ($retry ? "<a class='sp-btn' href='" . $e($retry) . "'>Try again</a>" : '') . "</main></body></html>";
}

// ------------------------------------------------------------ device list
function flp_devices()
{
    $lp = new LegacyProxy();
    return json(array(
        'status' => true,
        'devices' => $lp->devices(),
        'settings' => $lp->config['settings'],
        'fppProxiesCount' => count($lp->fppProxies()),
        'storageWritable' => $lp->storageWritable(),
        'storagePath' => $lp->dataDir,
    ), JSON_INVALID_UTF8_SUBSTITUTE);
}

function flp_device_save()
{
    $lp = new LegacyProxy();
    list($ok, $msg) = $lp->saveDevice(flp_input());
    return flp_result($ok, $msg, array('devices' => $lp->devices()));
}

function flp_device_delete()
{
    $lp = new LegacyProxy();
    $in = flp_input();
    list($ok, $msg) = $lp->deleteDevice((string) ($in['id'] ?? ''));
    return flp_result($ok, $msg, array('devices' => $lp->devices()));
}

function flp_device_fpp()
{
    $lp = new LegacyProxy();
    $in = flp_input();
    list($ok, $msg) = $lp->overrideFpp((string) ($in['target'] ?? ''), $in);
    return flp_result($ok, $msg, array('devices' => $lp->devices()));
}

function flp_settings()
{
    $lp = new LegacyProxy();
    list($ok, $msg) = $lp->saveSettings(flp_input());
    return flp_result($ok, $msg, array('settings' => $lp->config['settings'], 'devices' => $lp->devices()));
}

// -------------------------------------------------------------- status
function flp_status()
{
    $lp = new LegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    if (!$d) {
        return flp_result(false, 'Unknown device');
    }
    header('Cache-Control: no-store');
    return json($lp->tcpCheck($d['host'], $d['port']));
}

function flp_live()
{
    $lp = new LegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    $path = (string) ($_GET['path'] ?? '/');
    // Live mode only ever re-reads plain pages: on these firmwares a GET of an
    // action URL (even without a query) may perform the action.
    if (!$d || $path === '' || $path[0] !== '/' || strpbrk($path, "?#\r\n") !== false) {
        return flp_result(false, 'Bad request');
    }
    try {
        list(, , $body) = $lp->deviceRequest($d['host'], $d['port'], 'GET', $path);
        $res = $lp->engine('live', array('html' => LegacyProxy::toUtf8($body)));
    } catch (RuntimeException $e) {
        http_response_code(502);
        return json(array('error' => $e->getMessage()));
    }
    header('Cache-Control: no-store');
    return json((object) ($res['values'] ?? array()));
}

function flp_view()
{
    $lp = new LegacyProxy();
    $d = $lp->findDevice((string) params('target'));
    $mode = (string) params('mode');
    if (!$d || !in_array($mode, array('modern', 'classic'), true)) {
        return flp_result(false, 'Bad request');
    }
    $lp->setView($d['target'], $mode);
    $base = $d['url'];
    $next = (string) ($_GET['next'] ?? $base);
    header('Location: ' . (strpos($next, $base) === 0 ? $next : $base), true, 302);
    return '';
}

// --------------------------------------------------------- device pages
function flp_device_page()
{
    global $settings;
    $lp = new LegacyProxy();

    // Work from the raw request line so the device gets exactly the bytes a
    // browser would have sent it (including a bare trailing "?").
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $prefix = LegacyProxy::API . '/d/';
    if (strpos($uri, $prefix) !== 0) {
        return flp_error_page($lp, 'Bad request', 'Unexpected request path.', 400);
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
        return flp_error_page($lp, 'Device not in your list',
            "$target isn't one of your devices or in FPP's Proxy Settings. Add it on the Legacy Controller Proxy page first.", 403);
    }
    $base = $d['url'];
    $sub = ltrim(strtok($pathQs, '?'), '/');
    if (in_array(strtolower($sub), array('favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'), true)) {
        http_response_code(204);
        return '';
    }

    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    $body = $method === 'POST' ? (string) file_get_contents('php://input') : '';
    try {
        list($status, $headers, $resp) = $lp->deviceRequest($d['host'], $d['port'], $method, $pathQs, $body,
            (string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    } catch (RuntimeException $e) {
        $lp->log("{$d['target']} $method $pathQs: " . $e->getMessage());
        return flp_error_page($lp, 'Device not responding',
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
        'home' => flp_home_url(),
        'static' => flp_static_url(),
        'live_url' => LegacyProxy::API . '/live/' . $d['target'],
        'classic_url' => LegacyProxy::API . '/view/' . $d['target'] . '/classic?next=' . rawurlencode($base),
        'modern_url' => LegacyProxy::API . '/view/' . $d['target'] . '/modern?next=' . rawurlencode($base),
        'theme' => $settings['themeOverride'] ?? '',
    );
    try {
        $res = $lp->engine('page', array('html' => LegacyProxy::toUtf8($resp), 'ctx' => $ctx, 'view' => $view));
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
