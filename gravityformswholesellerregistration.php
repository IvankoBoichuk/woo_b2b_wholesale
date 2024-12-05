<?php
/**
Plugin Name: Gravity Forms Wholeseller Add-On
Version: 1.0.0
Author: Ivan Boichuk
**/

defined( 'ABSPATH' ) || die();

class WOO_Wholeseller {
    const META_NAME_REGULAR_PRICE = "_regular_price_wholesale";
    const META_NAME_SALE_PRICE = "_sale_price_wholesale";
    const TEXTDOMAIN = "woo-wholeseller";

    function __construct() {

        add_shortcode("fa_auth_link", [$this, "auth_link"]);
        
        add_action("init", [$this, "add_wholesaler_role"]);
        
        add_action("admin_enqueue_scripts", [$this, "admin_enqueue_scripts"]);
        add_action("wp_enqueue_scripts", [$this, "wp_enqueue_scripts"]);
        add_action("wp_ajax_toggle_wholesaler_role_user", [$this, "wp_ajax_toggle_wholesaler_role_user"]);
        // Add custom wholesale price field to the product edit page
        add_action("woocommerce_product_options_pricing", [$this, "woocommerce_product_options_pricing"]);
        // Save the wholesale price field value
        add_action("woocommerce_process_product_meta", [$this, "woocommerce_process_product_meta"]);
        // Add wholesale price field to variations
        add_action("woocommerce_product_after_variable_attributes", [$this, "woocommerce_product_after_variable_attributes"], 10, 3);
        // Save wholesale price for variations
        add_action("woocommerce_save_product_variation", [$this, "woocommerce_save_product_variation"], 10, 2);

        // Додаємо нову колонку "Wholesaler" у таблицю користувачів
        add_filter("manage_users_columns", [$this, "manage_users_columns"]);
        // Додаємо чекбокс до колонки
        add_filter("manage_users_custom_column", [$this, "manage_users_custom_column"], 10, 3);

        // Simple, grouped and external products
        add_filter("woocommerce_product_get_price", [$this, "ts_custom_price"], 99, 2 );
        add_filter("woocommerce_product_get_regular_price", [$this, "ts_custom_price"], 99, 2 );

        add_filter("woocommerce_product_variation_get_regular_price", [$this, "variation_get_regular_price"], 99, 2 );
        add_filter("woocommerce_product_variation_get_sale_price", [$this, "variation_get_sale_price"], 99, 2 );
        add_filter("woocommerce_product_variation_get_price", [$this, "get_variation_price"], 99, 2 );
        add_filter("woocommerce_get_price_html", [$this, "woocommerce_get_price_html"], 10, 2);

        add_filter("woocommerce_product_is_in_stock", [$this, "filter_product_stock_based_on_user_role"], 10, 2);
        // add_action("woocommerce_thankyou", [$this, "reduce_wholesale_stock"]);
        add_filter("woocommerce_update_product_stock_query", [$this, "replace_stock_meta"], 10, 4);
        add_action("woocommerce_product_options_inventory_product_data", [$this, "add_wholesale_stock_field"], 100);
        add_action("woocommerce_admin_process_product_object", [$this, "save_wholesale_stock_field"], 10, 1);
        add_action("woocommerce_variation_options_inventory", [$this, "add_variation_wholesale_stock_field"], 10, 3 );
		add_action("woocommerce_save_product_variation", [$this, "save_variation_wholesale_stock_field"], 10, 2 );

        add_filter("woocommerce_account_menu_items", [$this, "unset_downloads"], 99);

        add_action("admin_footer", [$this, "add_wholesale_status_to_orders_page"]);
        add_filter("woocommerce_product_get_stock_quantity", [$this, "custom_get_stock_quantity"], 10, 2 );
        add_filter("woocommerce_product_variation_get_stock_quantity", [$this, "custom_get_stock_quantity"], 10, 2 );
        

        // Фільтруємо замовлення за роллю клієнта
        add_action("pre_get_posts", [$this, "filter_orders_by_wholesaler"]);
        add_filter("manage_edit-product_columns", [$this, "add_wholesale_stock_column"]);
        add_action("manage_product_posts_custom_column", [$this, "display_wholesale_stock_column"], 10, 2);
        
    }
    function replace_stock_meta($sql, $product_id_with_stock, $new_stock, $operation){
        if (self::is_wholesaler()) {
            return str_replace("'_stock'", "'_wholesale_stock'", $sql);
        }
        return $sql;
    }
    function unset_downloads($items) {
        unset($items['downloads']); // Видаляємо пункт "Downloads"
        return $items;
    }
    function add_wholesale_status_to_orders_page() {
        global $post_type, $pagenow;

        if ('edit.php' === $pagenow && 'shop_order' === $post_type) {
            // Отримуємо кількість замовлень від wholesaler
            $wholesaler_count = self::get_wholesaler_orders_count();
            ?>
            <script type="text/javascript">
                (function() {
                    var urlParams = new URLSearchParams(window.location.search);
                    var wholesalerFilterActive = urlParams.has('wholesaler_filter');

                    var wholesaleTab = '<li class="wholesale"> | <a href="edit.php?post_type=shop_order&wholesaler_filter=1"';
                    if (wholesalerFilterActive) {
                        wholesaleTab += ' class="current"';
                    }
                    wholesaleTab += '"><?php _e('Wholesales', self::TEXTDOMAIN); ?> <span class="count">(<?php echo $wholesaler_count; ?>)</span></a></li>';

                    document.querySelector("ul.subsubsub").insertAdjacentHTML("beforeend", wholesaleTab);
                })();
            </script>
            <?php
        }
    }
    function filter_orders_by_wholesaler($query) {
        global $pagenow, $post_type;

        if (is_admin() && $pagenow == 'edit.php' && $post_type == 'shop_order' && isset($_GET['wholesaler_filter'])) {
            $query->set('meta_query', array(
                array(
                    'key'     => '_customer_user',
                    'value'   => get_users(array(
                        'role'    => 'wholesaler',
                        'fields'  => 'ID'
                    )),
                    'compare' => 'IN',
                ),
            ));
        }
    }
    public static function get_wholesaler_orders_count() {
        $wholesaler_ids = get_users(array(
            'role'    => 'wholesaler',
            'fields'  => 'ID'
        ));
    
        $args = array(
            'post_type'   => 'shop_order',
            'post_status' => array_keys(wc_get_order_statuses()),
            'meta_query'  => array(
                array(
                    'key'     => '_customer_user',
                    'value'   => $wholesaler_ids,
                    'compare' => 'IN',
                )
            ),
            'fields' => 'ids', // Повертаємо лише ID замовлень для підрахунку
        );
    
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    function woocommerce_get_price_html($price_html, $product) {
        // Determine user roles
        $is_admin = self::is_admin();
        $is_wholesaler = self::is_wholesaler();
        
        if ($is_admin) {
            return $price_html;
        }
        
        // Handle variable product pricing
        if (($is_wholesaler || $is_admin) && $product->is_type('variable')) {
            return $this->handle_variable_product_price_html($price_html, $product, $is_admin);
        }
    
        // Handle simple, grouped, or external products
        if ($is_wholesaler || $is_admin) {
            return $this->handle_simple_product_price_html($price_html, $product, $is_admin);
        }
    
        // Default behavior for other roles or unauthenticated users
        return $price_html;
    }
    /**
     * Handle price HTML for variable products.
     */
    private function handle_variable_product_price_html($price_html, $product, $is_admin) {
        $wholesale_prices = [];
        $variations = $product->get_children();
    
        // Collect wholesale prices from all variations
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            $wholesale_price = $variation->get_price();
            if ($wholesale_price !== null) {
                $wholesale_prices[] = $wholesale_price;
            }
        }
    
        // Generate price range or single price
        if (!empty($wholesale_prices)) {
            $price_html = $this->format_price_range($wholesale_prices);
        }
    
        // Include regular price if admin
        if ($is_admin) {
            $price_html = "{$price_html} - Regular</br>";
            $wholesale_variable_html = self::get_wholesale_variable_html($product->get_id());
            if ($wholesale_variable_html) {
                $price_html = $price_html . $wholesale_variable_html . " - Wholesale";
            }
        }
    
        return $price_html;
    }
    
