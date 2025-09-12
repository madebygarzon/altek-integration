<?php
/**
 * Plugin Name: ALTEK Plugin Integration for WooCommerce
 * Description: Agrega un botón en la lista de pedidos para enviar el pedido al servidor ALTEK y añade acción masiva + ajustes.
 * Version:     1.0.0
 * Author:      Ing. Carlos Garzón
 * License:     GPLv2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_enqueue_scripts', function($hook) {
    // (EN) Load SweetAlert2 from CDN on Orders list page only
    if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        wp_enqueue_style('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css', [], null);
    }
});

class WC_Altek_Integration {
    private $last_excluded = array();
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

    // (EN) Normalize SKU to 9 digits with leading zeros if numeric and <9 digits.
    private function normalize_altek_sku($sku) {
        $sku = trim((string)$sku); // Elimina espacios y asegúrate de que es string
        if (ctype_digit($sku) && strlen($sku) < 9) {
            return str_pad($sku, 9, '0', STR_PAD_LEFT);
        }
        return $sku;
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
        add_settings_field('db_host',   'DB Host',   [$this, 'field_db_host'],   self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('db_port',   'DB Port',   [$this, 'field_db_port'],   self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('db_name',   'DB Name',   [$this, 'field_db_name'],   self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('db_user',   'DB User',   [$this, 'field_db_user'],   self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('db_pass',   'DB Pass',   [$this, 'field_db_pass'],   self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('schema',    'Schema (PostgreSQL)', [$this, 'field_schema'], self::OPTION_KEY, 'wc_altek_main');
        add_settings_field('db_sslmode','SSL Mode',  [$this, 'field_db_sslmode'],self::OPTION_KEY, 'wc_altek_main');
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

    public function field_db_host()   { $o=$this->get_options(); printf('<input type="text" name="%s[db_host]" value="%s" class="regular-text" placeholder="altek.gsrv.co"/>', esc_attr(self::OPTION_KEY), esc_attr($o['db_host'] ?? '')); }
    public function field_db_port()   { $o=$this->get_options(); printf('<input type="number" name="%s[db_port]" value="%s" class="small-text" min="1" placeholder="5432"/>', esc_attr(self::OPTION_KEY), esc_attr($o['db_port'] ?? 5432)); }
    public function field_db_name()   { $o=$this->get_options(); printf('<input type="text" name="%s[db_name]" value="%s" class="regular-text" placeholder="AltekDev"/>', esc_attr(self::OPTION_KEY), esc_attr($o['db_name'] ?? '')); }
    public function field_db_user()   { $o=$this->get_options(); printf('<input type="text" name="%s[db_user]" value="%s" class="regular-text" placeholder="postgres"/>', esc_attr(self::OPTION_KEY), esc_attr($o['db_user'] ?? '')); }
    public function field_db_pass()   { $o=$this->get_options(); printf('<input type="password" name="%s[db_pass]" value="%s" class="regular-text" autocomplete="new-password" placeholder="********"/>', esc_attr(self::OPTION_KEY), esc_attr($o['db_pass'] ?? '')); }
    public function field_schema()    { $o=$this->get_options(); $v=$o['schema'] ?? 'public'; printf('<select name="%s[schema]"><option value="public" %s>public</option><option value="prev" %s>prev</option></select>', esc_attr(self::OPTION_KEY), selected('public',$v,false), selected('prev',$v,false)); }
    public function field_db_sslmode(){ $o=$this->get_options(); $v=$o['db_sslmode'] ?? 'disable'; printf('<select name="%s[db_sslmode]"><option value="disable" %s>disable (no SSL)</option><option value="require" %s>require</option><option value="prefer" %s>prefer</option><option value="allow" %s>allow</option><option value="verify-full" %s>verify-full</option></select><p class="description">El servidor actual reportó no soportar SSL; usa "disable" salvo que habiliten TLS.</p>', esc_attr(self::OPTION_KEY), selected('disable',$v,false), selected('require',$v,false), selected('prefer',$v,false), selected('allow',$v,false), selected('verify-full',$v,false)); }


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
            'endpoint'   => '',
            'api_key'    => '',
            'timeout'    => 20,
            'debug'      => '0',
            'exclusions' => '',
            'schema'     => 'public',
            'db_host'    => 'altek.gsrv.co',
            'db_port'    => 5432,
            'db_name'    => 'AltekDev',
            'db_user'    => 'postgres',
            'db_pass'    => '',
            'db_sslmode' => 'disable', // server dijo "does not support SSL"
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
            ['jquery', 'sweetalert2'],
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

        
        $order = wc_get_order($order_id);
        foreach ($order->get_items() as $item_id => $item) {
            error_log("ITEM $item_id: " . print_r($item->get_data(), true));
            foreach ($item->get_meta_data() as $meta) {
                error_log("META {$meta->key}: " . print_r($meta->value, true));
            }
        }

        $result = $this->send_order_to_altek($order_id);
        if ( is_wp_error($result) ) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        if (is_array($result) && !empty($result['idempotent'])) {
            wp_send_json_success([
                'message'    => $result['message'],
                'idempotent' => true,
                'altek_id'   => $result['altek_id'],
            ]);
        } elseif (is_array($result) && !empty($result['created'])) {
            wp_send_json_success([
                'message'  => $result['message'],
                'created'  => true,
                'altek_id' => $result['altek_id'],
            ]);
        }

        wp_send_json_success(['message' => 'Pedido enviado a ALTEK.']);
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

    // (EN) Build the minimal payload that we will use to insert into PostgreSQL directly.
    private function altek_build_orders_payload_minimal( WC_Order $order ): array {
        $opts   = $this->get_options();
        $schema = !empty($opts['schema']) ? (string)$opts['schema'] : 'public';

        $rawEx = is_string($opts['exclusions'] ?? '') ? $opts['exclusions'] : '';
        $ex    = $this->parse_exclusions($rawEx);

        $this->last_excluded = [];
        $items = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();

            // SI ES PADRE DE BUNDLE (agrupador, sin SKU), omite:
            $bundled_items = $item->get_meta('_bundled_items');
            if (!empty($bundled_items)) {
                continue; // Salta el agrupador, NUNCA tiene SKU ni se debe enviar a ALTEK
            }

            // TODOS los demás (normales y bundle hijos) se procesan igual:
            if ($product && $product->get_sku() && !$this->product_is_excluded($product, $ex)) {
                $items[] = [
                    'sku'     => $product->get_sku(),
                    'name'    => (string) $item->get_name(),
                    'qty'     => (float) $item->get_quantity(),
                    'price'   => (float) $order->get_item_total($item, false),
                    'discount'=> 0, // TODO: discount si es necesario
                ];
            } else if ($product && $this->product_is_excluded($product, $ex)) {
                $this->last_excluded[] = [
                    'item_id'   => (string)$item_id,
                    'productId' => $product->get_id(),
                    'sku'       => $product->get_sku(),
                    'name'      => $item->get_name(),
                    'qty'       => (float)$item->get_quantity(),
                ];
            }
            // Si no tiene SKU, no lo envía ni lo marca como excluido.
        }

        return [
            'schema'    => $schema,
            'order_id'  => (int) $order->get_id(),
            'customer'  => [
                'name'  => $order->get_formatted_billing_full_name(),
                'phone' => $order->get_billing_phone(),
                'email' => $order->get_billing_email(),
            ],
            'reference' => 'Pedido Woo #'.$order->get_id(),
            'items'     => $items,
        ];
    }

    // (EN) Open a pgsql connection using plugin settings, with clear errors.
    private function pg_open() {
        if ( ! function_exists('pg_connect') ) {
            return new WP_Error('altek_pg_ext', 'Extensión PHP "pgsql" no está instalada o habilitada en el servidor.');
        }

        $o = $this->get_options();

        $parts = [
            "host='".addslashes($o['db_host'])."'",
            "port='".intval($o['db_port'])."'",
            "dbname='".addslashes($o['db_name'])."'",
            "user='".addslashes($o['db_user'])."'",
            "password='".addslashes($o['db_pass'])."'", // keep raw here
            "sslmode='".addslashes($o['db_sslmode'] ?: 'disable')."'", // current server: no SSL
            "connect_timeout='10'",
        ];
        $conn_str = implode(' ', $parts);

        $conn = @pg_connect($conn_str);
        if ( ! $conn ) {
            // (EN) pg_last_error requires a connection; use generic last PHP error as hint.
            $hint = '';
            if (function_exists('error_get_last')) {
                $last = error_get_last();
                if (!empty($last['message'])) $hint = ' Detalle: ' . $last['message'];
            }
            return new WP_Error('altek_pg_connect', 'No se pudo conectar a Postgres. Revisa host/puerto/usuario/clave/firewall/sslmode.' . $hint);
        }
        return $conn;
    }


    // (EN) Insert order into PostgreSQL using a single transaction with idempotency.
 
    private function send_order_via_pgsql( WC_Order $order ) {
    $o      = $this->get_options();
    $schema = $o['schema'] ?: 'public';
    $conn = $this->pg_open();
    if ( is_wp_error($conn) ) return $conn;

    $payload = $this->altek_build_orders_payload_minimal($order);
    if (empty($payload['items'])) {
        $order->add_order_note('ALTEK: No se envió. Todos los productos del pedido están excluidos por configuración.');
        return new WP_Error('altek_all_excluded', 'Todos los productos del pedido están excluidos.');
    }

    if (!pg_query($conn, 'BEGIN')) {
        return new WP_Error('altek_pg_begin', 'No se pudo iniciar transacción.');
    }

    try {
        // (EN) 1. Idempotency check
        $order_id = (int)$payload['order_id'];
        $sqlCheck = "SELECT id FROM \"{$schema}\".\"cotizaciones\" WHERE idcotizacionweb = '$order_id' LIMIT 1";
        $res = pg_query($conn, $sqlCheck);
        if (!$res) throw new Exception(pg_last_error($conn) ?: 'Fallo al consultar idempotencia');
        if (pg_num_rows($res) > 0) {
            $row = pg_fetch_assoc($res);
            pg_query($conn, 'ROLLBACK');
            $note = 'ALTEK: Cotización '.$row['id'].' (ya fué creada).';
            $order->add_order_note($note);
            update_post_meta($order->get_id(), '_altek_idcotizacion', (int)$row['id']);
            return [
                'idempotent' => true,
                'altek_id'   => (int)$row['id'],
                'message'    => $note,
            ];
        }

        // (EN) 2. Resolve SKUs to item IDs
        $skuList = array_values(array_unique(array_map(function($i) {
            return $this->normalize_altek_sku((string)$i['sku']);
        }, $payload['items'])));
        $skuList = array_filter($skuList, fn($s)=> $s !== '');
        if (empty($skuList)) throw new Exception('Los productos no tienen SKU. Defina SKU o configure exclusiones.');
        $skuIn  = implode("','", array_map(fn($s) => pg_escape_string($conn, $s), $skuList));
        $sqlSku = "SELECT id, item FROM \"{$schema}\".\"inv_items\" WHERE item IN ('$skuIn')";
        $rSku = pg_query($conn, $sqlSku);
        if (!$rSku) throw new Exception(pg_last_error($conn) ?: 'Fallo al resolver SKUs');
        $skuMap = [];
        while ($r = pg_fetch_assoc($rSku)) { $skuMap[$r['item']] = (int)$r['id']; }
        $missing = array_values(array_filter($skuList, fn($s)=> !isset($skuMap[$s])));
        if (!empty($missing)) {
            throw new Exception('SKUs no encontrados en '.$schema.'.inv_items: '.implode(', ', $missing));
        }

        // (EN) 3. Insert Order (Headers)
        $ref    = mb_substr($payload['reference'] ?: ('COT. PARA '.$payload['customer']['name']), 0, 60);
        $nombre = pg_escape_string($conn, $payload['customer']['name'] ?? '');
        $phone  = pg_escape_string($conn, $payload['customer']['phone'] ?? '');
        $email  = pg_escape_string($conn, $payload['customer']['email'] ?? '');
        $ref    = pg_escape_string($conn, $ref);
        $sqlH = "INSERT INTO \"{$schema}\".\"cotizaciones\" (
            fecha, referencia, tipoproceso, idusuario, tipocliente, idcliente,
            idciudadinstalacion, descuento, anticipo, estado, causalnegacion,
            especial, idoc, embalaje, version, idproyecto, iva, idsolicitud,
            vrservicios, nombrecliente, telefonos, email, idcotizacionweb
        ) VALUES (
            CURRENT_DATE, '$ref', 0, 1, 0, 0,
            0, 0, 0, 0, 0,
            FALSE, 0, 0, 1, 0, 19, 0,
            0, '$nombre', '$phone', '$email', '$order_id'
        ) RETURNING id";
        $rH = pg_query($conn, $sqlH);
        if (!$rH) throw new Exception(pg_last_error($conn) ?: 'Fallo insert cotización');
        $idcot = (int)pg_fetch_result($rH, 0, 'id');

        // --- 4. Insertar productos (itemsxcotizacion) ---
        foreach ($payload['items'] as $it) {
            //$sku    = (string)$it['sku'];
            $sku = $this->normalize_altek_sku((string)$it['sku']);
            $this->log("DEBUG SKU: original='{$it['sku']}', normalizado='$sku'");
            $iditem = $skuMap[$sku] ?? null;
            if (!$iditem) throw new Exception('SKU sin resolver: '.$sku);
            $name   = pg_escape_string($it['name']);
            $qty    = (float)$it['qty'];
            $price  = (float)$it['price'];
            $desc   = (float)($it['discount'] ?? 0);
            $sqlI = "INSERT INTO \"{$schema}\".\"itemsxcotizacion\" (
                idcotizacion, detalle, iditem, nombre, cantidad, precioventa, iva,
                especial, espedido, porcentajedescuento
            ) VALUES (
                '$idcot', 'COLECCION WOO', '$iditem', '$name', '$qty', '$price', 19,
                FALSE, FALSE, '$desc'
            )";
            $ok = pg_query($conn, $sqlI);
            if (!$ok) throw new Exception(pg_last_error($conn) ?: ('Fallo insert ítem: '.$sku));
        }

        if (!pg_query($conn, 'COMMIT')) throw new Exception(pg_last_error($conn) ?: 'Fallo commit');

        $note = 'ALTEK: Cotización '.$idcot.' creada.';
        $order->add_order_note($note);
        update_post_meta($order->get_id(), '_altek_idcotizacion', $idcot);
        // (ES) Retornamos detalles para que la respuesta AJAX pueda replicar la nota exacta
        return ['created' => true, 'altek_id' => $idcot, 'message' => $note];

    } catch (Exception $e) {
        pg_query($conn, 'ROLLBACK');
        return new WP_Error('altek_pg_tx', $e->getMessage());
    }
}


   // (EN) Always use direct PostgreSQL mode (no HTTP API).
    private function send_order_to_altek($order_id) {
        $order = wc_get_order($order_id);
        if ( ! $order ) {
            return new WP_Error('altek_no_order', 'Pedido no encontrado');
        }

        // Build minimal payload to honor exclusions (and note excluded items)
        $payload = $this->altek_build_orders_payload_minimal($order);
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

        // Execute DB transaction
        $result = $this->send_order_via_pgsql($order);

        if ( is_wp_error($result) ) {
            $order->add_order_note('ALTEK: Error al enviar (DB) - ' . $result->get_error_message());
            return $result;
        }

        if (is_array($result) && !empty($result['idempotent'])) {
            return $result;
        } elseif (is_array($result) && !empty($result['created'])) {
            return $result;
        }

        return true;
    }


    // (EN) Simple logger wrapper.
    private function log($message) {
        if ( ! class_exists('WC_Logger') ) return;
        $logger = wc_get_logger();
        $logger->info($message, ['source' => self::LOG_SOURCE]);
    }
}

new WC_Altek_Integration();

 // (EN) Add an icon on our custom action keeping the label visible
    add_action('admin_head', function () {
        if ( function_exists('get_current_screen') ) {
            $screen = get_current_screen();
            if ( ! $screen || $screen->id !== 'edit-shop_order' ) return;
        }
        ?>
        <style>
        a.button.wc-action-button.wc-action-button-altek-send-order.altek-send-order::after {
            content: "\f344"; /* Dashicon arrow-right-alt */
            font-family: Dashicons !important;
            font-size: 15px;
            line-height: 1;
            vertical-align: middle;
            margin-top: 6px;
            font-weight: normal;
            speak: never;
            text-transform: none;
        }
        </style>
        <?php
    });

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
