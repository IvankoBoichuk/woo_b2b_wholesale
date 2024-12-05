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
        // Shortcode for auth link in footer
        add_shortcode("fa_auth_link", [$this, "auth_link"]);

        // Add new role "Wholesaler"
        add_action("init", [$this, "add_wholesaler_role"]);

        // Enqueue Scripts & Styles
        add_action("admin_enqueue_scripts", [$this, "admin_enqueue_scripts"]);
        add_action("wp_enqueue_scripts", [$this, "wp_enqueue_scripts"]);

        // AJAX Handler for toggle customer/wholesaler roles 
        add_action("wp_ajax_toggle_wholesaler_role_user", [$this, "wp_ajax_toggle_wholesaler_role_user"]);

        // Add new column "Wholesaler" to users table in admin
        add_filter("manage_users_columns", [$this, "manage_users_columns"]);

        // Add checkbox for toggle customer/wholesaler roles 
        add_filter("manage_users_custom_column", [$this, "manage_users_custom_column"], 100, 3);

        // Add custom wholesale price field to the product edit page
        add_action("woocommerce_product_options_pricing", [$this, "woocommerce_product_options_pricing"]);

        // Save the wholesale price field value
        add_action("woocommerce_process_product_meta", [$this, "woocommerce_process_product_meta"]);

        // Add wholesale price field to variations
        add_action("woocommerce_product_after_variable_attributes", [$this, "woocommerce_product_after_variable_attributes"], 100, 3);

        // Save wholesale price for variations
        add_action("woocommerce_save_product_variation", [$this, "woocommerce_save_product_variation"], 100, 2);

        // Price changing. Depends from user role
        // -- Simple product
        add_filter("woocommerce_product_get_regular_price", [$this, "get_regular_price"], 100, 2 );
        add_filter("woocommerce_product_get_sale_price", [$this, "get_sale_price"], 100, 2 );
        add_filter("woocommerce_product_get_price", [$this, "get_price"], 100, 2 );
        // -- Variable product
        add_filter("woocommerce_product_variation_get_regular_price", [$this, "get_regular_price"], 100, 2 );
        add_filter("woocommerce_product_variation_get_sale_price", [$this, "get_sale_price"], 100, 2 );
        add_filter("woocommerce_product_variation_get_price", [$this, "get_price"], 100, 2 );
        add_filter("woocommerce_variation_prices", [$this, "variation_prices_array"], 100, 3);
        
        // Unset "Downloads" from menu
        add_filter("woocommerce_account_menu_items", [$this, "unset_downloads"], 100);

        // Get Stock Status. Depends from user role
        add_filter("woocommerce_product_is_in_stock", [$this, "filter_product_stock_based_on_user_role"], 100, 2);
        
        // Change meta-key for display different stock count. Depends from user role
        add_filter("woocommerce_update_product_stock_query", [$this, "replace_stock_meta"], 100, 1);

        // Add "Wholesale stock" field to product edit page
        add_action("woocommerce_product_options_inventory_product_data", [$this, "add_wholesale_stock_field"], 100);
        add_action("woocommerce_admin_process_product_object", [$this, "save_wholesale_stock_field"], 100, 1);
        add_action("woocommerce_variation_options_inventory", [$this, "add_variation_wholesale_stock_field"], 100, 3 );
		add_action("woocommerce_save_product_variation", [$this, "save_variation_wholesale_stock_field"], 100, 2 );


        // Add link with "Wholesale" filter on the order list
        add_action("admin_footer", [$this, "add_wholesale_status_to_orders_page"]);
        add_action("pre_get_posts", [$this, "filter_orders_by_wholesaler"]);

        // Add new "Wholesale stock" column
        add_filter("manage_edit-product_columns", [$this, "add_wholesale_stock_column"]);
        add_action("manage_product_posts_custom_column", [$this, "display_wholesale_stock_column"], 100, 2);
        
        // Get stock quantity. Depends from user role
        add_filter("woocommerce_product_get_stock_quantity", [$this, "custom_get_stock_quantity"], 100, 2 );
        add_filter("woocommerce_product_variation_get_stock_quantity", [$this, "custom_get_stock_quantity"], 100, 2 );


        // Print Priselist table for admin
        add_action("woocommerce_after_add_to_cart_form", [$this, "admin_product_price"]);
        
        // Clear cache after product's update 
        add_action('woocommerce_update_product', [$this, "clear_variation_prices_cache"], 100, 1);
    }
    function clear_variation_prices_cache($product_id) {
        $transient_key = 'wc_var_prices_' . $product_id . '_w';
        delete_transient($transient_key);
    }
    function variation_prices_array($prices_array, $product, $for_display) {
        if (!self::is_wholesaler()) {
            return $prices_array;
        }
    
        // Ключ для кешу
        $transient_key = 'wc_var_prices_' . $product->get_id() . '_w';
    
        // Спроба отримати кешовані дані
        $cached_prices = get_transient($transient_key);
    
        if ($cached_prices !== false) {
            return $cached_prices;
        }
    
        // Обробка варіацій і визначення цін
        foreach ($prices_array as $key => &$variations) {
            foreach ($variations as $variation_id => &$original_price) {
                $variation = wc_get_product($variation_id);
                $wholesale_price = $variation->{"get_$key"}();
                $original_price = $wholesale_price ? $wholesale_price : $original_price;
            }
        }
    
        // Збереження результату в кеш
        set_transient($transient_key, $prices_array, DAY_IN_SECONDS * 30);
        return $prices_array;
    }
    function admin_product_price() {
        global $product;
        if (!self::is_admin()) {
            return;
        }
        if ($product->is_type("variable")) {
            $this->print_variable_pricelist($product);
        } else {
            $this->print_pricelist($product);
        }
    }
    function print_pricelist (WC_Product $product) {
        $pricelist = [
            $product->get_regular_price(), $product->get_sale_price(),
            $product->get_meta(self::META_NAME_REGULAR_PRICE), $product->get_meta(self::META_NAME_SALE_PRICE)
        ];
        $table = [
            ["", "Regular Price", "Sale Price"],
            ["User:", $pricelist[0] ? wc_price($pricelist[0]) : "—", $pricelist[1] ? wc_price($pricelist[1]) : "—"],
            ["Wholesale:", $pricelist[2] ? wc_price($pricelist[2]) : "—", $pricelist[3] ? wc_price($pricelist[3]) : "—"],
        ];
        
        echo "<div style='
            margin-top: 20px;
            margin-bottom: 20px;
        '>
            <table border='1' style='border-collapse: collapse; width: 100%;'>
                <thead>
                    <tr>
                        <th></th>
                        <th>Regular Price</th>
                        <th>Sale Price</th>
                    </tr>
                </thead>
                <tbody>";
        
        foreach ($table as $index => $row) {
            if ($index === 0) continue; // Пропускаємо заголовок, бо він уже доданий у <thead>
            echo "<tr>";
            foreach ($row as $cell) {
                echo "<td style='padding: 8px; text-align: left;'>$cell</td>";
            }
            echo "</tr>";
        }
        
        echo "    </tbody>
            </table>
        </div>";
    }
    function print_variable_pricelist(WC_Product $product) {
        // Отримуємо усі варіації товару
        $variations = $product->get_children();
        $variation_prices = [];
    
        foreach ($variations as $variation_id) {
            $variation = new WC_Product_Variation($variation_id);
            $attributes = $variation->get_attributes();
            $attribute_values = [];
    
            // Збираємо значення атрибутів для кожної варіації
            foreach ($attributes as $attribute_name => $attribute_value) {
                $attribute_values[] = wc_attribute_label($attribute_name) . ": " . $attribute_value;
            }
    
            $regular_price = $variation->get_regular_price();
            $sale_price = $variation->get_sale_price();
            $wholesale_price = $variation->get_meta(self::META_NAME_REGULAR_PRICE);
            $wholesale_sale_price = $variation->get_meta(self::META_NAME_SALE_PRICE);
            
            $variation_prices[] = [
                'attributes' => implode(", ", $attribute_values),
                'regular_price_user' => $regular_price ? wc_price($regular_price) : '—',
                'sale_price_user' => $sale_price ? wc_price($sale_price) : '—',
                'regular_price_wholesale' => $wholesale_price ? wc_price($wholesale_price) : '—',
                'sale_price_wholesale' => $wholesale_sale_price ? wc_price($wholesale_sale_price) : '—'
            ];
        }
    
        // Створюємо таблицю
        echo "<div style='
            margin-top: 20px;
            margin-bottom: 20px;
        '>
            <table border='1' class='admin-pricelist'>
                <thead>
                    <tr>
                        <th></th>
                        <th>User Role</th>
                        <th>Regular Price</th>
                        <th>Sale Price</th>
                    </tr>
                </thead>
                <tbody>";
    
        // Додаємо варіації
        foreach ($variation_prices as $index => $variation) {
            echo "<tr>";
            echo "<td rowspan='2' style='vertical-align: middle;'>" . $variation['attributes'] . "</td>"; // Назва атрибутів з rowspan="2"
    
            // Для користувача
            echo "<td>User</td>";
            echo "<td>" . $variation['regular_price_user'] . "</td>";
            echo "<td>" . $variation['sale_price_user'] . "</td>";
            echo "</tr>";
    
            // Для оптового покупця
            echo "<tr>";
            echo "<td>Wholesale</td>";
            echo "<td>" . $variation['regular_price_wholesale'] . "</td>";
            echo "<td>" . $variation['sale_price_wholesale'] . "</td>";
            echo "</tr>";
        }
    
        echo "    </tbody>
            </table>
        </div>";
    }
    function replace_stock_meta($sql){
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
    function get_wholesale_variable_html ($product_id) {
        $product = wc_get_product($product_id);
        $price_html = "";
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $product = wc_get_product($variation_id);
            $wholesale_regular_price = $product->get_meta(self::META_NAME_REGULAR_PRICE);
            $wholesale_sale_price = $product->get_meta(self::META_NAME_SALE_PRICE);
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
    function get_regular_price($price, $product) {
        if (!self::is_wholesaler()) {
            return $price;
        }
        return $product->get_meta(self::META_NAME_REGULAR_PRICE);
    }
    function get_sale_price($price, $product) {
        if (!self::is_wholesaler()) {
            return $price;
        }
        return $product->get_meta(self::META_NAME_SALE_PRICE);
    }
    function get_price( $price, $product ) {
        if (!self::is_wholesaler()) {
            return $price;
        }

        $wholesale_regular_price = $product->get_meta(self::META_NAME_REGULAR_PRICE);
        $wholesale_sale_price = $product->get_meta(self::META_NAME_SALE_PRICE);

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
           return "<p><a href='".wp_logout_url( home_url())."' title='Log out'>Log out</a></p>";
        }
        return "<p><a href='".get_permalink( get_option('woocommerce_myaccount_page_id') )."' title='Log in'>Log in</a></p>";
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
        $wholesale_stock = $product->get_meta("_wholesale_stock");
        if (self::is_wholesaler() && $wholesale_stock) {
            // Отримуємо наявність для ролі wholesale
            return $wholesale_stock > 0;
        }
        return $is_in_stock;
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
        $product = wc_get_product($post_id);
        switch ($column) {
            case 'wholesale_stock':
                $wholesale_stock = $product->get_meta('_wholesale_stock');
                echo ($wholesale_stock == '' || $wholesale_stock == "0") ? '—' : esc_html($wholesale_stock);
                break;

            case 'wholesale_price':
                if ($product->is_type('variable')) {
                    $price = self::get_wholesale_variable_html($post_id);
                    echo $price == "" ? "—" : $price;
                } else {
                    echo self::get_simple_product_price($post_id);
                }
                break;
        }
    }
    public static function get_simple_product_price($product_id) {
        $price = '—';
        $product = wc_get_product($product_id);
        $wholesale_regular_price = $product->get_meta(self::META_NAME_REGULAR_PRICE);
        $wholesale_sale_price = $product->get_meta(self::META_NAME_SALE_PRICE);
        
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
        if (self::is_wholesaler() && $product->get_meta("_wholesale_stock")) {
            return $product->get_meta("_wholesale_stock");
        }
        return $value;
    }
}
new WOO_Wholeseller();