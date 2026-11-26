<?php
// Help menu entry: opens the plugin's help (rendered from README.md).
?>
<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=help/devices.php', dirname(__FILE__)); ?>">Legacy Controller Proxy</a></li>
