<?php

/**
 * Custom WooCommerce Email for Wholesalers
 * This file should be included only after WooCommerce emails are loaded
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only define the class if WC_Email exists
if (class_exists('WC_Email')) {
    
    class Wholesaler_Custom_Email extends WC_Email {

        public function __construct() {
            $this->id             = 'wholesaler_custom_email';
            $this->title          = __('Wholesaler Order Confirmation', WOO_Wholeseller::TEXTDOMAIN);
            $this->customer_email = true;
            $this->email_group    = 'wholesaler';
            $this->description    = __('This email is sent to wholesaler customers when their order status changes to on-hold.', WOO_Wholeseller::TEXTDOMAIN);

            $this->template_html  = 'emails/wholesaler-order-confirmation.php';
            $this->template_plain = 'emails/plain/wholesaler-order-confirmation.php';
            $this->template_base  = plugin_dir_path(__FILE__) . 'templates/';

            parent::__construct();
        }

        public function get_default_subject() {
            return __('Confirmation of Apsara order', WOO_Wholeseller::TEXTDOMAIN);
        }

        public function get_default_heading() {
            return __('Order Confirmation', WOO_Wholeseller::TEXTDOMAIN);
        }

        public function trigger($order_id) {
            $this->setup_locale();

            if ($order_id) {
                $order = wc_get_order($order_id);
                $this->object = $order;
                $this->recipient = $order->get_billing_email();
            }

            if ($this->is_enabled() && $this->get_recipient()) {
                $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }

            $this->restore_locale();
        }

        public function get_content_html() {
            return wc_get_template_html($this->template_html, array(
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ), '', $this->template_base);
        }

        public function get_content_plain() {
            return wc_get_template_html($this->template_plain, array(
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ), '', $this->template_base);
        }
    }
}