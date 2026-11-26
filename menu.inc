<?php
# Legacy Controller Proxy menu entries (one per menu area, per PLUGIN_GUIDELINES §9.1).
# FPP sets $plugin (this plugin's folder) and $menu (the menu area being drawn).
$menuEntries = array(
    array('text' => 'Legacy Controller Proxy', 'type' => 'status', 'page' => 'devices.php', 'wrap' => 1),
    array('text' => 'Legacy Controller Proxy', 'type' => 'help', 'page' => 'help/devices.php', 'wrap' => 1),
);
foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu) {
        continue;
    }
    $nopage = (isset($entry['wrap']) && $entry['wrap'] == 0) ? '&nopage=1' : '';
    printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n", $plugin, $entry['page'], $nopage, $entry['text']);
}