    /**
     * Handle price HTML for simple, grouped, or external products.
     */
    private function handle_simple_product_price_html($price_html, $product, $is_admin) {
        $wholesale_html = self::get_wholesale_html($product->get_id());
    
        if ($is_admin && $wholesale_html) {
            $regular_html = self::get_regular_html($product->get_id());
            $price_html = $regular_html . $wholesale_html;
        } elseif ($wholesale_html) {
            $price_html = $wholesale_html;
        }
    
        return $price_html;
    }
    
    /**
     * Get the wholesale price for a variation.
     */
    private function get_wholesale_price_for_variation($variation) {
        $wholesale_regular_price = get_post_meta($variation->get_id(), self::META_NAME_REGULAR_PRICE, true);
        if (!empty($wholesale_regular_price)) {
            return (float)$wholesale_regular_price;
        }
        return null;
    }
    
    /**
     * Format a price range.
     */
    private function format_price_range($prices) {
        $min_price = min($prices);
        $max_price = max($prices);
    
        if ($min_price === $max_price) {
            return wc_price($min_price);
        }
    
        return sprintf(
            __('%s – %s', self::TEXTDOMAIN),
            wc_price($min_price),
            wc_price($max_price)
        );
    }
    function get_wholesale_variable_html ($product_id) {
        $product = wc_get_product($product_id);
        $price_html = "";
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $wholesale_regular_price = get_post_meta($variation_id, self::META_NAME_REGULAR_PRICE, true);
            $wholesale_sale_price = get_post_meta($variation_id, self::META_NAME_SALE_PRICE, true);
            if ($wholesale_regular_price) {
                $wholesale_prices[] = (float) $wholesale_regular_price;
            }
            if ($wholesale_sale_price) {
                $wholesale_prices[] = (float) $wholesale_sale_price;
            }
        }

