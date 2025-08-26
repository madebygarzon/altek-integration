<?php
/**
 * Plugin Name: ALTEK Integration for WooCommerce
 * Description: Agrega un botón en la lista de pedidos para enviar el pedido al servidor ALTEK y añade acción masiva + ajustes.
 * Version:     1.0.0
 * Author:      Ing. Carlos Garzón
 * License:     GPLv2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Altek_Integration {
    private array $last_excluded = [];
    const OPTION_KEY = 'wc_altek_settings';
    const NONCE_KEY  = 'wc_altek_nonce';
    const ACTION_AJAX_SINGLE = 'altek_send_order';
    const ACTION_AJAX_BULK   = 'altek_send_orders_bulk';
    const LOG_SOURCE = 'altek-integration';

    public function __construct() {
        // Admin - settings
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Order action icon on list
        add_filter('woocommerce_admin_order_actions', [$this, 'add_order_action_button'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers (single & bulk)
        add_action('wp_ajax_' . self::ACTION_AJAX_SINGLE, [$this, 'handle_ajax_single']);
        add_action('wp_ajax_' . self::ACTION_AJAX_BULK,   [$this, 'handle_ajax_bulk']);

        // Bulk action in orders list
        add_filter('bulk_actions-edit-shop_order', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);
    }

    /** ---------------------------
     *  Settings page
     *  ---------------------------
     */

    // (EN) Adds a submenu under WooCommerce → Settings → Integrations.
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Integración ALTEK',
            'Integración ALTEK',
            'manage_woocommerce',
            'wc-altek-settings',
            [$this, 'render_settings_page']
        );
    }

    // (EN) Register settings fields.
    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY);

        add_settings_section(
            'wc_altek_main',
            'Configuración de ALTEK',
            function() {
                echo '<p>Define las credenciales y el endpoint del servidor ALTEK.</p>';
            },
            self::OPTION_KEY
        );
        add_settings_field('endpoint', 'Endpoint ALTEK', [$this, 'field_endpoint'], self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('api_key', 'API Key', [$this, 'field_api_key'], self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('timeout', 'Timeout (seg)', [$this, 'field_timeout'], self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('debug', 'Debug (logs detallados)', [$this, 'field_debug'], self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('exclusions', 'Excluir productos (SKU o ID)', [$this, 'field_exclusions'], self::OPTION_KEY, 'wc_altek_main');
    }

    // (EN) Parse exclusions string into two sets: 'skus' (lowercased) and 'ids' (ints)
    private function parse_exclusions(string $raw): array {
        $skus = [];
        $ids  = [];
        $parts = preg_split('/[\s,]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (ctype_digit($p)) {
                $ids[(int)$p] = true;
            } else {
                $skus[strtolower($p)] = true;
            }
        }
        return ['skus' => $skus, 'ids' => $ids];
    }

    // (EN) Decide if the given product should be excluded based on SKU or ID
    private function product_is_excluded(?WC_Product $product, array $ex): bool {
        if (!$product) return false;
        $pid = (int) $product->get_id();
        if (isset($ex['ids'][$pid])) return true;

        $sku = $product->get_sku();
        if ($sku && isset($ex['skus'][strtolower($sku)])) return true;

        return false;
    }

    // (EN) Render fields.
    public function field_endpoint() {
        $opts = $this->get_options();
        printf('<input type="url" name="%s[endpoint]" value="%s" class="regular-text" placeholder="https://endpoint.com/api/orders"/>', esc_attr(self::OPTION_KEY), esc_attr($opts['endpoint']));
    }
    public function field_api_key() {
        $opts = $this->get_options();
        printf('<input type="text" name="%s[api_key]" value="%s" class="regular-text" placeholder="secret_xxx"/>', esc_attr(self::OPTION_KEY), esc_attr($opts['api_key']));
    }
    public function field_timeout() {
        $opts = $this->get_options();
        printf('<input type="number" min="5" name="%s[timeout]" value="%s" class="small-text"/>', esc_attr(self::OPTION_KEY), esc_attr($opts['timeout']));
        echo ' <span class="description">Por defecto: 20</span>';
    }
    public function field_debug() {
        $opts = $this->get_options();
        printf('<label><input type="checkbox" name="%s[debug]" value="1" %s/> Activar logs detallados</label>', esc_attr(self::OPTION_KEY), checked('1', $opts['debug'], false));
    }
    public function field_exclusions() {
        $opts = $this->get_options();
        $ph = "SKU123,SKU-ABC-999\n1024, 2048";
        printf(
            '<textarea name="%s[exclusions]" rows="4" class="large-text code" placeholder="%s">%s</textarea>
            <p class="description">Ingresa SKU(s) y/o ID(s) de producto separados por coma o salto de línea. Se omitirán al enviar a ALTEK.</p>',
            esc_attr(self::OPTION_KEY),
            esc_attr($ph),
            esc_textarea($opts['exclusions'])
        );
    }

    // (EN) Settings page UI.
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Integración ALTEK</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections(self::OPTION_KEY);
                submit_button('Guardar cambios');
                ?>
            </form>
            
        </div>
        <?php
    }

    private function get_options() {
        $defaults = [
            'endpoint' => '',
            'api_key'  => '',
            'timeout'  => 20,
            'debug'    => '0',
            'exclusions' => '', // (EN) New: comma/line-separated SKUs or product IDs to skip
        ];
        $opts = get_option(self::OPTION_KEY, []);
        return wp_parse_args($opts, $defaults);
    }

    /** ---------------------------
     *  Order action button + assets
     *  ---------------------------
     */

    // (EN) Add a custom action icon in Orders list.
    public function add_order_action_button($actions, $order) {
        if ( ! $order instanceof WC_Order ) return $actions;

        $actions['altek_send'] = [
            'url'    => '#',
            'name'   => __('Enviar a ALTEK', 'wc-altek'),
            'action' => 'altek-send-order', // CSS class on button
        ];

        return $actions;
    }

    // (EN) Enqueue admin JS on Orders screen only.
    public function enqueue_admin_assets($hook) {
        if ( 'edit.php' !== $hook || ( isset($_GET['post_type']) && 'shop_order' !== $_GET['post_type'] ) ) return;

        wp_enqueue_script(
            'wc-altek-admin',
            plugin_dir_url(__FILE__) . 'assets-wc-altek.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wc-altek-admin', 'wcAltek', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_KEY),
            'i18n'    => [
                'sending' => __('Enviando a ALTEK...', 'wc-altek'),
                'ok'      => __('Pedido enviado a ALTEK', 'wc-altek'),
                'fail'    => __('Error al enviar a ALTEK', 'wc-altek'),
            ]
        ]);
    }

    /** ---------------------------
     *  AJAX handlers
     *  ---------------------------
     */

    // (EN) Handle single order send via AJAX.
    public function handle_ajax_single() {
        $this->check_ajax_nonce();

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ( ! $order_id ) {
            wp_send_json_error(['message' => 'order_id missing'], 400);
        }

        $result = $this->send_order_to_altek($order_id);
        if ( is_wp_error($result) ) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        wp_send_json_success(['message' => 'sent']);
    }

    // (EN) Handle bulk orders send via AJAX (used for bulk UI hook too).
    public function handle_ajax_bulk() {
        $this->check_ajax_nonce();

        $ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('absint', $_POST['order_ids']) : [];
        if ( empty($ids) ) {
            wp_send_json_error(['message' => 'order_ids missing'], 400);
        }

        $errors = [];
        foreach ($ids as $id) {
            $r = $this->send_order_to_altek($id);
            if ( is_wp_error($r) ) {
                $errors[$id] = $r->get_error_message();
            }
        }

        if ( ! empty($errors) ) {
            wp_send_json_error(['message' => 'Some orders failed', 'errors' => $errors], 207);
        }

        wp_send_json_success(['message' => 'all sent']);
    }

    private function check_ajax_nonce() {
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if ( ! wp_verify_nonce($nonce, self::NONCE_KEY) ) {
            wp_send_json_error(['message' => 'invalid nonce'], 403);
        }
    }

    /** ---------------------------
     *  Bulk actions plumbing
     *  ---------------------------
     */

    // (EN) Register bulk action on Orders screen.
    public function register_bulk_action($actions) {
        $actions['altek_send_bulk'] = __('Enviar a ALTEK', 'wc-altek');
        return $actions;
    }

    // (EN) Handle bulk action redirect (fires after submit). We push to AJAX for actual sending.
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ( 'altek_send_bulk' !== $doaction ) return $redirect_to;

        // (EN) We perform send synchronously here (server-side) to show admin notices on completion.
        $failures = [];
        foreach ($post_ids as $order_id) {
            $r = $this->send_order_to_altek($order_id);
            if ( is_wp_error($r) ) {
                $failures[] = $order_id;
            }
        }

        $sent = count($post_ids) - count($failures);
        $redirect_to = add_query_arg([
            'altek_sent' => $sent,
            'altek_fail' => count($failures),
        ], $redirect_to);

        return $redirect_to;
    }

    /** ---------------------------
     *  Core: build & send
     *  ---------------------------
     */

    // (EN) Build the JSON payload for ALTEK from a WooCommerce order.
    private function altek_build_payload(WC_Order $order) {
        // (EN) Customer data
        $customer = [
            'id'        => (string) $order->get_customer_id(),
            'email'     => $order->get_billing_email(),
            'firstName' => $order->get_billing_first_name(),
            'lastName'  => $order->get_billing_last_name(),
            'phone'     => $order->get_billing_phone(),
        ];

        // (EN) Billing & shipping
        $billing = [
            'address1' => $order->get_billing_address_1(),
            'address2' => $order->get_billing_address_2(),
            'city'     => $order->get_billing_city(),
            'state'    => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country'  => $order->get_billing_country(),
            'company'  => $order->get_billing_company(),
        ];
        $shipping = [
            'address1' => $order->get_shipping_address_1(),
            'address2' => $order->get_shipping_address_2(),
            'city'     => $order->get_shipping_city(),
            'state'    => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country'  => $order->get_shipping_country(),
            'company'  => $order->get_shipping_company(),
            'firstName'=> $order->get_shipping_first_name(),
            'lastName' => $order->get_shipping_last_name(),
        ];

        // (EN) Line items (with exclusions)
        $opts = $this->get_options();
        $rawEx = is_string($opts['exclusions'] ?? '') ? $opts['exclusions'] : '';
        $ex    = $this->parse_exclusions($rawEx);

        $this->last_excluded = []; // reset for this build
        $items = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();

            // (EN) Skip if excluded by SKU or Product ID
            if ( $this->product_is_excluded($product, $ex) ) {
                $this->last_excluded[] = [
                    'item_id'   => (string)$item_id,
                    'productId' => $product ? (string)$product->get_id() : null,
                    'sku'       => $product ? $product->get_sku() : null,
                    'name'      => $item->get_name(),
                    'qty'       => (float)$item->get_quantity(),
                ];
                continue;
            }

            $items[] = [
                'itemId'      => (string) $item_id,
                'productId'   => $product ? (string) $product->get_id() : null,
                'sku'         => $product ? $product->get_sku() : null,
                'name'        => $item->get_name(),
                'quantity'    => (float) $item->get_quantity(),
                'subtotal'    => (float) $order->get_line_subtotal($item, false, false),
                'total'       => (float) $order->get_line_total($item, false, false),
                'tax_total'   => (float) $order->get_line_tax($item),
                'meta'        => wc_get_order_item_meta($item_id, '', false),
            ];
        }


        // (EN) Coupons, shipping, fees
        $coupons = [];
        foreach ($order->get_items('coupon') as $coupon) {
            $coupons[] = [
                'code'  => $coupon->get_code(),
                'amount'=> (float) $coupon->get_discount(),
            ];
        }
        $shippings = [];
        foreach ($order->get_items('shipping') as $ship) {
            $shippings[] = [
                'methodId' => $ship->get_method_id(),
                'total'    => (float) $ship->get_total(),
            ];
        }
        $fees = [];
        foreach ($order->get_items('fee') as $fee) {
            $fees[] = [
                'name'  => $fee->get_name(),
                'total' => (float) $fee->get_total(),
            ];
        }

        // (EN) Totals
        $totals = [
            'currency'       => $order->get_currency(),
            'subtotal'       => (float) $order->get_subtotal(),
            'discount_total' => (float) $order->get_discount_total(),
            'shipping_total' => (float) $order->get_shipping_total(),
            'tax_total'      => (float) $order->get_total_tax(),
            'total'          => (float) $order->get_total(),
        ];

        // (EN) Order meta + status + dates
        $data = [
            'orderId'      => (string) $order->get_id(),
            'number'       => $order->get_order_number(),
            'status'       => $order->get_status(),
            'dateCreated'  => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'datePaid'     => $order->get_date_paid() ? $order->get_date_paid()->date('c') : null,
            'paymentMethod'=> $order->get_payment_method(),
            'transactionId'=> $order->get_transaction_id(),
            'customer'     => $customer,
            'billing'      => $billing,
            'shipping'     => $shipping,
            'items'        => $items,
            'coupons'      => $coupons,
            'shippingLines'=> $shippings,
            'fees'         => $fees,
            'totals'       => $totals,
            'notes'        => wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal']),
        ];

        return $data;
    }

    // (EN) Execute the remote POST to ALTEK with headers.
    private function altek_remote_post(array $payload) {
        $opts = $this->get_options();
        if ( empty($opts['endpoint']) ) {
            return new WP_Error('altek_no_endpoint', 'Endpoint ALTEK no configurado');
        }

        $args = [
            'timeout' => max(5, (int)$opts['timeout']),
            'headers' => [
                'Content-Type'  => 'application/json',
                // (EN) If ALTEK requires bearer or custom header, adjust here:
                'Authorization' => ! empty($opts['api_key']) ? 'Bearer ' . $opts['api_key'] : '',
                'X-From'        => 'WooCommerce',
            ],
            'body'    => wp_json_encode($payload),
        ];

        if ( $opts['debug'] === '1' ) {
            $this->log('POST ' . $opts['endpoint']);
            $this->log('Payload: ' . wp_json_encode($payload));
        }

        $response = wp_remote_post($opts['endpoint'], $args);

        if ( is_wp_error($response) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $opts['debug'] === '1' ) {
            $this->log('Response code: ' . $code);
            $this->log('Response body: ' . $body);
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('altek_bad_status', 'ALTEK devolvió estado ' . $code . ' - ' . $body);
        }

        return true;
    }

    // (EN) Public method to send an order to ALTEK.
    private function send_order_to_altek($order_id) {
        $order = wc_get_order($order_id);
        if ( ! $order ) {
            return new WP_Error('altek_no_order', 'Pedido no encontrado');
        }

        $payload = $this->altek_build_payload($order);

        // (EN) If all items were excluded, block and inform
        if (empty($payload['items'])) {
            $order->add_order_note('ALTEK: No se envió. Todos los productos del pedido están excluidos por configuración.');
            return new WP_Error('altek_all_excluded', 'Todos los productos del pedido están excluidos.');
        }

        // (EN) If some items were excluded, annotate which ones
        if (!empty($this->last_excluded)) {
            $labels = array_map(function($x) {
                $parts = [];
                if (!empty($x['sku'])) $parts[] = 'SKU: ' . $x['sku'];
                if (!empty($x['productId'])) $parts[] = 'ID: ' . $x['productId'];
                $parts[] = 'Nombre: ' . $x['name'];
                return implode(' | ', $parts);
            }, $this->last_excluded);

            $order->add_order_note(
                "ALTEK: Se omitieron " . count($this->last_excluded) . " producto(s):\n- " . implode("\n- ", $labels)
            );
        }
        $result  = $this->altek_remote_post($payload);

        // (EN) Optional: add order note on success/fail.
        if ( is_wp_error($result) ) {
            $order->add_order_note('ALTEK: Error al enviar - ' . $result->get_error_message());
        } else {
            $order->add_order_note('ALTEK: Enviado correctamente');
        }

        return $result;
    }

    // (EN) Simple logger wrapper.
    private function log($message) {
        if ( ! class_exists('WC_Logger') ) return;
        $logger = wc_get_logger();
        $logger->info($message, ['source' => self::LOG_SOURCE]);
    }
    // (EN) Add an icon on our custom action keeping the label visible
    add_action('admin_head', function () {
        // (EN) Limit to Orders list screen
        if ( function_exists('get_current_screen') ) {
            $screen = get_current_screen();
            if ( ! $screen || $screen->id !== 'edit-shop_order' ) return;
        }
        ?>
        <style>
          /* (EN) Target our button with high specificity */
          a.button.wc-action-button.wc-action-button-altek-send-order.altek-send-order::after {
            /* (EN) Use Dashicons glyph (export/migrate); change code if you prefer another */
            content: "\f19f" !important;
            font-family: Dashicons !important;
            font-size: 16px;
            line-height: 1;
            vertical-align: middle;
            margin-left: .35em;
          }
        </style>
        <?php
    });
}

new WC_Altek_Integration();

/**
 * Frontend admin JS file output (inline generator):
 * (EN) We generate a physical file on activation if needed; for simplicity, ship a static file path.
 */
register_activation_hook(__FILE__, function() {
    $js_path = plugin_dir_path(__FILE__) . 'assets-wc-altek.js';
    if ( ! file_exists($js_path) ) {
        file_put_contents($js_path, "// created on activation\n");
    }
});
