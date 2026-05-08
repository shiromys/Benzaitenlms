<?php
require_once('/var/www/html/config.php');

$DB->execute("UPDATE mdl_config SET value = '' WHERE name = 'additionalhtmlfooter'");

purge_all_caches();

echo '<h2 style="color:green;">Fixed Successfully!</h2>';
echo '<p>Broken script cleared and caches purged.</p>';
echo '<p>Now delete this fix.php file from GitHub!</p>';
