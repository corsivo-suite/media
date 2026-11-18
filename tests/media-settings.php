<?php
/** Isolated settings, upgrade, capability and rendered form regressions. No WordPress database. */

define('ABSPATH', __DIR__ . '/');
define('CORSIVO_FOCAL_POINT_FILE', dirname(__DIR__) . '/corsivo-focal-point.php');
define('CORSIVO_FOCAL_POINT_SETTINGS_OPTION', 'corsivo_focal_point_settings');

$test_saved = false;
$test_option_writes = 0;
$test_can_manage = true;
$test_webp_supported = false;
$test_registration = [];
$test_assertions = 0;

function add_action() {}
function add_filter() {}
function apply_filters($hook, $value) { return $value; }
function plugin_basename($file) { return basename($file); }
function get_option($key, $default = false) { return $GLOBALS['test_saved'] === false ? $default : $GLOBALS['test_saved']; }
function add_option($key, $value, $deprecated = '', $autoload = null) { $GLOBALS['test_option_writes']++; $GLOBALS['test_saved'] = $value; return true; }
function register_setting($group, $name, $args) { $GLOBALS['test_registration'] = compact('group', 'name', 'args'); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function post_type_supports($name, $support) { return $support === 'thumbnail' && in_array($name, ['post', 'page', 'product'], true); }
function post_type_exists($name) { return in_array($name, ['post', 'page', 'product', 'attachment'], true); }
function get_post_types($args, $output) {
	$types = [];
	foreach (['post' => 'Articles <script>alert(1)</script>', 'page' => 'Pages', 'product' => 'Products', 'attachment' => 'Media'] as $name => $label) {
		$types[$name] = (object) ['name' => $name, 'labels' => (object) ['name' => $label]];
	}
	return $types;
}
function __($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function checked($checked, $current = true, $display = true) { $result = (string) $checked === (string) $current ? ' checked="checked"' : ''; if ($display) { echo $result; } return $result; }
function current_user_can($capability) { return $capability === 'manage_options' && $GLOBALS['test_can_manage']; }
function has_filter() { return false; }
function wp_image_editor_supports($args) { return $GLOBALS['test_webp_supported']; }
function settings_errors() {}
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '"><input type="hidden" name="_wpnonce" value="test-nonce">'; }
function submit_button($text = '') { echo '<button type="submit">' . esc_html($text) . '</button>'; }

function media_assert($condition, $message) {
	$GLOBALS['test_assertions']++;
	if (!$condition) { throw new RuntimeException($message); }
}

require dirname(__DIR__) . '/includes/settings.php';
require dirname(__DIR__) . '/includes/data.php';

$defaults = corsivo_focal_point_default_settings();
media_assert($defaults['focal_point_enabled'] === true, 'Upgrading must keep the existing focal point module enabled.');
media_assert($defaults['optimization_enabled'] === false && $defaults['webp_enabled'] === false, 'Image processing must remain opt-in.');
media_assert($defaults['image_quality'] === 82 && $defaults['max_dimension'] === 2560, 'Unexpected initial processing defaults.');
media_assert(corsivo_focal_point_get_settings() === $defaults, 'Absent options must use complete defaults without writing.');
media_assert($test_option_writes === 0, 'Reading default settings wrote to storage.');

$legacy = ['post_types' => ['page'], 'woocommerce_enabled' => true, 'wpml_copy_once_enabled' => true, 'delete_on_uninstall' => false];
$test_saved = $legacy;
$upgraded = corsivo_focal_point_get_settings();
media_assert($upgraded['post_types'] === ['page'] && $upgraded['woocommerce_enabled'] === true && $upgraded['wpml_copy_once_enabled'] === true, 'Upgrade lost historical settings.');
media_assert($upgraded['focal_point_enabled'] === true && $upgraded['optimization_enabled'] === false && $upgraded['webp_enabled'] === false, 'Upgrade unexpectedly enabled processing or disabled focal point.');
corsivo_focal_point_initialize_site();
media_assert($test_saved === $legacy && $test_option_writes === 0, 'Initialization replaced the historical option.');

foreach ([[], new stdClass(), 'invalid', INF, NAN, '1e999'] as $invalid) {
	media_assert(corsivo_media_setting_integer($invalid, 82, 40, 100) === 82, 'Invalid numeric input was not normalized safely.');
}
media_assert(corsivo_media_setting_integer('81.6', 82, 40, 100) === 82, 'Numeric values must be rounded.');
media_assert(corsivo_media_setting_integer(-9999, 82, 40, 100) === 40, 'Quality lower bound failed.');
media_assert(corsivo_media_setting_integer(PHP_FLOAT_MAX, 82, 40, 100) === 100, 'Large finite values must clamp before integer conversion.');
media_assert(corsivo_media_setting_integer(0, 2560, 256, 8192) === 256, 'Dimension lower bound failed.');
media_assert(corsivo_media_setting_integer('99999999', 2560, 256, 8192) === 8192, 'Dimension upper bound failed.');
foreach ([true, 1, '1'] as $enabled) {
	$normalized = corsivo_media_normalize_settings(['focal_point_enabled' => $enabled, 'optimization_enabled' => $enabled, 'webp_enabled' => $enabled]);
	media_assert($normalized['focal_point_enabled'] && $normalized['optimization_enabled'] && $normalized['webp_enabled'], 'Valid checkbox true value was rejected.');
}
foreach ([false, 0, '0', 'false', 'on', [], new stdClass()] as $disabled) {
	$normalized = corsivo_media_normalize_settings(['focal_point_enabled' => $disabled, 'optimization_enabled' => $disabled, 'webp_enabled' => $disabled]);
	media_assert(!$normalized['focal_point_enabled'] && !$normalized['optimization_enabled'] && !$normalized['webp_enabled'], 'Malformed checkbox data enabled processing.');
}

$test_saved = array_replace($defaults, ['optimization_enabled' => true, 'webp_enabled' => true, 'image_quality' => 90, 'max_dimension' => 1920]);
$legacy_save = corsivo_focal_point_sanitize_settings(['post_types' => ['page'], 'wpml_copy_once_enabled' => '1']);
media_assert($legacy_save['optimization_enabled'] && $legacy_save['webp_enabled'] && $legacy_save['image_quality'] === 90 && $legacy_save['max_dimension'] === 1920, 'An older settings client erased new media preferences.');
media_assert($legacy_save['post_types'] === ['page'] && $legacy_save['wpml_copy_once_enabled'], 'Historical fields cannot still be updated.');
$clean = corsivo_focal_point_sanitize_settings(['post_types' => ['post', 'post', 'product', 'attachment', ['bad'], 'missing'], 'focal_point_enabled' => '0', 'optimization_enabled' => '0', 'webp_enabled' => '0', 'image_quality' => '999', 'max_dimension' => '-1', 'unexpected' => 'discard']);
media_assert($clean['post_types'] === ['post'], 'Unsupported and duplicate post types were retained.');
media_assert(!$clean['focal_point_enabled'] && !$clean['optimization_enabled'] && !$clean['webp_enabled'], 'Explicit hidden checkbox zero values cannot disable modules.');
media_assert($clean['image_quality'] === 100 && $clean['max_dimension'] === 256, 'Sanitization skipped numeric bounds.');
media_assert(!array_key_exists('unexpected', $clean), 'Unknown settings are persisted.');

$test_saved = array_replace($defaults, ['focal_point_enabled' => false, 'post_types' => ['post', 'page']]);
media_assert(corsivo_focal_point_get_post_types() === [], 'Disabling the focal module leaves editorial hooks enabled.');
media_assert($test_saved['post_types'] === ['post', 'page'], 'Disabling the focal module erased its configuration.');
$test_saved['focal_point_enabled'] = true;
media_assert(corsivo_focal_point_get_post_types() === ['post', 'page'], 'Re-enabling focal point did not recover the configured content types.');

corsivo_focal_point_register_settings();
media_assert($test_registration['name'] === 'corsivo_focal_point_settings' && $test_registration['group'] === 'corsivo_focal_point_settings', 'The historical Settings API contract changed.');
media_assert($test_registration['args']['sanitize_callback'] === 'corsivo_focal_point_sanitize_settings', 'The settings registration lost server-side sanitization.');

ob_start();
corsivo_focal_point_render_settings_page();
$html = ob_get_clean();
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
media_assert($xpath->query('//form')->length === 1, 'Settings were split into forms with partial save scope.');
media_assert($xpath->query('//form[@action="options.php"]')->length === 1, 'The native Settings API form destination changed.');
$sections = $xpath->query('//form//section[@data-corsivo-section]');
media_assert($sections->length === 5, 'The five media settings sections are not mounted inside the same form.');
foreach ($sections as $section) {
	$key = $section->getAttribute('data-corsivo-section');
	$links = $xpath->query('//nav//a[@data-corsivo-section-link][@href="#' . $key . '"]');
	media_assert($links->length === 1 && $document->getElementById($key) === $section, 'The sidebar link must reach its section without JavaScript: ' . $key);
}
media_assert($xpath->query('//form//*[@disabled]')->length === 0, 'Inactive section fields are disabled and would be omitted on save.');
media_assert($xpath->query('//script')->length === 0, 'A content type label was rendered as executable markup.');
media_assert(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Content type labels were not safely escaped.');
foreach (['focal_point_enabled', 'optimization_enabled', 'webp_enabled'] as $toggle) {
	$name = CORSIVO_FOCAL_POINT_SETTINGS_OPTION . '[' . $toggle . ']';
	media_assert($xpath->query('//form//input[@type="hidden"][@name="' . $name . '"][@value="0"]')->length === 1, 'Unchecked modules cannot be saved as disabled: ' . $toggle);
	media_assert($xpath->query('//form//input[@type="checkbox"][@name="' . $name . '"]')->length === 1, 'Missing module switch: ' . $toggle);
}
media_assert($xpath->query('//input[@id="corsivo-media-quality"][@min="40"][@max="100"]')->length === 1, 'The quality input does not expose its server-side limits.');
media_assert($xpath->query('//input[@id="corsivo-media-dimension"][@min="256"][@max="8192"]')->length === 1, 'The dimension input does not expose its server-side limits.');
media_assert(str_contains($html, 'WebP non'), 'Unsupported WebP capability is not explained.');
$test_webp_supported = true;
ob_start();
corsivo_media_render_upload_settings($defaults);
$supported_html = ob_get_clean();
media_assert(str_contains($supported_html, 'Questo server supporta'), 'Supported WebP capability is not explained.');
$test_can_manage = false;
ob_start();
corsivo_focal_point_render_settings_page();
media_assert(ob_get_clean() === '', 'Unauthorized users can render the settings form.');

echo 'Corsivo Media settings: ' . $test_assertions . " assertions passed.\n";
