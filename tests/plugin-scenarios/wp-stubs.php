<?php
/**
 * Minimal WordPress + WooCommerce stub environment (TESTS ONLY).
 *
 * Provides just enough of the WP/WC API surface for the Peakopay plugin to
 * run its full lifecycle (activation without WooCommerce, gateway boot,
 * process_payment, webhook handling, AJAX) inside `php -S` scenarios.
 *
 * NOT a real WordPress: every scenario prints machine-readable markers that
 * the parent test asserts on. Set $GLOBALS['__wp_with_woocommerce'] = true
 * BEFORE requiring this file to enable the WooCommerce stub classes.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('WPINC', 'wp-includes');

$GLOBALS['__wp_actions']  = [];
$GLOBALS['__wp_filters']  = [];
$GLOBALS['__wp_options']  = [];
$GLOBALS['__wp_nonces']   = [];
$GLOBALS['__hp_orders']   = [];
$GLOBALS['__hp_notices']  = [];
$GLOBALS['__hp_wc_logs']  = [];
$GLOBALS['__wp_textdomain'] = [];
$GLOBALS['__wp_scripts'] = [];
$GLOBALS['__wp_privacy_policy'] = [];

// ── WooCommerce presence (opt-in per scenario) ─────────────────────────────
$GLOBALS['__wp_with_woocommerce'] = $GLOBALS['__wp_with_woocommerce'] ?? false;

// ═══════════════════════════════ WordPress functions ══════════════════════

function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    $GLOBALS['__wp_filters'][$hook][] = ['priority' => (int) $priority, 'callback' => $callback];
}

function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    add_action($hook, $callback, $priority, $acceptedArgs);
}

function do_action($hook, ...$args)
{
    $hooks = $GLOBALS['__wp_filters'][$hook] ?? [];
    usort($hooks, static fn ($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $entry) {
        call_user_func_array($entry['callback'], $args);
    }
}

function apply_filters($hook, $value)
{
    $hooks = $GLOBALS['__wp_filters'][$hook] ?? [];
    usort($hooks, static fn ($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $entry) {
        $value = call_user_func($entry['callback'], $value);
    }
    return $value;
}

function plugin_dir_path($file)
{
    return rtrim(dirname($file), '/\\') . '/';
}

function plugin_basename($file)
{
    return 'peakopay/' . basename($file);
}

function admin_url($path = '')
{
    return 'https://shop.example.com/wp-admin/' . ltrim((string) $path, '/');
}

function home_url($path = '')
{
    return 'https://shop.example.com/' . ltrim((string) $path, '/');
}

function __($text, $domain = 'default')
{
    return (string) $text; // stub: no translation layer
}

function esc_html__($text, $domain = 'default')
{
    return esc_html(__($text, $domain));
}

function esc_attr__($text, $domain = 'default')
{
    return esc_attr(__($text, $domain));
}

function load_plugin_textdomain($domain, $deprecated = false, $path = false)
{
    $GLOBALS['__wp_textdomain'][] = [$domain, (string) $path];
    return true;
}

function plugin_dir_url($file)
{
    return 'https://shop.example.com/wp-content/plugins/' . basename(dirname((string) $file)) . '/';
}

function wp_register_script($handle, $src, $deps = [], $ver = false, $footer = false)
{
    $GLOBALS['__wp_scripts'][$handle] = [$src, $deps, $ver];
    return true;
}

function wp_add_privacy_policy_content($pluginName, $content)
{
    $GLOBALS['__wp_privacy_policy'][$pluginName] = (string) $content;
    return true;
}

function esc_html($t)
{
    return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
}

function esc_attr($t)
{
    return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
}

function esc_url($u)
{
    return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw($u)
{
    return filter_var((string) $u, FILTER_SANITIZE_URL) !== false ? (string) $u : '';
}

function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags);
}

function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return stripslashes((string) $value);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/');
}

function get_option($key, $default = false)
{
    return $GLOBALS['__wp_options'][$key] ?? $default;
}

function update_option($key, $value)
{
    $GLOBALS['__wp_options'][$key] = $value;
    return true;
}

function delete_option($key)
{
    unset($GLOBALS['__wp_options'][$key]);
    return true;
}

function current_user_can($cap)
{
    return true; // scenarios run as admin
}

function wp_create_nonce($action)
{
    $nonce = 'n_' . md5((string) $action);
    $GLOBALS['__wp_nonces'][$nonce] = (string) $action;
    return $nonce;
}

function check_ajax_referer($action, $queryArg = 'security')
{
    $given = (string) ($_POST[$queryArg] ?? $_GET[$queryArg] ?? '');
    if ($given !== '' && isset($GLOBALS['__wp_nonces'][$given]) && $GLOBALS['__wp_nonces'][$given] === (string) $action) {
        return true;
    }
    echo wp_json_encode(['success' => false, 'data' => ['message' => 'bad nonce']]);
    exit(403);
}

function wp_send_json_success($data = null)
{
    echo wp_json_encode(['success' => true, 'data' => $data]);
    exit(0);
}

function wp_send_json_error($data = null)
{
    echo wp_json_encode(['success' => false, 'data' => $data]);
    exit(0);
}

function wp_remote_request($url, $args = [])
{
    /*
     * شبیه‌سازی دقیق رفتار WordPress HTTP API (WP_Http + WpOrg\Requests):
     * هدرها باید آرایه انجمنی name => value باشند.
     * اگر آرایه عددی از رشته‌های «Name: value» داده شود، وردپرس واقعی کلید
     * عددی را «نام هدر» فرض می‌کند و هدر خراب «0: Name: value» می‌سازد —
     * دقیقاً همان باگی که خطای «شناسه فروشگاه یا کلید API ارسال نشده است»
     * را در production ایجاد کرد. اینجا همان رفتار شبیه‌سازی می‌شود تا هر
     * انحراف آینده در تست‌ها شکست بخورد، نه در سایت مشتری.
     */
    if (isset($args['headers']) && is_array($args['headers'])) {
        $real = [];
        foreach ($args['headers'] as $k => $v) {
            $real[is_int($k) ? (string) $k : strtolower((string) $k)] = (string) $v;
        }
        $args['headers'] = $real;
    }
    if (isset($GLOBALS['__hp_http_handler']) && is_callable($GLOBALS['__hp_http_handler'])) {
        return call_user_func($GLOBALS['__hp_http_handler'], $url, $args);
    }
    return ['body' => wp_json_encode(['ok' => false, 'error' => ['code' => 'no_handler', 'message' => 'no http handler in stub']]), 'response' => ['code' => 500]];
}

