<?php
/*
 * Help for the Legacy Controller Proxy page (F1 / Help > Help for This Page).
 *
 * FPP looks for a plugin page's help at <plugin>/help/<page>, so this file is
 * named after devices.php. It renders the repository's README.md, so the GitHub
 * page and the in-FPP help stay identical with no extra upkeep. Same approach as
 * fpp-after-hours/help.
 *
 * FPP loads this with jQuery into its help dialog (plugin.php?...&nopage=1&page=help/devices.php),
 * so links, anchors and image paths must work from inside the host page.
 */
$flpRepo = 'fpp-legacy-proxy';
$flpGitHub = 'https://github.com/jcrossbdn/fpp-legacy-proxy';

require_once __DIR__ . '/Parsedown.php';

$md = (string) @file_get_contents(dirname(__DIR__) . '/README.md');
// Parsedown calls mb_strlen() for lines containing tabs, and FPP doesn't install
// mbstring, so expand tabs here first.
$md = str_replace("\t", '    ', $md);

$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$html = $parsedown->text($md);

// Relative images -> served from the plugin folder through FPP's plugin.php.
$html = preg_replace_callback('/(<img\b[^>]*\bsrc=)(["\'])([^"\']+)\2/i', function ($m) use ($flpRepo) {
    $src = html_entity_decode($m[3]);
    if (preg_match('#^(https?:|data:|/)#i', $src)) {
        return $m[0];
    }
    $url = '/plugin.php?plugin=' . $flpRepo . '&nopage=1&file=' . rawurlencode(ltrim($src, './'));
    return $m[1] . $m[2] . htmlspecialchars($url, ENT_QUOTES) . $m[2] . ' style="max-width:100%;height:auto"';
}, $html);

// Relative links (e.g. LICENSE, docs/...) -> the file on GitHub; external links open in a new tab.
$html = preg_replace_callback('/<a href="([^"]+)"/i', function ($m) use ($flpGitHub) {
    $href = html_entity_decode($m[1]);
    if ($href[0] === '#') {
        return $m[0];
    }
    if (!preg_match('#^(https?:|mailto:)#i', $href)) {
        $href = $flpGitHub . '/blob/master/' . ltrim($href, './');
    }
    return '<a target="_blank" rel="noopener" href="' . htmlspecialchars($href, ENT_QUOTES) . '"';
}, $html);

// Table of contents from h2/h3, with "back to top" links. IDs are prefixed so
// they can't clash with anything on the FPP page hosting the help dialog.
$toc = '';
$tocLevel = 2; // the outer <ul> is printed below
$used = array();
$html = preg_replace_callback('/<h([1-3])>(.*?)<\/h\1>/s', function ($m) use (&$toc, &$tocLevel, &$used) {
    $level = (int) $m[1];
    $text = trim(strip_tags($m[2]));
    if ($level === 1) {
        return '<h1>' . $m[2] . '</h1>';
    }
    $id = 'flpHelp-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    $base = $id;
    for ($n = 2; isset($used[$id]); $n++) {
        $id = "$base-$n";
    }
    $used[$id] = true;
    while ($tocLevel < $level) {
        $toc .= '<ul>';
        $tocLevel++;
    }
    while ($tocLevel > $level) {
        $toc .= '</ul>';
        $tocLevel--;
    }
    $toc .= '<li><a href="#' . $id . '">' . htmlspecialchars($text) . '</a></li>';
    return '<h' . $level . ' id="' . $id . '" class="flp-help-h">' . $m[2]
        . ' <a href="#flpHelpTop" class="flp-help-top"><sub>(^top^)</sub></a></h' . $level . '>';
}, $html);
while ($tocLevel > 2) {
    $toc .= '</ul>';
    $tocLevel--;
}
?>
<style>
  .flp-help { line-height: 1.5; }
  .flp-help img { max-width: 100%; height: auto; border: 1px solid var(--fpp-border); border-radius: 8px; margin: .5rem 0; }
  .flp-help table { border-collapse: collapse; margin: .5rem 0 1rem; }
  .flp-help th, .flp-help td { border: 1px solid var(--fpp-border); padding: .35rem .6rem; text-align: left; vertical-align: top; }
  .flp-help th { background: var(--fpp-bg-hover); }
  .flp-help pre { background: var(--fpp-bg-hover); padding: .6rem .8rem; border-radius: 6px; overflow-x: auto; }
  .flp-help code { font-family: var(--fpp-font-mono, monospace); }
  .flp-help .flp-help-h { margin-top: 1.6rem; }
  .flp-help .flp-help-top { font-size: .7em; font-weight: normal; text-decoration: none; }
  .flp-help .flp-help-toc { background: var(--fpp-bg-hover); border-radius: 8px; padding: .6rem 1rem .6rem .5rem; }
  .flp-help .flp-help-toc ul { margin: 0; padding-left: 1.2rem; }
</style>
<div class="flp-help">
  <p id="flpHelpTop"><a href="<?= htmlspecialchars($flpGitHub, ENT_QUOTES) ?>" target="_blank" rel="noopener">View this plugin on GitHub</a></p>
  <?php if ($toc !== ''): ?><div class="flp-help-toc"><b>Contents</b><ul><?= $toc ?></ul></div><?php endif; ?>
  <?= $html ?>
</div>