        if (!empty($wholesale_prices)) {
            $min_price = min($wholesale_prices);
            $max_price = max($wholesale_prices);

            if ($min_price === $max_price) {
                $price_html = wc_price($min_price);
            } else {
                $price_html = sprintf(
                    __('%s – %s', self::TEXTDOMAIN),
                    wc_price($min_price),
                    wc_price($max_price)
                );
            }
        }
        return $price_html;
    }
    function get_regular_html ($product_id) {
        $wholesale_regular_price = get_post_meta($product_id, "_regular_price", true);
        $wholesale_sale_price = get_post_meta($product_id, "_sale_price", true);
        
        if ($wholesale_regular_price && $wholesale_sale_price) {
            $price_html = sprintf(
                __('<span class="price"><del>%s</del><ins>%s</ins> - Regular</span>', self::TEXTDOMAIN),
                wc_price($wholesale_regular_price),
                wc_price($wholesale_sale_price)
            );
        } else {
            $price_html = wc_price($wholesale_regular_price);
        }

        return $price_html;
    }
    function get_wholesale_html ($product_id) {
        $wholesale_regular_price = get_post_meta($product_id, self::META_NAME_REGULAR_PRICE, true);
        $wholesale_sale_price = get_post_meta($product_id, self::META_NAME_SALE_PRICE, true);
        
        $default_price = get_post_meta($product_id, "_price", true);

        if ($wholesale_regular_price && $wholesale_sale_price) {
            $price_html = sprintf(
                __('<span class="price"><del>%s</del><ins>%s</ins> - Wholesale</span>', self::TEXTDOMAIN),
                wc_price($wholesale_regular_price),
                wc_price($wholesale_sale_price)
            );
        } elseif ($wholesale_regular_price) {
            $price_html = wc_price($wholesale_regular_price);
        } else {
            $price_html = wc_price($default_price);
        }

        return $price_html;
    }
    function variation_get_regular_price($price, $variation) {
        if (!self::is_wholesaler()) {
            return $price;
        }
        $variation_id = $variation->variation_id;
        $wholesale_regular_price = get_post_meta($variation_id, self::META_NAME_REGULAR_PRICE, true);
        return $wholesale_regular_price;
    }
    function variation_get_sale_price($price, $variation) {
        if (!self::is_wholesaler()) {
            return $price;
        }
        $variation_id = $variation->variation_id;
        $wholesale_sale_price = get_post_meta($variation_id, self::META_NAME_SALE_PRICE, true);
        return $wholesale_sale_price;
    }
    function get_variation_price( $price, $variation ) {
        if (!self::is_wholesaler()) {
            return $price;
        }
        $variation_id = $variation->variation_id;
        $wholesale_regular_price = get_post_meta($variation_id, self::META_NAME_REGULAR_PRICE, true);
        $wholesale_sale_price = get_post_meta($variation_id, self::META_NAME_SALE_PRICE, true);
        if ($wholesale_regular_price && $wholesale_sale_price) {
            return min([
                $wholesale_regular_price,
                $wholesale_sale_price
            ]);
        } elseif ($wholesale_regular_price) {
            return $wholesale_regular_price;
        } else {
            return $price;
        }
    }
    public function is_admin() {
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles);
    }
    public function is_wholesaler() {
        $user = wp_get_current_user();
        return in_array('wholesaler', $user->roles);
    }
    function add_wholesaler_role() {
        if (get_role('wholesaler')) {
            return;
        }
        // Додати нову роль Wholesaler
        add_role(
            'wholesaler', // Ідентифікатор ролі
            __('Wholesaler', self::TEXTDOMAIN), // Назва ролі
            array(
                'read' => true, // Дозволити читання (доступ до адмінки)
                'edit_posts' => false, // Заборонити редагування постів
                'delete_posts' => false, // Заборонити видалення постів
            )
        );
    }
    function ts_custom_price( $price, $product ) {
        // Перевіряємо, чи користувач залогінений і чи має роль 'wholesaler'
        if (!is_user_logged_in()) {
            return $price;
        }

        if (self::is_admin()) {
            return $price;
        }
        
        if (! self::is_wholesaler()) {
            return $price;
        }
        // Отримуємо оптові ціни
        $wholesale_regular_price = get_post_meta($product->get_id(), self::META_NAME_REGULAR_PRICE, true);
        $wholesale_sale_price = get_post_meta($product->get_id(), self::META_NAME_SALE_PRICE, true);
        // Якщо є ціна розпродажу, використовуємо її, інакше стандартну оптову ціну
        if (!empty($wholesale_sale_price)) {
            return (float) $wholesale_sale_price;
        } elseif (!empty($wholesale_regular_price)) {
            return (float) $wholesale_regular_price;
        }
    }
    function manage_users_columns ($columns) {
        $columns['wholesaler_url'] = __('Website', self::TEXTDOMAIN);
        $columns['wholesaler_toggle'] = __('Wholesaler', self::TEXTDOMAIN);
        return $columns;
    }
    function manage_users_custom_column ($value, $column_name, $user_id) {
        if ($column_name === 'wholesaler_toggle') {
            $is_wholesaler = in_array('wholesaler', get_userdata($user_id)->roles);
            $checked = $is_wholesaler ? 'checked' : '';
    
            return sprintf(
                '<input type="checkbox" class="wholesaler-toggle" data-user-id="%d" %s>',
                esc_attr($user_id),
                $checked
            );
        } elseif ($column_name === 'wholesaler_url') {
            $is_wholesaler = in_array('wholesaler', get_userdata($user_id)->roles);
            $website = get_user_meta($user_id, '_url', true);
            if ($website) {
                return sprintf('<a href="%s" target="_blank">%s</a>', esc_url($website), esc_html($website));
            }
        }
        return $value;
    }
    function admin_enqueue_scripts ($hook) {
        // Цільова сторінка "Користувачі"
        wp_enqueue_style('wholesaler-app', plugin_dir_url(__FILE__) . 'css/wholesaler-app.css', array(), '1.0', false);
        if ($hook != 'users.php') {
            return;
        }
        wp_enqueue_script('wholesaler-toggle-users', plugin_dir_url(__FILE__) . 'js/wholesaler-toggle.js', array('jquery'), '1.0', true);
        wp_localize_script('wholesaler-toggle-users', 'wholesalerUsersAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wholesaler_users_nonce')
        ));
    }
    function wp_enqueue_scripts () {
        wp_enqueue_style('wholesaler-toggle-users', plugin_dir_url(__FILE__) . 'css/wholesaler-toggle.css', array(), '1.0', false);
    }
    function wp_ajax_toggle_wholesaler_role_user () {
        check_ajax_referer('wholesaler_users_nonce', 'nonce');
    
        $user_id = absint($_POST['user_id']);
        $is_checked = sanitize_text_field($_POST['is_checked']);
    
        if ($user_id) {
            $user = get_user_by('id', $user_id);
            if ($is_checked === 'true') {
                $user->set_role('wholesaler');
            } else {
                $user->set_role('customer');
            }
            wp_send_json_success();
        }
        wp_send_json_error();
    }
    function woocommerce_product_options_pricing () {
        echo '<div class="options_group">';
        woocommerce_wp_text_input(array(
            'id' => '_regular_price_wholesale',
            'label' => __('Wholesale Price ($)', self::TEXTDOMAIN),
            'desc_tip' => true,
            'description' => __('Set a wholesale price for wholesalers.', self::TEXTDOMAIN),
            'type' => 'text',
            'data_type' => 'price',
        ));
        woocommerce_wp_text_input(array(
            'id' => '_sale_price_wholesale',
            'label' => __('Wholesale Sale Price ($)', self::TEXTDOMAIN),
            'desc_tip' => true,
            'description' => __('Set a wholesale sale price for wholesalers.', self::TEXTDOMAIN),
            'type' => 'text',
            'data_type' => 'price',
        ));
        echo '</div>';
    }
    function woocommerce_process_product_meta ($post_id) {
        if (isset($_POST['_regular_price_wholesale'])) {
            update_post_meta($post_id, '_regular_price_wholesale', wc_format_decimal($_POST['_regular_price_wholesale']));
        }
        if (isset($_POST['_sale_price_wholesale'])) {
            update_post_meta($post_id, '_sale_price_wholesale', wc_format_decimal($_POST['_sale_price_wholesale']));
        }
    }
    function woocommerce_product_after_variable_attributes($loop, $variation_data, $variation) {
        echo '<div class="options_group">';
        woocommerce_wp_text_input(array(
            'id' => "regular_price_wholesale_{$variation->ID}",
            'name' => "regular_price_wholesale[{$variation->ID}]",
            'label' => __('Wholesale Price ($)', self::TEXTDOMAIN),
            'value' => get_post_meta($variation->ID, '_regular_price_wholesale', true),
            'desc_tip' => true,
            'description' => __('Set a wholesale price for this variation.', self::TEXTDOMAIN),
            'type' => 'text',
            'data_type' => 'price',
        ));
        woocommerce_wp_text_input(array(
            'id' => "sale_price_wholesale_{$variation->ID}",
            'name' => "sale_price_wholesale[{$variation->ID}]",
            'label' => __('Wholesale Sale Price ($)', self::TEXTDOMAIN),
            'value' => get_post_meta($variation->ID, '_sale_price_wholesale', true),
            'desc_tip' => true,
            'description' => __('Set a wholesale sale price for this variation.', self::TEXTDOMAIN),
            'type' => 'text',
            'data_type' => 'price',
        ));
        echo '</div>';
    }
    function woocommerce_save_product_variation($variation_id, $i) {
        // Перевіряємо та зберігаємо оптову регулярну ціну
        if (isset($_POST['regular_price_wholesale'][$variation_id])) {
            $regular_price = wc_format_decimal($_POST['regular_price_wholesale'][$variation_id]);
            if (!empty($regular_price)) {
                update_post_meta($variation_id, '_regular_price_wholesale', $regular_price);
            } else {
                delete_post_meta($variation_id, '_regular_price_wholesale');
            }
        }
    
        // Перевіряємо та зберігаємо оптову ціну розпродажу
        if (isset($_POST['sale_price_wholesale'][$variation_id])) {
            $sale_price = wc_format_decimal($_POST['sale_price_wholesale'][$variation_id]);
            if (!empty($sale_price)) {
                update_post_meta($variation_id, '_sale_price_wholesale', $sale_price);
            } else {
                delete_post_meta($variation_id, '_sale_price_wholesale');
            }
        }
    }
    function auth_link () {
        if (is_user_logged_in()) {
           return "<a href='".wp_logout_url( home_url())."' title='Log out'>Log out</a>";
        }
        return "<a href='".get_permalink( get_option('woocommerce_myaccount_page_id') )."' title='Log in'>Log in</a>";
    }
    // Додаємо поле для "wholesale stock" в адміні панелі продуктів
    public function add_wholesale_stock_field() {
        global $product_object;
        echo '<div class="inventory_new_stock_information options_group show_if_simple show_if_variable">';
        woocommerce_wp_text_input( array(
            'id' => '_wholesale_stock',
            'label' => __('Wholesale Stock', self::TEXTDOMAIN),
            'description' => __('Separate stock for wholesale customers.', self::TEXTDOMAIN),
            'type' => 'number',
            'desc_tip' => true,
            'value' => $product_object->get_meta( '_wholesale_stock' )
        ));
        echo '</div>';
    }
    // Зберігаємо значення "wholesale stock"
    public function save_wholesale_stock_field($product) {
        if( isset( $_POST['_wholesale_stock'] ) ) {
			$product->update_meta_data( '_wholesale_stock', sanitize_text_field( $_POST['_wholesale_stock'] ) );
			$product->save_meta_data();
		}
    }
    public function filter_product_stock_based_on_user_role($is_in_stock, $product) {
        // Отримуємо роль користувача
        if (self::is_wholesaler()) {
            // Отримуємо наявність для ролі wholesale
            $wholesale_stock = get_post_meta($product->get_id(), '_wholesale_stock', true);
            return ($wholesale_stock > 0);
        }
        return $is_in_stock;
    }
    public function reduce_wholesale_stock($order_id) {
        $order = wc_get_order($order_id);
        
        // Якщо користувач у ролі wholesale
        if (self::is_wholesaler()) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $wholesale_stock = get_post_meta($product_id, '_wholesale_stock', true);
                $new_stock = max(0, $wholesale_stock ? $wholesale_stock : 0 - $item->get_quantity());
                update_post_meta($product_id, '_wholesale_stock', $new_stock);
            }
        }
    }
    /**
	 * ADD variation custom field
	 *
	 * @since 1.0.0
	 */
	public function add_variation_wholesale_stock_field( $loop, $variation_data, $variation ) {

		$variation_product = wc_get_product( $variation->ID );

        woocommerce_wp_text_input( array(
            'id' => '_wholesale_stock' . '[' . $loop . ']',
            'label' => __('Wholesale Stock', self::TEXTDOMAIN),
            'description' => __('Separate stock for wholesale customers.', self::TEXTDOMAIN),
            'type' => 'number',
            'desc_tip' => true,
            'value'    => $variation_product->get_meta( '_wholesale_stock' )
        ));

	}

	/**
	* SAVE variation custom field
	 *
	 * @since 1.0.0
	 */
	public function save_variation_wholesale_stock_field( $variation_id, $i  ) {

		if( isset( $_POST['_wholesale_stock'][$i] ) ) {
			$variation_product = wc_get_product( $variation_id );
			$variation_product->update_meta_data( '_wholesale_stock', sanitize_text_field( $_POST['_wholesale_stock'][$i] ) );
			$variation_product->save_meta_data();
		}

	}
    // Додаємо колонку "Wholesale Stock" у таблицю товарів
    public function add_wholesale_stock_column($columns) {
        // Вставляємо нову колонку після "stock"
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'is_in_stock') {
                $new_columns['wholesale_stock'] = __('Wholesale Stock', self::TEXTDOMAIN);
            }
            if ($key === 'price') {
                $new_columns['wholesale_price'] = __('Wholesale Price', self::TEXTDOMAIN);
            }
        }
        return $new_columns;
    }
    // Відображаємо значення для колонки
    public function display_wholesale_stock_column($column, $post_id) {
        if ($column === 'wholesale_stock') {
            $wholesale_stock = get_post_meta($post_id, '_wholesale_stock', true);
            echo ($wholesale_stock == '' || $wholesale_stock == "0") ? '—' : esc_html($wholesale_stock);
        }
        if ($column === 'wholesale_price') {
            $product = wc_get_product($post_id);
            if ($product->is_type('variable')) {
                $price = self::get_wholesale_variable_html($post_id);
                echo $price == "" ? "—" : $price;
            } else {
                echo self::get_simple_product_price($post_id);
            }
        }
    }
    public static function get_simple_product_price($product_id) {
        $price = '—';
        $wholesale_regular_price = get_post_meta($product_id, self::META_NAME_REGULAR_PRICE, true);
        $wholesale_sale_price = get_post_meta($product_id, self::META_NAME_SALE_PRICE, true);
        
        if ($wholesale_regular_price && $wholesale_sale_price) {
            $price = min([
                $wholesale_regular_price,
                $wholesale_sale_price
            ]);
            $price = wc_price($price);
        } elseif ($wholesale_regular_price) {
            $price = wc_price($wholesale_regular_price);
        }
        return $price;
    }
    function custom_get_stock_quantity( $value, $product ) {
        if (self::is_admin() && $product->get_meta("_wholesale_stock")) {
            return $product->get_meta("_wholesale_stock");
        }
        return $value;
    }
}
new WOO_Wholeseller();