function wp_remote_retrieve_response_code($response)
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response)
{
    return (string) ($response['body'] ?? '');
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function status_header($code)
{
    http_response_code((int) $code);
}

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '')
    {
        $this->message = $message;
    }
    public function get_error_message()
    {
        return $this->message;
    }
}

// ═══════════════════════════════ WooCommerce stubs ════════════════════════

function wc_get_order($id)
{
    return $GLOBALS['__hp_orders'][(int) $id] ?? false;
}

function wc_add_notice($message, $type = 'notice')
{
    $GLOBALS['__hp_notices'][] = ['type' => $type, 'message' => (string) $message];
}

function wc_get_logger()
{
    return new class {
        public function log($level, $message, $context = [])
        {
            $GLOBALS['__hp_wc_logs'][] = ['level' => $level, 'message' => (string) $message];
        }
        public function warning($message, $context = [])
        {
            $this->log('warning', $message, $context);
        }
        public function error($message, $context = [])
        {
            $this->log('error', $message, $context);
        }
        public function info($message, $context = [])
        {
            $this->log('info', $message, $context);
        }
    };
}

class WC_Order
{
    public $status = 'pending';
    public $id;
    private $meta = [];
    public $notes = [];
    public $paymentCompleteCount = 0;
    public $paymentRef = '';
    public $virtualOnly = false;

    public function __construct($id = 0)
    {
        $this->id = (int) $id;
    }

    public function get_id()
    {
        return $this->id;
    }
    public function get_order_key()
    {
        return 'wc_order_test_' . $this->id;
    }
    public function get_order_number()
    {
        return (string) $this->id;
    }
    public function get_total()
    {
        return '250000.00';
    }
    public function get_formatted_billing_full_name()
    {
        return 'مشتری تست';
    }
    public function get_billing_phone()
    {
        return '09120000000';
    }
    public function get_meta($key)
    {
        return $this->meta[(string) $key] ?? '';
    }
    public function update_meta_data($key, $value)
    {
        $this->meta[(string) $key] = $value;
    }
    public function add_order_note($note)
    {
        $this->notes[] = (string) $note;
        return count($this->notes);
    }
    public function save()
    {
        return true;
    }
    public function has_status(array $statuses)
    {
        return in_array($this->status, $statuses, true);
    }
    public function get_status()
    {
        return $this->status;
    }
    public function update_status($newStatus, $note = '')
    {
        $this->status = (string) $newStatus;
        if ($note !== '') {
            $this->add_order_note($note);
        }
    }
    public function payment_complete($transactionId = '')
    {
        if ($this->has_status(['processing', 'completed'])) {
            return false; // WooCommerce itself is idempotent
        }
        $this->status = $this->virtualOnly ? 'completed' : 'processing';
        $this->paymentCompleteCount++;
        $this->paymentRef = (string) $transactionId;
        return true;
    }
}

