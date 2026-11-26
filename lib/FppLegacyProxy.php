<?php
/**
 * fpp-legacy-proxy core: device list, settings, the tolerant device HTTP client
 * and the bridge to the Python re-skin engine.
 *
 * Storage (per FPP plugin guidelines):
 *   <media>/config/plugin.fpp-legacy-proxy             settings (FPP's plugin settings, PrintSetting*)
 *   <media>/plugindata/fpp-legacy-proxy/config.json   devices, FPP-device overrides, view choices
 *   <media>/plugindata/fpp-legacy-proxy/locks/         one lock file per device
 *   <media>/logs/plugin-fpp-legacy-proxy.log           the plugin's only log
 */

class FppLegacyProxy
{
    const PLUGIN = 'fpp-legacy-proxy';
    const API = '/api/plugin/fpp-legacy-proxy';

    // Settings shown on the plugin page and stored by FPP in config/plugin.<repo>.
    // Keep these defaults in step with the PrintSetting* defaults in devices.php.
    const DEFAULT_SETTINGS = array(
        'includeFppProxies' => '1',
        'defaultView' => 'modern',
        'readTimeout' => '20',
    );
    // Internal tuning, not user settings.
    const CONNECT_TIMEOUT = 5;
    const REQUEST_GAP_MS = 75;
    const ENGINE_TIMEOUT = 15;

    public $mediaDir;
    public $dataDir;
    public $configFile;
    public $logFile;
    public $config;

    public function __construct()
    {
        global $settings;
        $this->mediaDir = rtrim($settings['mediaDirectory'] ?? '/home/fpp/media', '/');
        $this->dataDir = $this->mediaDir . '/plugindata/' . self::PLUGIN;
        $this->configFile = $this->dataDir . '/config.json';
        $logDir = rtrim($settings['logDirectory'] ?? ($this->mediaDir . '/logs'), '/');
        $this->logFile = $logDir . '/plugin-' . self::PLUGIN . '.log';
        if (!is_dir($this->dataDir . '/locks')) {
            @mkdir($this->dataDir . '/locks', 0775, true);
        }
        $this->config = $this->loadConfig();
    }

    // ------------------------------------------------------------------ log
    public function log($msg)
    {
        // Open per write so FPP's log rotation is respected.
        @file_put_contents($this->logFile, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND | LOCK_EX);
    }

    // --------------------------------------------------------------- config
    private function loadConfig()
    {
        $cfg = array();
        if (is_file($this->configFile)) {
            $cfg = json_decode((string) @file_get_contents($this->configFile), true);
            if (!is_array($cfg)) {
                $this->log("config.json unreadable; starting with defaults");
                $cfg = array();
            }
        }
        unset($cfg['settings']); // settings now live in FPP's config/plugin.<repo>
        $cfg['devices'] = array_values((array) ($cfg['devices'] ?? array()));
        $cfg['fppOverrides'] = (array) ($cfg['fppOverrides'] ?? array());
        $cfg['views'] = (array) ($cfg['views'] ?? array());
        return $cfg;
    }

    public function saveConfig()
    {
        $tmp = $this->configFile . '.tmp';
        $out = $this->config;
        $out['fppOverrides'] = (object) $out['fppOverrides'];
        $out['views'] = (object) $out['views'];
        $ok = @file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false
            && @rename($tmp, $this->configFile);
        if (!$ok) {
            $this->log("could not write {$this->configFile}");
        }
        return $ok;
    }

    /** A plugin setting from FPP's config/plugin.<repo> (written by the PrintSetting* helpers). */
    public function setting($name)
    {
        $v = false;
        if (function_exists('ReadSettingFromFile')) {
            $v = ReadSettingFromFile($name, self::PLUGIN);
        } else {
            global $settings;
            $file = rtrim($settings['configDirectory'] ?? ($this->mediaDir . '/config'), '/') . '/plugin.' . self::PLUGIN;
            if (is_file($file) && preg_match('/^' . preg_quote($name, '/') . '\s*=(.+)$/m', (string) @file_get_contents($file), $m)) {
                $v = trim($m[1], " \t\n\r\0\x0B\"");
            }
        }
        if ($v === false || $v === '') {
            return self::DEFAULT_SETTINGS[$name] ?? null;
        }
        return $v;
    }

    public function includeFpp()
    {
        return $this->setting('includeFppProxies') === '1';
    }

    public function readTimeout()
    {
        $t = (int) $this->setting('readTimeout');
        return max(2, min(120, $t > 0 ? $t : 20));
    }

