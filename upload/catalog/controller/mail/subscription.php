<?php
class ControllerMailSubscription extends Controller {
    public function index(string &$route, array &$args, &$output): void {
        if (isset($args[0])) {
            $subscription_id = $args[0];
        } else {
            $subscription_id = 0;
        }

        if (isset($args[1]['subscription'])) {
            $subscription = $args[1]['subscription'];
        } else {
            $subscription = [];
        }

        if (isset($args[2])) {
            $comment = $args[2];
        } else {
            $comment = '';
        }

        if (isset($args[3])) {
            $notify = $args[3];
        } else {
            $notify = '';
        }
        /*
        $subscription['order_product_id']
        $subscription['customer_id']
        $subscription['order_id']
        $subscription['subscription_plan_id']
        $subscription['customer_payment_id'],
        $subscription['name']
        $subscription['description']
        $subscription['trial_price']
        $subscription['trial_frequency']
        $subscription['trial_cycle']
        $subscription['trial_duration']
        $subscription['trial_remaining']
        $subscription['trial_status']
        $subscription['price']
        $subscription['frequency']
        $subscription['cycle']
        $subscription['duration']
        $subscription['remaining']
        $subscription['date_next']
        $subscription['status']
        */

        if ($subscription['trial_duration'] && $subscription['trial_remaining']) {
            $date_next = date('Y-m-d', strtotime('+' . $subscription['trial_cycle'] . ' ' . $subscription['trial_frequency']));
        } elseif ($subscription['duration'] && $subscription['remaining']) {
            $date_next = date('Y-m-d', strtotime('+' . $subscription['cycle'] . ' ' . $subscription['frequency']));
        }

        // Subscription
        $this->load->model('account/subscription');

        $filter_data = [
            'filter_subscription_id'        => $subscription_id,
            'filter_date_next'              => $date_next,
            'filter_subscription_status_id' => $this->config->get('config_subscription_active_status_id'),
            'start'                         => 0,
            'limit'                         => 1
        ];

        $subscriptions = $this->model_account_subscription->getSubscriptions($filter_data);

        if ($subscriptions) {
            $this->load->language('mail/subscription');

            foreach ($subscriptions as $value) {
                // Only match the latest order ID of the same customer ID
                // since new subscriptions cannot be re-added with the same
                // order ID; only as a new order ID added by an extension
                if ($value['customer_id'] == $subscription['customer_id'] && $value['order_id'] == $subscription['order_id']) {
                    // Payment Methods
                    $this->load->model('account/payment_method');

                    $payment_info = $this->model_account_payment_method->getPaymentMethod($value['customer_id'], $value['customer_payment_id']);

                    if ($payment_info) {
                        // Subscription
                        $this->load->model('checkout/subscription');

                        $subscription_order_product = $this->model_checkout_subscription->getSubscriptionByOrderProductId($value['order_product_id']);

                        if ($subscription_order_product) {
                            // Orders
                            $this->load->model('account/order');

                            // Order Products
                            $order_product = $this->model_account_order->getOrderProduct($value['order_id'], $value['order_product_id']);

                            if ($order_product && $order_product['order_product_id'] == $subscription['order_product_id']) {
                                $products = $this->cart->getProducts();

                                $description = '';

                                foreach ($products as $product) {
                                    if ($product['product_id'] == $order_product['product_id']) {
                                        $trial_price = $this->currency->format($this->tax->calculate($value['trial_price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
                                        $trial_cycle = $value['trial_cycle'];
                                        $trial_frequency = $this->language->get('text_' . $value['trial_frequency']);
                                        $trial_duration = $value['trial_duration'];

                                        if ($product['subscription']['trial_status']) {
                                            $description .= sprintf($this->language->get('text_subscription_trial'), $trial_price, $trial_cycle, $trial_frequency, $trial_duration);
                                        }

                                        $price = $this->currency->format($this->tax->calculate($value['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
                                        $cycle = $value['cycle'];
                                        $frequency = $this->language->get('text_' . $value['frequency']);
                                        $duration = $value['duration'];

                                        if ($duration) {
                                            $description .= sprintf($this->language->get('text_subscription_duration'), $price, $cycle, $frequency, $duration);
                                        } else {
                                            $description .= sprintf($this->language->get('text_subscription_cancel'), $price, $cycle, $frequency);
                                        }
                                    }
                                }

                                // Both descriptions need to match to maintain the
                                // mutual agreement of the subscription in accordance
                                // with the service providers
                                if ($description && $description == $subscription['description']) {
                                    // Orders
                                    $this->load->model('checkout/order');

                                    $order_info = $this->model_checkout_order->getOrder($value['order_id']);

                                    if ($order_info) {
                                        // Stores
                                        $this->load->model('setting/store');

                                        // Settings
                                        $this->load->model('setting/setting');

                                        $store_info = $this->model_setting_store->getStore($order_info['store_id']);

                                        if ($store_info) {
                                            $store_logo = html_entity_decode($this->model_setting_setting->getSettingValue('config_logo', $store_info['store_id']), ENT_QUOTES, 'UTF-8');
                                            $store_name = html_entity_decode($store_info['name'], ENT_QUOTES, 'UTF-8');

                                            $store_url = $store_info['url'];
                                        } else {
                                            $store_logo = html_entity_decode($this->config->get('config_logo'), ENT_QUOTES, 'UTF-8');
                                            $store_name = html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8');

                                            $store_url = HTTP_SERVER;
                                        }

                                        // Subscription Status
                                        $subscription_status_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "subscription_status` WHERE `subscription_status_id` = '" . (int)$value['subscription_status_id'] . "' AND `language_id` = '" . (int)$order_info['language_id'] . "'");

                                        if ($subscription_status_query->num_rows) {
                                            $data['order_status'] = $subscription_status_query->row['name'];
                                        } else {
                                            $data['order_status'] = '';
                                        }

                                        // Languages
                                        $this->load->model('localisation/language');

                                        $language_info = $this->model_localisation_language->getLanguage($order_info['language_id']);

                                        // We need to compare both language IDs as they both need to match.
                                        if ($language_info) {
                                            $language_code = $language_info['code'];
                                        } else {
                                            $language_code = $this->config->get('config_language');
                                        }

                                        // Load the language for any mails using a different country code and prefixing it, so it does not pollute the main data pool.
                                        $language = new \Language($order_info['language_code']);
                                        $language->load($order_info['language_code']);
                                        $language->load('mail/subscription');

                                        $subject = sprintf($language->get('text_subject'), $store_name, $order_info['order_id']);

                                        // Image files
                                        $this->load->model('tool/image');

                                        if (is_file(DIR_IMAGE . $store_logo)) {
                                            $data['logo'] = $store_url . 'image/' . $store_logo;
                                        } else {
                                            $data['logo'] = '';
                                        }

                                        $data['title'] = sprintf($language->get('text_subject'), $store_name, $order_info['order_id']);

                                        $data['text_greeting'] = sprintf($language->get('text_greeting'), $order_info['store_name']);

                                        $data['store'] = $store_name;
                                        $data['store_url'] = $order_info['store_url'];

                                        $data['customer_id'] = $order_info['customer_id'];
                                        $data['link'] = $order_info['store_url'] . 'index.php?route=account/subscription/info&subscription_id=' . $subscription_id;

                                        $data['order_id'] = $order_info['order_id'];
                                        $data['date_added'] = date($language->get('date_format_short'), strtotime($value['date_added']));
                                        $data['payment_method'] = $order_info['payment_method'];
                                        $data['email'] = $order_info['email'];
                                        $data['telephone'] = $order_info['telephone'];
                                        $data['ip'] = $order_info['ip'];

                                        // Order Totals
                                        $data['totals'] = [];

                                        $order_totals = $this->model_checkout_order->getOrderTotals($subscription['order_id']);

                                        foreach ($order_totals as $order_total) {
                                            $data['totals'][] = [
                                                'title' => $order_total['title'],
                                                'text'  => $this->currency->format($order_total['value'], $order_info['currency_code'], $order_info['currency_value']),
                                            ];
                                        }

                                        // Subscription
                                        if ($comment && $notify) {
                                            $data['comment'] = nl2br($comment);
                                        } else {
                                            $data['comment'] = '';
                                        }

                                        $data['description'] = $value['description'];

                                        // Products
                                        $data['name'] = $order_product['name'];
                                        $data['quantity'] = $order_product['quantity'];
                                        $data['price'] = $this->currency->format($order_product['price'], $order_info['currency_code'], $order_info['currency_value']);
                                        $data['total'] = $this->currency->format($order_product['total'], $order_info['currency_code'], $order_info['currency_value']);

                                        $data['order'] = $this->url->link('account/order/info', 'order_id=' . $value['order_id']);
                                        $data['product'] = $this->url->link('product/product', 'product_id=' . $order_product['product_id']);

                                        // Settings
                                        $from = $this->model_setting_setting->getSettingValue('config_email', $order_info['store_id']);

                                        if (!$from) {
                                            $from = $this->config->get('config_email');
                                        }

                                        if ($this->config->get('payment_' . $payment_info['code'] . '_status')) {
                                            $this->load->model('extension/payment/' . $payment_info['code']);

                                            // Promotion
                                            if (property_exists($this->{'model_extension_payment_' . $payment_info['code']}, 'promotion')) {
                                                $subscription_status_id = $this->{'model_extension_payment_' . $payment_info['code']}->promotion($value['subscription_id']);

                                                if ($store_info) {
                                                    $config_subscription_active_status_id = $this->model_setting_setting->getSettingValue('config_subscription_active_status_id', $store_info['store_id']);
                                                } else {
                                                    $config_subscription_active_status_id = $this->config->get('config_subscription_active_status_id');
                                                }

                                                if ($config_subscription_active_status_id == $subscription_status_id) {
                                                    $subscription_info = $this->model_account_subscription->getSubscription($value['subscription_id']);

                                                    // Validate the latest subscription values with the ones edited
                                                    // by promotional extensions
                                                    if ($subscription_info && $subscription_info['status'] && $subscription_info['customer_id'] == $value['customer_id'] && $subscription_info['order_id'] == $value['order_id'] && $subscription_info['order_product_id'] == $value['order_product_id']) {
                                                        $this->load->model('account/customer');

                                                        $customer_info = $this->model_account_customer->getCustomer($subscription_info['customer_id']);

                                                        $frequencies = [
                                                            'day',
                                                            'week',
                                                            'semi_month',
                                                            'month',
                                                            'year'
                                                        ];

                                                        // We need to validate frequencies in compliance of the admin subscription plans
                                                        // as with the use of the APIs
                                                        if ($customer_info && (int)$subscription_info['cycle'] >= 0 && in_array($subscription_info['frequency'], $frequencies)) {
                                                            // New customer once the trial period has ended
                                                            $customer_remaining = strtotime($customer_info['date_added']);

                                                            if ($subscription_info['frequency'] == 'semi_month') {
                                                                $remaining = strtotime("2 weeks");
                                                            } else {
                                                                $remaining = strtotime($subscription_info['cycle'] . ' ' . $subscription_info['frequency']);
                                                            }

                                                            // Calculates the remaining days between the subscription
                                                            // promotional period and the customer account's date added
                                                            // period
                                                            $remaining = ($remaining - $customer_remaining);
                                                            $remaining = round($remaining / (60 * 60 * 24));

                                                            // The value of 0 also implicits a current or a final period
                                                            // of the promotional features for the customer
                                                            if (!$remaining) {
                                                                $remaining = 0;
                                                            }

                                                            // Promotional features description must be identical
                                                            // until the time period has exceeded
                                                            if ($remaining >= 0 && $value['description'] == $description && $subscription_info['subscription_plan_id'] == $value['subscription_plan_id']) {
                                                                // Products
                                                                $this->load->model('catalog/product');

                                                                $product_subscription_info = $this->model_catalog_product->getSubscription($order_product['product_id'], $subscription_info['subscription_plan_id']);

                                                                if ($product_subscription_info) {
                                                                    // For the next billing cycle
                                                                    $this->model_account_subscription->addTransaction($value['subscription_id'], $value['order_id'], $this->language->get('text_promotion'), $subscription_info['amount'], $subscription_info['type'], $subscription_info['payment_method'], $subscription_info['payment_code']);
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        // Mail
                                        if ($this->config->get('config_mail_engine')) {
                                            $mail = new \Mail($this->config->get('config_mail_engine'));
                                            $mail->parameter = $this->config->get('config_mail_parameter');
                                            $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
                                            $mail->smtp_username = $this->config->get('config_mail_smtp_username');
                                            $mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
                                            $mail->smtp_port = $this->config->get('config_mail_smtp_port');
                                            $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

                                            $mail->setTo($order_info['email']);
                                            $mail->setFrom($from);
                                            $mail->setSender($store_name);
                                            $mail->setSubject($subject);
                                            $mail->setHtml($this->load->view('mail/subscription', $data));
                                            $mail->send();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}