// WooCommerce core classes exist only when the scenario enables them —
// that is exactly what we must test (plugin must survive their absence).
if (!empty($GLOBALS['__wp_with_woocommerce'])) {

class WC_Payment_Gateways
{
    public $gateways = [];
    public static function instance()
    {
        static $i = null;
        return $i ??= new self();
    }
    public function payment_gateways()
    {
        return $this->gateways;
    }
}

function WC()
{
    return WC_Payment_Gateways::instance();
}

class WooCommerce
{
    const VERSION = '9.9.0-test';
}

class WC_Payment_Gateway
{
    public $id = 'gateway';
    public $title = '';
    public $description = '';
    public $method_title = '';
    public $method_description = '';
    public $icon = '';
    public $has_fields = false;
    public $supports = [];
    public $form_fields = [];
    public $settings = [];
    public $enabled = 'no';

    public function init_form_fields()
    {
    }

    public function init_settings()
    {
        $this->settings = (array) get_option('woocommerce_' . $this->id . '_settings', []);
        foreach ($this->form_fields as $key => $field) {
            if (!isset($this->settings[$key]) && array_key_exists('default', $field)) {
                $this->settings[$key] = $field['default'];
            }
        }
    }

    public function get_option($key, $default = '')
    {
        return $this->settings[$key] ?? $default;
    }

    public function update_option($key, $value = '')
    {
        $this->settings[$key] = $value;
        update_option('woocommerce_' . $this->id . '_settings', $this->settings);
    }

    public function admin_options()
    {
        // رندر مینیمال جدول تنظیمات (معادل WC_Settings_API::generate_settings_html)
        echo '<table class="form-table">' . "\n";
        foreach ($this->form_fields as $key => $field) {
            $val = (string) $this->get_option($key, (string) ($field['default'] ?? ''));
            echo '<tr><th scope="row">' . esc_html((string) ($field['title'] ?? $key)) . '</th>'
                . '<td><input type="text" name="woocommerce_' . esc_attr($this->id) . '_' . esc_attr((string) $key) . '" value="' . esc_attr($val) . '"></td></tr>' . "\n";
        }
        echo '</table>' . "\n";
    }

    public function process_admin_options()
    {
        $settings = (array) get_option('woocommerce_' . $this->id . '_settings', []);
        foreach ($this->form_fields as $key => $field) {
            $postKey = 'woocommerce_' . $this->id . '_' . $key;
            if (isset($_POST[$postKey])) {
                $settings[$key] = wp_unslash($_POST[$postKey]);
            }
        }
        update_option('woocommerce_' . $this->id . '_settings', $settings);
        return true;
    }

    public function get_return_url($order = null)
    {
        return 'https://shop.example.com/checkout/order-received/' . ($order ? $order->get_id() : 0) . '/?key=' . ($order ? $order->get_order_key() : '');
    }
}

} // end with-woocommerce block

// ═══════════════════════════════ state dump helper ════════════════════════

function __hp_state(): array
{
    $orders = [];
    foreach ($GLOBALS['__hp_orders'] as $id => $o) {
        $orders[(string) $id] = [
            'status' => $o->status,
            'payment_complete_count' => $o->paymentCompleteCount,
            'payment_ref' => $o->paymentRef,
            'token' => (string) $o->get_meta('_peakopay_token'),
            'paid_meta' => (string) $o->get_meta('_peakopay_paid'),
            'amount_rial' => (string) $o->get_meta('_peakopay_amount_rial'),
            'notes' => $o->notes,
        ];
    }
    return [
        'orders' => $orders,
        'notices' => $GLOBALS['__hp_notices'],
        'logs' => $GLOBALS['__hp_wc_logs'],
        'gateway_registered' => isset($GLOBALS['__wp_filters']['woocommerce_payment_gateways']),
        'webhook_action_registered' => isset($GLOBALS['__wp_filters']['woocommerce_api_peakopay']),
        'options' => array_keys($GLOBALS['__wp_options']),
    ];
}
