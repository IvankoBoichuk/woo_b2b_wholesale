<?php
/**
 * Wholesaler Order Confirmation Email (Plain Text)
 *
 * @var WC_Order $order
 * @var string $email_heading
 * @var WC_Email $email
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "= " . $email_heading . " =\n\n";

echo __('Thank you for your order. Apsara will be contacting you with updates on your order, availability, and shipping rates.', 'woo-wholeseller') . "\n\n";

echo __('If we do not have your current payment information on file, we will contact you by telephone.', 'woo-wholeseller') . "\n\n";

echo __('Typically, we ship through FedEx or USPS. If you have any special delivery instructions, please let us know.', 'woo-wholeseller') . "\n\n";

echo __('Once your order is initiated, Apsara will update you on processing, delivery and tracking of your order.', 'woo-wholeseller') . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'));
