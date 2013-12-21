<?php

// Exit if accessed directly
if (!defined('ABSPATH'))
{
	exit();
}

function xfac_register_settings()
{
	register_setting('xfac-settings', 'xfac_root');
	register_setting('xfac-settings', 'xfac_client_id');
	register_setting('xfac-settings', 'xfac_client_secret');
	register_setting('xfac-settings', 'xfac_tag_forum_mappings');
}

add_action('admin_init', 'xfac_register_settings');

function xfac_admin_menu()
{
	add_options_page('XenForo API Consumer', 'XenForo API Consumer', 'manage_options', 'xfac', 'xfac_options_init');
}

add_action('admin_menu', 'xfac_admin_menu');

function xfac_plugin_action_links($links, $file)
{
	if ($file == 'xenforo-api-consumer/xenforo-api-consumer.php')
	{
		$settings_link = '<a href="options-general.php?page=xfac">' . __("Settings") . '</a>';

		array_unshift($links, $settings_link);
	}

	return $links;
}

add_filter('plugin_action_links', 'xfac_plugin_action_links', 10, 2);

function xfac_whitelist_options($whitelist_options)
{
	$whitelist_options['xfac'][] = 'xfac_root';
	$whitelist_options['xfac'][] = 'xfac_client_id';
	$whitelist_options['xfac'][] = 'xfac_client_secret';
	$whitelist_options['xfac'][] = 'xfac_tag_forum_mappings';
	
	return $whitelist_options;
}

add_filter('whitelist_options', 'xfac_whitelist_options');