    // ------------------------------------------------------- host / target
    /** "host" or "host:port" -> [host, port] or null. */
    public static function splitTarget($target)
    {
        $target = trim((string) $target);
        if (!preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9.-]{0,252}[A-Za-z0-9])?)(?::(\d{1,5}))?$/', $target, $m)) {
            return null;
        }
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 80;
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return array(strtolower($m[1]), $port);
    }

    public static function target($host, $port)
    {
        return ((int) $port === 80) ? $host : "$host:$port";
    }

    /** Never proxy to this box itself or to link-local/metadata ranges. */
    public static function hostIsBlocked($host)
    {
        if ($host === 'localhost') {
            return true;
        }
        if (preg_match('/^(127|0)\./', $host) || preg_match('/^169\.254\./', $host)
            || preg_match('/^2(2[4-9]|3\d)\./', $host) || $host === '255.255.255.255') {
            return true;
        }
        return false;
    }

    // ------------------------------------------------------- FPP proxy list
    /** Entries from FPP's Proxy Settings page: [ ['host'=>..,'description'=>..], ...] */
    public function fppProxies()
    {
        $list = null;
        if (function_exists('LoadProxyList')) {
            try {
                $list = LoadProxyList();
            } catch (\Throwable $e) {
                $this->log('LoadProxyList failed: ' . $e->getMessage());
            }
        }
        if (!is_array($list)) {
            $ctx = stream_context_create(array('http' => array('timeout' => 3)));
            $raw = @file_get_contents('http://127.0.0.1/api/proxies', false, $ctx);
            $list = $raw !== false ? json_decode($raw, true) : array();
        }
        $out = array();
        foreach ((array) $list as $p) {
            $t = self::splitTarget($p['host'] ?? '');
            if ($t && !self::hostIsBlocked($t[0])) {
                $out[] = array('host' => $t[0], 'port' => $t[1],
                    'description' => (string) ($p['description'] ?? ''),
                    'pending' => !empty($p['pending']));
            }
        }
        return $out;
    }

    // ------------------------------------------------------- merged devices
    public function devices()
    {
        $byTarget = array();
        foreach ($this->config['devices'] as $d) {
            $t = self::target($d['host'], $d['port']);
            $byTarget[$t] = array(
                'id' => $d['id'], 'name' => $d['name'], 'host' => $d['host'], 'port' => (int) $d['port'],
                'target' => $t, 'notes' => $d['notes'] ?? '', 'source' => 'manual',
                'inFpp' => false, 'hidden' => false,
            );
        }
        if ($this->includeFpp()) {
            foreach ($this->fppProxies() as $p) {
                $t = self::target($p['host'], $p['port']);
                if (isset($byTarget[$t])) {
                    $byTarget[$t]['inFpp'] = true;
                    continue;
                }
                $ov = (array) ($this->config['fppOverrides'][$t] ?? array());
                $byTarget[$t] = array(
                    'id' => 'fpp:' . $t,
                    'name' => ($ov['name'] ?? '') !== '' ? $ov['name'] : ($p['description'] !== '' ? $p['description'] : $p['host']),
                    'host' => $p['host'], 'port' => $p['port'], 'target' => $t,
                    'notes' => $p['description'], 'source' => 'fpp', 'inFpp' => true,
                    'hidden' => !empty($ov['hidden']), 'pending' => $p['pending'],
                );
            }
        }
        $list = array_values($byTarget);
        foreach ($list as &$d) {
            $d['url'] = self::API . '/d/' . $d['target'] . '/';
            $d['view'] = $this->viewFor($d['target']);
        }
        usort($list, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $list;
    }

    /** The allow-list: only devices you added, or FPP's Proxy Settings list. */
    public function findDevice($target)
    {
        foreach ($this->devices() as $d) {
            if ($d['target'] === $target) {
                return $d;
            }
        }
        return null;
    }

    public function saveDevice($in)
    {
        $name = self::cut($in['name'] ?? '', 64);
        $notes = self::cut($in['notes'] ?? '', 200);
        $t = self::splitTarget(($in['host'] ?? '') . (isset($in['port']) && $in['port'] !== '' && (int) $in['port'] !== 80 ? ':' . (int) $in['port'] : ''));
        if (!$t) {
            return array(false, 'Enter an IP address or host name (optionally host:port).');
        }
        if (self::hostIsBlocked($t[0])) {
            return array(false, 'That address is not allowed (loopback, link-local or multicast).');
        }
        $id = (string) ($in['id'] ?? '');
        foreach ($this->config['devices'] as $d) {
            if ($d['id'] !== $id && $d['host'] === $t[0] && (int) $d['port'] === $t[1]) {
                return array(false, 'That device is already in the list.');
            }
        }
        $rec = array('id' => $id !== '' ? $id : bin2hex(random_bytes(4)), 'name' => $name !== '' ? $name : $t[0],
            'host' => $t[0], 'port' => $t[1], 'notes' => $notes);
        $found = false;
        foreach ($this->config['devices'] as &$d) {
            if ($d['id'] === $id && $id !== '') {
                $d = $rec;
                $found = true;
            }
        }
        unset($d);
        if (!$found) {
            $this->config['devices'][] = $rec;
        }
        return $this->saved($found ? 'Device updated.' : 'Device added.');
    }

    public function deleteDevice($id)
    {
        $before = count($this->config['devices']);
        $this->config['devices'] = array_values(array_filter($this->config['devices'], function ($d) use ($id) {
            return $d['id'] !== $id;
        }));
        if (count($this->config['devices']) === $before) {
            return array(false, 'Device not found.');
        }
        return $this->saved('Device removed.');
    }

    public function overrideFpp($target, $in)
    {
        $t = self::splitTarget($target);
        if (!$t) {
            return array(false, 'Bad device address.');
        }
        $key = self::target($t[0], $t[1]);
        $ov = (array) ($this->config['fppOverrides'][$key] ?? array());
        if (array_key_exists('name', $in)) {
            $ov['name'] = self::cut($in['name'], 64);
        }
        if (array_key_exists('hidden', $in)) {
            $ov['hidden'] = (bool) $in['hidden'];
        }
        $this->config['fppOverrides'][$key] = $ov;
        return $this->saved('Saved.');
    }

    public function viewFor($target)
    {
        $v = $this->config['views'][$target] ?? $this->setting('defaultView');
        return $v === 'classic' ? 'classic' : 'modern';
    }

    public function setView($target, $view)
    {
        $this->config['views'][$target] = $view;
        return $this->saveConfig();
    }

    // -------------------------------------------------- device HTTP client
    /**
     * One request at a time per device (these controllers have ~1 socket), a
     * short pause between requests, and a parser that accepts bare-CR/LF
     * header lines and HTTP/0.9 replies.
     * Returns [status, headers(lowercase keys), body] or throws RuntimeException.
     */
    public function deviceRequest($host, $port, $method, $pathQs, $body = '', $ctype = '')
    {
        $lockPath = $this->dataDir . '/locks/' . preg_replace('/[^A-Za-z0-9.-]/', '_', "$host-$port") . '.lock';
        $lock = @fopen($lockPath, 'c+');
        $deadline = microtime(true) + 30;
        if ($lock) {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("$host is busy with another request");
                }
                usleep(50000);
            }
            $last = (float) stream_get_contents($lock, -1, 0);
            $gap = self::REQUEST_GAP_MS / 1000 - (microtime(true) - $last);
            if ($gap > 0) {
                usleep((int) ($gap * 1e6));
            }
        }
        try {
            return $this->rawRequest($host, $port, $method, $pathQs, $body, $ctype);
        } finally {
            if ($lock) {
                ftruncate($lock, 0);
                fwrite($lock, sprintf('%.4f', microtime(true)));
                fflush($lock);
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function rawRequest($host, $port, $method, $pathQs, $body, $ctype)
    {
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, (float) self::CONNECT_TIMEOUT);
        if (!$s) {
            throw new RuntimeException("could not connect to $host:$port" . ($errstr ? " ($errstr)" : ''));
        }
        // Keep the request tiny: these firmwares parse it byte by byte.
        $req = "$method $pathQs HTTP/1.0\r\nHost: $host\r\nUser-Agent: fpp-legacy-proxy\r\nConnection: close\r\n";
        if ($method === 'POST') {
            $ctype = preg_replace('/[\r\n]+/', '', (string) $ctype);
            $req .= 'Content-Type: ' . ($ctype !== '' ? $ctype : 'application/x-www-form-urlencoded') . "\r\n";
            $req .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        fwrite($s, $req . "\r\n" . ($method === 'POST' ? $body : ''));
        $raw = '';
        $readTimeout = (float) $this->readTimeout();
        $deadline = microtime(true) + $readTimeout;
        stream_set_timeout($s, (int) ceil($readTimeout));
        while (!feof($s)) {
            if (microtime(true) > $deadline) {
                break;
            }
            $chunk = fread($s, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($s);
                if ($meta['timed_out']) {
                    break;
                }
                continue;
            }
            $raw .= $chunk;
            if (strlen($raw) > 2 * 1024 * 1024) {
                fclose($s);
                throw new RuntimeException('response too large');
            }
        }
        fclose($s);
        if ($raw === '') {
            throw new RuntimeException("$host:$port did not answer");
        }
        return self::parseResponse($raw);
    }

    public static function parseResponse($raw)
    {
        if (strncmp($raw, 'HTTP/', 5) !== 0) {
            return array(200, array(), $raw); // HTTP/0.9: body only
        }
        if (preg_match('/\r\n\r\n|\n\n|\r\r|\n\r\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $head = substr($raw, 0, $m[0][1]);
            $body = substr($raw, $m[0][1] + strlen($m[0][0]));
        } else {
            $head = $raw;
            $body = '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $head, -1, PREG_SPLIT_NO_EMPTY);
        $status = 200;
        if ($lines && preg_match('/^HTTP\/\S+\s+(\d{3})/', $lines[0], $sm)) {
            $status = (int) $sm[1];
        }
        $headers = array();
        foreach (array_slice($lines, 1) as $line) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }
        return array($status, $headers, $body);
    }

    public function tcpCheck($host, $port)
    {
        $lockPath = $this->dataDir . '/locks/' . preg_replace('/[^A-Za-z0-9.-]/', '_', "$host-$port") . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return array('online' => true, 'busy' => true, 'ms' => null); // in use = alive
        }
        $t0 = microtime(true);
        $s = @stream_socket_client("tcp://$host:$port", $e, $es, 2.5);
        $ok = (bool) $s;
        if ($s) {
            fclose($s);
        }
        if ($lock) {
            ftruncate($lock, 0);
            fwrite($lock, sprintf('%.4f', microtime(true)));
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return array('online' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000));
    }

    // --------------------------------------------------- re-skin engine
    public function engine($mode, $payload)
    {
        $cmd = array('python3', dirname(__DIR__) . '/engine/cli.py', $mode);
        $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $proc = @proc_open($cmd, $spec, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start python3');
        }
        fwrite($pipes[0], json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + self::ENGINE_TIMEOUT;
        while (true) {
            $r = array($pipes[1], $pipes[2]);
            $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 200000) === false) {
                break;
            }
            foreach ($r as $p) {
                $chunk = fread($p, 65536);
                if ($p === $pipes[1]) {
                    $out .= (string) $chunk;
                } else {
                    $err .= (string) $chunk;
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException('re-skin engine took too long');
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (trim($err) !== '') {
            $this->log("engine: " . trim($err));
        }
        $res = json_decode((string) $out, true);
        if ($code !== 0 || !is_array($res)) {
            throw new RuntimeException("re-skin engine failed (exit $code)");
        }
        return $res;
    }

    // NOTE: FPP does not install PHP's mbstring extension, so nothing here may
    // use mb_* functions. preg's /u mode and plain byte maths cover what we need.

    /** Trim and cut to at most $max characters (UTF-8 safe, no mbstring). */
    public static function cut($s, $max)
    {
        $s = trim((string) $s);
        if (!preg_match('//u', $s)) {
            $s = self::toUtf8($s);
        }
        return preg_match('/^.{0,' . (int) $max . '}/us', $s, $m) ? trim($m[0]) : '';
    }

    /** Device bytes are latin-1 (8-bit firmware strings); JSON needs UTF-8. */
    public static function toUtf8($bytes)
    {
        $bytes = (string) $bytes;
        if (preg_match('//u', $bytes)) {
            return $bytes; // already valid UTF-8 (includes plain ASCII)
        }
        // ISO-8859-1 maps byte-for-byte onto U+0000..U+00FF.
        return preg_replace_callback('/[\x80-\xFF]/', function ($m) {
            $c = ord($m[0]);
            return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
        }, $bytes);
    }

    public function storageWritable()
    {
        return is_dir($this->dataDir) && is_writable($this->dataDir)
            && (!is_file($this->configFile) || is_writable($this->configFile));
    }

    private function saved($okMsg)
    {
        if ($this->saveConfig()) {
            return array(true, $okMsg);
        }
        return array(false, "Could not save: {$this->configFile} is not writable. "
            . "Run the plugin's scripts/fpp_install.sh or reinstall it from the Plugin Manager.");
    }
}
