<?php
if (!defined('SMF'))
	die('Hacking attempt...');

is_not_guest();
	
function SVNRepoManager() {
	//global $context, $settings, $options;
	loadTemplate('SVNRepoManager');
}

?>