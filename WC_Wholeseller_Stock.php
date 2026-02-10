<?php
/**
 * Class allows manage separete stock for regular and wholeseller users
 */

defined( 'ABSPATH' ) || die();

class WC_Wholeseller_Stock {
    const META_NAME_REGULAR_PRICE = "_regular_price_wholesale";
    const META_NAME_SALE_PRICE = "_sale_price_wholesale";
    const TEXTDOMAIN = "woo-wholeseller";

    function __construct() {
        // Change meta-key for display different stock count. Depends from user role
        add_filter("woocommerce_update_product_stock_query", [$this, "replace_stock_meta"], 100, 1);

        // Get Stock Status. Depends from user role
        add_filter("woocommerce_product_is_in_stock", [$this, "filter_product_stock_based_on_user_role"], 100, 2);

        // Add "Wholesale stock" field to product edit page
        add_action("woocommerce_product_options_inventory_product_data", [$this, "add_wholesale_stock_field"], 100);
        add_action("woocommerce_admin_process_product_object", [$this, "save_wholesale_stock_field"], 100, 1);
        add_action("woocommerce_variation_options_inventory", [$this, "add_variation_wholesale_stock_field"], 100, 3 );
		add_action("woocommerce_save_product_variation", [$this, "save_variation_wholesale_stock_field"], 100, 2 );
        
        // Add new "Wholesale stock" column
        add_filter("manage_edit-product_columns", [$this, "add_wholesale_stock_column"]);
        add_action("manage_product_posts_custom_column", [$this, "display_wholesale_stock_column"], 100, 2);
        
        // Get stock quantity. Depends from user role
        add_filter("woocommerce_product_get_stock_quantity", [$this, "custom_get_stock_quantity"], 100, 2 );
        add_filter("woocommerce_product_variation_get_stock_quantity", [$this, "custom_get_stock_quantity"], 100, 2 );

        // Reduce inventory when wholesale order is created
        add_action('woocommerce_checkout_order_processed', [$this, 'reduce_wholesale_order_inventory'], 10, 3);
        
        // Prevent WooCommerce from reducing stock automatically on status changes for wholesale orders
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'prevent_duplicate_stock_reduction'], 10, 2);
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
    public function is_admin() {
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles);
    }
    public function is_wholesaler() {
        $user = wp_get_current_user();
        return in_array('wholesaler', $user->roles);
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
    public function custom_get_stock_quantity( $value, $product ) {
        if (self::is_wholesaler() && $product->get_meta("_wholesale_stock")) {
            return $product->get_meta("_wholesale_stock");
        }
        return $value;
    }
}
new WC_Wholeseller_Stock();