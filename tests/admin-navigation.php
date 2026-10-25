<?php

define('ABSPATH', __DIR__ . '/');
define('CORSIVO_FOCAL_POINT_FILE', dirname(__DIR__) . '/corsivo-focal-point.php');
define('CORSIVO_FOCAL_POINT_URL', 'https://example.test/wp-content/plugins/corsivo-focal-point/');
define('CORSIVO_FOCAL_POINT_VERSION', 'test');

$test_actions = [];
$test_page = [];
$test_can_manage = true;
$test_assertions = 0;
$test_assets = [];

function add_action($hook, $callback, $priority = 10) { $GLOBALS['test_actions'][$hook][$callback] = $priority; }
function add_filter() {}
function __($value, $domain = '') { return $value; }
function esc_html__($value, $domain = '') { return $value; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function plugin_basename($file) { return basename($file); }
function current_user_can($capability) { return $capability === 'manage_options' && $GLOBALS['test_can_manage']; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . '&' . $key . '=' . $value; }
function wp_safe_redirect($url) { throw new RuntimeException('redirect:' . $url); }
function wp_enqueue_style($handle, $url, $dependencies, $version) { $GLOBALS['test_assets']['style:' . $handle] = compact('url', 'dependencies', 'version'); }
function wp_enqueue_script($handle, $url, $dependencies, $version, $footer) { $GLOBALS['test_assets']['script:' . $handle] = compact('url', 'dependencies', 'version', 'footer'); }

function add_media_page($title, $menu_title, $capability, $slug, $callback) {
	$GLOBALS['test_page'] = compact('title', 'menu_title', 'capability', 'slug', 'callback');
	return 'media_page_' . $slug;
}

function navigation_assert($condition, $message) {
	$GLOBALS['test_assertions']++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

require dirname(__DIR__) . '/includes/settings.php';

corsivo_focal_point_add_settings_page();
navigation_assert($test_page['capability'] === 'manage_options', 'Moving the menu changed the configuration capability.');
navigation_assert($test_page['slug'] === 'corsivo-media', 'The Media settings page has not adopted its canonical slug.');
navigation_assert($test_page['title'] === 'Corsivo Media', 'The product title is not Corsivo Media.');
navigation_assert($test_page['menu_title'] === 'Impostazioni media', 'The menu label does not describe the settings page.');
navigation_assert($test_actions['admin_menu']['corsivo_focal_point_redirect_legacy_settings_page'] === 20, 'Legacy navigation is not redirected before the admin page access check.');
$links = corsivo_focal_point_plugin_action_links([]);
navigation_assert(str_contains($links[0], 'upload.php?page=corsivo-media'), 'The plugin settings shortcut still targets the legacy menu.');

foreach (['options-general.php', 'upload.php', 'admin.php'] as $legacy_parent) {
	$pagenow = $legacy_parent;
	$_GET = ['page' => 'corsivo-focal-point', 'settings-updated' => 'true'];
	$redirect = '';
	try {
		corsivo_focal_point_redirect_legacy_settings_page();
	} catch (RuntimeException $error) {
		$redirect = $error->getMessage();
	}
	navigation_assert($redirect === 'redirect:https://example.test/wp-admin/upload.php?page=corsivo-media&settings-updated=true', 'The legacy settings URL loses its destination or save confirmation: ' . $legacy_parent);
}

$pagenow = 'upload.php';
$_GET = ['page' => 'corsivo-media'];
navigation_assert(corsivo_focal_point_redirect_legacy_settings_page() === null, 'The canonical Media URL redirects again.');
foreach (['another-plugin', ['corsivo-focal-point'], null, 42] as $invalid_page) {
	$_GET['page'] = $invalid_page;
	navigation_assert(corsivo_focal_point_redirect_legacy_settings_page() === null, 'An unrelated or malformed page query is redirected.');
}
$_GET['page'] = 'corsivo-focal-point';
$pagenow = 'edit.php';
navigation_assert(corsivo_focal_point_redirect_legacy_settings_page() === null, 'An unrelated admin parent is redirected.');
$pagenow = 'upload.php';
$test_can_manage = false;
navigation_assert(corsivo_focal_point_redirect_legacy_settings_page() === null, 'Legacy navigation bypasses the real capability.');

corsivo_focal_point_enqueue_settings_workspace('settings_page_corsivo-focal-point');
corsivo_focal_point_enqueue_settings_workspace('media_page_another-plugin');
navigation_assert($test_assets === [], 'Media settings assets leaked onto another admin screen.');
corsivo_focal_point_enqueue_settings_workspace('media_page_corsivo-media');
navigation_assert(count($test_assets) === 3, 'The canonical Media page did not receive its complete workspace assets.');
foreach ($test_assets as $asset) {
	navigation_assert(str_starts_with($asset['url'], CORSIVO_FOCAL_POINT_URL), 'The Media interface depends on a sibling plugin asset.');
}

echo 'Corsivo Media navigation: ' . $test_assertions . " assertions passed.\n";
