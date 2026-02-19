<?php
    
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
        add_action("wp_ajax_toggle_bypass_minimum_order", [$this, "wp_ajax_toggle_bypass_minimum_order"]);

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
        
        // Add link with "Wholesale" filter on the order list
        add_action("admin_footer", [$this, "add_wholesale_status_to_orders_page"]);
        add_action("pre_get_posts", [$this, "filter_orders_by_wholesaler"]);

        // Print Priselist table for admin
        add_action("woocommerce_after_add_to_cart_form", [$this, "admin_product_price"]);
        
        // Clear cache after product's update 
        add_action('woocommerce_update_product', [$this, "clear_variation_prices_cache"], 100, 1);

        
        // Додаємо чекбокси на сторінку редагування товару
        add_action('woocommerce_product_options_general_product_data', [$this, "add_checkboxes_to_product_edit_page"]);
        // Зберігаємо значення чекбоксів
        add_action('woocommerce_process_product_meta', [$this, "save_display_product_setting"]);
        add_action('woocommerce_product_query', [$this, "archive_product_display_manager"]);
        add_action('template_redirect', [$this, "single_product_display_manager"]);

        add_action('bulk_edit_custom_box', function($column_name, $post_type) {
            if ($post_type !== 'product') {
                return;
            }
        
            if ($column_name === 'name') { // "name" відповідає назві колонки, в якій буде відображено форму
                ?>
                <fieldset class="inline-edit-col-left">
                    <div class="inline-edit-col">
                        <label class="alignleft" style="margin-right: 20px;">
                            <input type="checkbox" name="_show_for_retail_bulk" value="yes">
                            <span><?php _e('Show for Retail', self::TEXTDOMAIN); ?></span>
                        </label>
                        <label class="alignleft">
                            <input type="checkbox" name="_show_for_wholesale_bulk" value="yes">
                            <span><?php _e('Show for Wholesale', self::TEXTDOMAIN); ?></span>
                        </label>
                    </div>
                </fieldset>
                <?php
            }
        }, 10, 2);

        add_action('save_post', function($post_id) {
            // Перевіряємо, чи це bulk edit запит
            if (!isset($_REQUEST['bulk_edit']) || empty($_REQUEST['bulk_edit'])) {
                return;
            }
        
            // Зберігаємо значення для роздрібних користувачів
            if (isset($_REQUEST['_show_for_retail_bulk'])) {
                update_post_meta($post_id, '_show_for_retail', 'yes');
            } else {
                update_post_meta($post_id, '_show_for_retail', 'no');
            }
        
            // Зберігаємо значення для оптовиків
            if (isset($_REQUEST['_show_for_wholesale_bulk'])) {
                update_post_meta($post_id, '_show_for_wholesale', 'yes');
            } else {
                update_post_meta($post_id, '_show_for_wholesale', 'no');
            }
        });

        // Додаємо колонки в таблицю товарів
        add_filter('manage_edit-product_columns', function($columns) {
            $columns['_show_for_retail'] = __('Retail', self::TEXTDOMAIN);
            $columns['_show_for_wholesale'] = __('Wholesale', self::TEXTDOMAIN);
            return $columns;
        });

        add_filter( 'woocommerce_ajax_variation_threshold', function () {
            return 100; // Set your desired variation limit here
        }, 10 );
        

        // Відображаємо значення в колонках
        add_action('manage_product_posts_custom_column', function($column, $post_id) {
            $white_list = [
                "_show_for_retail",
                "_show_for_wholesale",
            ];
            if (!in_array($column, $white_list)) return;

            echo match (get_post_meta($post_id, $column, true)) {
                "yes" => __('Yes', self::TEXTDOMAIN),
                "no" => __('No', self::TEXTDOMAIN),
                default => "Unset",
            };
        }, 10, 2);
        
        add_action('restrict_manage_posts', function($post_type) {
            if ($post_type !== 'product') {
                return;
            }
        
            // Фільтр для Retail
            ?>
            <select name="_show_for_retail_filter">
                <option value=""><?php _e('Filter by Retail', self::TEXTDOMAIN); ?></option>
                <option value="yes" <?php selected($_GET['_show_for_retail_filter'] ?? '', 'yes'); ?>>
                    <?php _e('Yes', self::TEXTDOMAIN); ?>
                </option>
                <option value="no" <?php selected($_GET['_show_for_retail_filter'] ?? '', 'no'); ?>>
                    <?php _e('No', self::TEXTDOMAIN); ?>
                </option>
            </select>
            <?php
        
            // Фільтр для Wholesale
            ?>
            <select name="_show_for_wholesale_filter">
                <option value=""><?php _e('Filter by Wholesale', self::TEXTDOMAIN); ?></option>
                <option value="yes" <?php selected($_GET['_show_for_wholesale_filter'] ?? '', 'yes'); ?>>
                    <?php _e('Yes', self::TEXTDOMAIN); ?>
                </option>
                <option value="no" <?php selected($_GET['_show_for_wholesale_filter'] ?? '', 'no'); ?>>
                    <?php _e('No', self::TEXTDOMAIN); ?>
                </option>
            </select>
            <?php
        });

        add_action('pre_get_posts', function($query) {
            if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'product') {
                return;
            }
        
            // Фільтрація за Retail
            if (!empty($_GET['_show_for_retail_filter'])) {
                $query->set('meta_query', array_merge(
                    $query->get('meta_query') ?: array(),
                    array(
                        array(
                            'key' => '_show_for_retail',
                            'value' => sanitize_text_field($_GET['_show_for_retail_filter']),
                            'compare' => '='
                        )
                    )
                ));
            }
        
            // Фільтрація за Wholesale
            if (!empty($_GET['_show_for_wholesale_filter'])) {
                $query->set('meta_query', array_merge(
                    $query->get('meta_query') ?: array(),
                    array(
                        array(
                            'key' => '_show_for_wholesale',
                            'value' => sanitize_text_field($_GET['_show_for_wholesale_filter']),
                            'compare' => '='
                        )
                    )
                ));
            }
        });

        add_action('restrict_manage_posts', function($post_type) {
            if ($post_type === 'product') {
                echo '<a href="' . esc_url(remove_query_arg(array('_show_for_retail_filter', '_show_for_wholesale_filter'))) . '" class="button">' . __('Clear Filters', self::TEXTDOMAIN) . '</a>';
            }
        });
        add_action('woocommerce_before_order_notes', [$this, 'add_wholesaler_delivery_date_field']);
        add_filter('gettext', [$this, 'wc_billing_field_strings'], 20, 3 );
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_wholesaler_delivery_date_field']);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'show_wholesaler_delivery_date_in_admin'], 10, 1);
        add_filter('woocommerce_email_order_meta_fields', [$this, 'email_show_wholesaler_delivery_date'], 10, 3);
        add_filter('the_title', [$this, 'change_checkout_page_title_for_wholesaler'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [$this, 'disable_default_email_for_wholesaler'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [$this, 'disable_default_email_for_wholesaler'], 10, 2);
        add_action('woocommerce_order_status_pending_to_on-hold', [$this, 'send_wholesaler_custom_email'], 20);
        add_filter('woocommerce_available_payment_gateways', [$this, 'limit_payment_methods_for_wholesalers']);
        add_filter('body_class', [$this, 'add_user_role_to_body_class']);
        add_filter('woocommerce_package_rates', [$this, 'hide_shipping_methods_for_wholesalers'], 10, 2);
        add_filter('woocommerce_cart_needs_shipping', [$this, 'wholesaler_disable_shipping_requirement'], 10, 1);

        add_filter( 'woocommerce_email_classes', [$this, 'add_email_class'] );
        add_filter('woocommerce_related_products', [$this, 'filter_related_products_for_wholesaler'], 10, 3);
        
        // Minimum order amount for wholesale
        add_action('woocommerce_check_cart_items', [$this, 'check_minimum_order_amount']);
        add_action('woocommerce_proceed_to_checkout', [$this, 'hide_checkout_button_if_below_minimum'], 1);
        
        // Add "Back to Dashboard" button for logged in users on my-account pages
        add_action('woocommerce_account_navigation', [$this, 'add_back_to_dashboard_in_navigation'], 99);
        
        // Add bypass minimum order field to user profile
        add_action('show_user_profile', [$this, 'add_bypass_minimum_order_field']);
        add_action('edit_user_profile', [$this, 'add_bypass_minimum_order_field']);
        add_action('personal_options_update', [$this, 'save_bypass_minimum_order_field']);
        add_action('edit_user_profile_update', [$this, 'save_bypass_minimum_order_field']);
    }
    
    /**
     * Add the custom email class to the WooCommerce Emails
     */
    function add_email_class( $email_classes ) {
        // Load the custom email class file
        require_once plugin_dir_path(__FILE__) . 'Wholesaler_Custom_Email.php';
        
        // Only add if the class was successfully loaded
        if (class_exists('Wholesaler_Custom_Email')) {
            $email_classes['Wholesaler_Custom_Email'] = new Wholesaler_Custom_Email();
        }
        
        return $email_classes;
    }
    function archive_product_display_manager($query) {
        if (is_admin() || self::is_admin()) {
            return;
        }
    
        $meta_query = $query->get('meta_query') ?: [];
        if (self::is_wholesaler()) {
            // Якщо користувач - wholesale, показуємо тільки товари для wholesale
            $meta_query[] = [
                'key' => '_show_for_wholesale',
                'value' => 'yes',
                'compare' => '='
            ];
        } else {
            // Для всіх інших (retail), ховаємо товари для wholesale
            $meta_query[] = [
                'key' => '_show_for_retail',
                'value' => 'yes',
                'compare' => '='
            ];
        }
        $query->set('meta_query', $meta_query);
    }
    function single_product_display_manager() {
        if (!is_product() || self::is_admin()) {
            return;
        }
    
        global $post;
    
        $show_for_wholesale = get_post_meta($post->ID, '_show_for_wholesale', true);
        $show_for_retail = get_post_meta($post->ID, '_show_for_retail', true);

        if (self::is_wholesaler()) {
            // Wholesale користувач може бачити тільки товари, дозволені для wholesale
            if ($show_for_wholesale === 'no') {
                wp_redirect(home_url());
                exit;
            }
        } else {
            // Retail користувач не може бачити товари для wholesale
            if ($show_for_retail === 'no') {
                wp_redirect(home_url());
                exit;
            }
        }
    }
    function save_display_product_setting($post_id) {
        $show_for_retail = isset($_POST['_show_for_retail']) ? 'yes' : 'no';
        $show_for_wholesale = isset($_POST['_show_for_wholesale']) ? 'yes' : 'no';

        update_post_meta($post_id, '_show_for_retail', $show_for_retail);
        update_post_meta($post_id, '_show_for_wholesale', $show_for_wholesale);
    }
    function add_checkboxes_to_product_edit_page() {
        global $post;

        // Отримуємо значення мета-поля або задаємо "yes" за замовчуванням для retail
        $show_for_retail = get_post_meta($post->ID, '_show_for_retail', true);
        $show_for_retail = ($show_for_retail === '') ? 'yes' : $show_for_retail;

        $show_for_wholesale = get_post_meta($post->ID, '_show_for_wholesale', true);
        $show_for_wholesale = ($show_for_wholesale === '') ? 'no' : $show_for_wholesale;

        echo '<div class="options_group">';

        woocommerce_wp_checkbox(array(
            'id' => '_show_for_retail',
            'label' => __('Show for Retail', self::TEXTDOMAIN),
            'description' => __('Display this product for retail users.', self::TEXTDOMAIN),
            'desc_tip' => true,
            'value' => $show_for_retail, // Встановлюємо значення
        ));

        woocommerce_wp_checkbox(array(
            'id' => '_show_for_wholesale',
            'label' => __('Show for Wholesale', self::TEXTDOMAIN),
            'description' => __('Display this product for wholesale users.', self::TEXTDOMAIN),
            'desc_tip' => true,
            'value' => $show_for_wholesale, // Встановлюємо значення
        ));

        echo '</div>';
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
    public function filter_orders_by_wholesaler($query) {
        global $pagenow, $post_type;

        // Перевірка: в адмінці, сторінка замовлень, є фільтр wholesaler
        if (
            is_admin() &&
            $pagenow === 'edit.php' &&
            $post_type === 'shop_order' &&
            isset($_GET['wholesaler_filter']) &&
            $_GET['wholesaler_filter'] === '1' // 👉 краще додати значення
        ) {
            // Отримуємо всі ID користувачів з роллю wholesaler
            $wholesaler_ids = get_users(array(
                'role'   => 'wholesaler',
                'fields' => 'ID',
            ));

            if (empty($wholesaler_ids)) {
                $wholesaler_ids = [1]; // 👉 фіктивне значення, щоб не було помилки
            }

            // Додаємо meta_query на поле _customer_user
            $query->set('meta_query', array(
                array(
                    'key'     => '_customer_user',
                    'value'   => $wholesaler_ids,
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

        if (empty($wholesaler_ids)) {
            $wholesaler_ids = [1]; // 👉 фіктивне значення, щоб не було помилки
        }
    
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
    public static function is_admin() {
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles);
    }
    public static function is_wholesaler($user_id = null) {
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
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
        $columns['bypass_minimum_order'] = __('Bypass Min Order', self::TEXTDOMAIN);
        return $columns;
    }
    function manage_users_custom_column ($value, $column_name, $user_id) {
        if ($column_name === 'wholesaler_toggle') {
            $is_wholesaler = self::is_wholesaler($user_id);
            $checked = $is_wholesaler ? 'checked' : '';
    
            return sprintf(
                '<input type="checkbox" class="wholesaler-toggle" data-user-id="%d" %s>',
                esc_attr($user_id),
                $checked
            );
        } elseif ($column_name === 'bypass_minimum_order') {
            // Показуємо чекбокс тільки для wholesaler
            if (!self::is_wholesaler($user_id)) {
                return '—';
            }
            
            $bypass_minimum = get_user_meta($user_id, '_bypass_minimum_order', true);
            $checked = ($bypass_minimum === 'yes') ? 'checked' : '';
            
            return sprintf(
                '<input type="checkbox" class="bypass-minimum-toggle" data-user-id="%d" %s>',
                esc_attr($user_id),
                $checked
            );
        } elseif ($column_name === 'wholesaler_url') {
            $is_wholesaler = self::is_wholesaler($user_id);
            $website = get_user_meta($user_id, '_url', true);
            if ($website) {
                return sprintf('<a href="%s" target="_blank">%s</a>', esc_url($website), esc_html($website));
            }
        }
        return $value;
    }
    function admin_enqueue_scripts ($hook) {
        // Цільова сторінка "Користувачі"
        wp_enqueue_style('wholesaler-app', plugin_dir_url(__FILE__) . 'css/wholesaler-app.css', array(), '1.2', false);
        if ($hook != 'users.php') {
            return;
        }
        wp_enqueue_script('wholesaler-toggle-users', plugin_dir_url(__FILE__) . 'js/wholesaler-toggle.js', array('jquery'), '1.2', true);
        wp_localize_script('wholesaler-toggle-users', 'wholesalerUsersAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wholesaler_users_nonce')
        ));
    }
    function wp_enqueue_scripts () {
        wp_enqueue_style('wholesaler-toggle-users', plugin_dir_url(__FILE__) . 'css/wholesaler-toggle.css', array(), '1.2', false);
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
    
    function wp_ajax_toggle_bypass_minimum_order () {
        check_ajax_referer('wholesaler_users_nonce', 'nonce');
    
        $user_id = absint($_POST['user_id']);
        $is_checked = sanitize_text_field($_POST['is_checked']);
    
        if ($user_id && self::is_wholesaler($user_id)) {
            $bypass_value = ($is_checked === 'true') ? 'yes' : 'no';
            update_user_meta($user_id, '_bypass_minimum_order', $bypass_value);
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
           return "<p class='auth_link'><a href='".wp_logout_url( home_url())."' title='Log out'>Log out</a></p>";
        }
        return "<p class='auth_link'><a href='".get_permalink( get_option('woocommerce_myaccount_page_id') )."' title='Log in'>Log in</a></p>";
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
    function wc_billing_field_strings( $translated_text, $text, $domain ) {
        if (!self::is_wholesaler()) {
            return $translated_text;
        }

        switch ( $translated_text ) {
            case 'Billing details' :
                $translated_text = __( 'Shipping details', 'woocommerce' );
                break;
        }
        
        return $translated_text;
    }
    public function add_wholesaler_delivery_date_field($checkout) {
        if (!is_user_logged_in()) return;

        if (self::is_wholesaler()) {
            // echo '<h3>' . __('Delivery Details') . '</h3>';

            woocommerce_form_field('delivery_date', [
                'type'        => 'date',
                'class'       => ['form-row-wide'],
                'label'       => __('Desired delivery date'),
                'required'    => false,
            ], $checkout->get_value('delivery_date'));
        }
    }
    public function save_wholesaler_delivery_date_field($order_id) {
        if (!empty($_POST['delivery_date'])) {
            update_post_meta($order_id, '_delivery_date', sanitize_text_field($_POST['delivery_date']));
        }
    }
    public function show_wholesaler_delivery_date_in_admin($order) {
        $date = get_post_meta($order->get_id(), '_delivery_date', true);
        if ($date) {
            echo '<p><strong>' . __('Desired delivery date') . ':</strong> ' . esc_html($date) . '</p>';
        }
    }
    public function email_show_wholesaler_delivery_date($fields, $sent_to_admin, $order) {
        $date = get_post_meta($order->get_id(), '_delivery_date', true);
        if ($date) {
            $fields['delivery_date'] = [
                'label' => __('Desired delivery date'),
                'value' => $date,
            ];
        }
        return $fields;
    }
    public function change_checkout_page_title_for_wholesaler($title, $post_id) {
        if (!is_admin() && is_checkout() && is_user_logged_in()) {
            if (self::is_wholesaler()) {
                $checkout_page_id = wc_get_page_id('checkout');
                if ($post_id === $checkout_page_id) {
                    return 'For invoicing and shipping submit order';
                }
            }
        }
        return $title;
    }
    
    public function disable_default_email_for_wholesaler($enabled, $order) {
        if (!$order instanceof WC_Order) return $enabled;

        $user_id = $order->get_user_id();
        if (!$user_id) return $enabled;

        if (self::is_wholesaler()) {
            return false;
        }

        return $enabled;
    }
    public function send_wholesaler_custom_email($order_id) {
        if (!self::is_wholesaler()) return;

        $emails = WC()->mailer()->get_emails();
        $email  = $emails['Wholesaler_Custom_Email'];
        $email->trigger( $order_id );

        // $to = $order->get_billing_email();
        // $subject = 'Confirmation of Apsara order';
        // $message = <<<HTML
        // <p>Thank you for your order. Apsara will be contacting you with updates on your order, availability, and shipping rates.</p>
        // <p>If we do not have your current payment information on file, we will contact you by telephone.</p>
        // <p>Typically, we ship through FedEx or USPS. If you have any special delivery instructions, please let us know.</p>
        // <p>Once your order is initiated, Apsara will update you on processing, delivery and tracking of your order.</p>
        // HTML;

        // $headers = ['Content-Type: text/html; charset=UTF-8'];

        // wp_mail($to, $subject, $message, $headers);
    }
    public function limit_payment_methods_for_wholesalers( $available_gateways ) {

        // Do nothing in admin or outside checkout
        if ( is_admin() || ! is_checkout() ) {
            return $available_gateways;
        }

        // If user is NOT logged in → remove BACS
        if ( ! is_user_logged_in() ) {
            unset( $available_gateways['bacs'] );
            return $available_gateways;
        }

        // Logged-in WHOLESALE user → allow ONLY BACS
        if ( self::is_wholesaler() ) {

            $allowed_gateways = [ 'bacs' ];

            foreach ( $available_gateways as $gateway_id => $gateway ) {
                if ( ! in_array( $gateway_id, $allowed_gateways, true ) ) {
                    unset( $available_gateways[ $gateway_id ] );
                }
            }

            return $available_gateways;
        }

        // Logged-in NON-wholesale user → remove BACS
        unset( $available_gateways['bacs'] );

        return $available_gateways;
    }

    public function add_user_role_to_body_class($classes) {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            foreach ((array) $user->roles as $role) {
                $classes[] = 'user-role-' . sanitize_html_class($role);
            }
        } else {
            $classes[] = 'user-role-guest';
        }
        return $classes;
    }
    public function hide_shipping_methods_for_wholesalers($rates, $package) {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            if (self::is_wholesaler()) {
                return []; // ❌ приховує всі методи доставки
            }
        }
        return $rates;
    }
    public function wholesaler_disable_shipping_requirement($needs_shipping) {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (self::is_wholesaler()) {
                return false; // 🟢 не потрібно доставку
            }
        }
        return $needs_shipping;
    }
    
    public function filter_related_products_for_wholesaler($related_posts, $product_id, $args) {
        if (self::is_admin()) {
            return $related_posts;
        }

        if (self::is_wholesaler()) {
            // Фільтруємо related products - залишаємо тільки ті, що дозволені для wholesale
            $filtered_posts = [];
            foreach ($related_posts as $related_id) {
                $show_for_wholesale = get_post_meta($related_id, '_show_for_wholesale', true);
                if ($show_for_wholesale === 'yes') {
                    $filtered_posts[] = $related_id;
                }
            }
            return $filtered_posts;
        } else {
            // Для retail користувачів фільтруємо навпаки
            $filtered_posts = [];
            foreach ($related_posts as $related_id) {
                $show_for_retail = get_post_meta($related_id, '_show_for_retail', true);
                if ($show_for_retail === 'yes') {
                    $filtered_posts[] = $related_id;
                }
            }
            return $filtered_posts;
        }
    }
    
    /**
     * Check if a wholesaler user has completed any previous orders
     */
    private function has_completed_orders($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        // Check if user has any completed orders
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => 1,
            'return' => 'ids'
        ]);
        
        return !empty($orders);
    }
    
    /**
     * Check minimum order amount for wholesale users
     */
    public function check_minimum_order_amount() {
        if (WC()->cart->is_empty()) {
            return;
        }

        if (!self::is_wholesaler()) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        // Check if bypass is enabled for this user
        $bypass_minimum = get_user_meta($user_id, '_bypass_minimum_order', true);
        
        if ($bypass_minimum === 'yes') {
            return;
        }
        
        // Check if user has completed any previous orders
        // Minimum order ONLY applies to the first (opening) order
        if ($this->has_completed_orders($user_id)) {
            return;
        }
        
        $minimum_amount = 500;
        $cart_total = WC()->cart->get_subtotal();
        
        if ($cart_total < $minimum_amount) {
            $remaining = $minimum_amount - $cart_total;
            wc_add_notice(
                sprintf(
                    __('Minimum opening order amount for new wholesale customers is %s. Please add %s more to your cart to proceed with checkout. (This minimum only applies to your first order)', self::TEXTDOMAIN),
                    wc_price($minimum_amount),
                    wc_price($remaining)
                ),
                'error'
            );
        }
    }
    
    /**
     * Hide checkout button if wholesale user hasn't met minimum
     */
    public function hide_checkout_button_if_below_minimum() {
        if (!self::is_wholesaler()) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        // Check if bypass is enabled for this user
        $bypass_minimum = get_user_meta($user_id, '_bypass_minimum_order', true);
        
        if ($bypass_minimum === 'yes') {
            return;
        }
        
        // Check if user has completed any previous orders
        // Minimum order ONLY applies to the first (opening) order
        if ($this->has_completed_orders($user_id)) {
            return;
        }
        
        $minimum_amount = 500;
        $cart_total = WC()->cart->get_subtotal();
        
        if ($cart_total < $minimum_amount) {
            // Приховуємо кнопку Proceed to Checkout
            echo '<style>.wc-proceed-to-checkout { display: none !important; }</style>';
        }
    }
    
    /**
     * Add "Back to Dashboard" button in my-account navigation
     */
    public function add_back_to_dashboard_in_navigation() {
        if (!is_user_logged_in() || !is_account_page()) {
            return;
        }
        
        // Показуємо кнопку тільки якщо ми НЕ на головній сторінці dashboard
        global $wp;
        $current_endpoint = isset($wp->query_vars) ? key($wp->query_vars) : '';
        
        if (empty($current_endpoint) || $current_endpoint === 'dashboard' || $current_endpoint === 'page') {
            return;
        }
        
        $dashboard_url = wc_get_page_permalink('myaccount');
        echo '<a href="' . esc_url($dashboard_url) . '" style="font-weight: bold; margin-bottom: 10px; display: inline-block;">← Back to Dashboard</a>';
    }
    
    /**
     * Add field to user profile to bypass minimum order
     */
    public function add_bypass_minimum_order_field($user) {
        if (!self::is_wholesaler($user->ID)) {
            return;
        }
        
        $bypass_minimum = get_user_meta($user->ID, '_bypass_minimum_order', true);
        ?>
        <h3><?php _e('Wholesale Settings', self::TEXTDOMAIN); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="bypass_minimum_order"><?php _e('Bypass Minimum Order Amount', self::TEXTDOMAIN); ?></label></th>
                <td>
                    <input type="checkbox" name="bypass_minimum_order" id="bypass_minimum_order" value="yes" <?php checked($bypass_minimum, 'yes'); ?> />
                    <span class="description"><?php _e('Allow this user to place orders below $500 minimum.', self::TEXTDOMAIN); ?></span>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save bypass minimum order field
     */
    public function save_bypass_minimum_order_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        $bypass_value = isset($_POST['bypass_minimum_order']) ? 'yes' : 'no';
        update_user_meta($user_id, '_bypass_minimum_order', $bypass_value);
    }
}