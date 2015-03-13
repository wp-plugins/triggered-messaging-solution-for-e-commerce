<?php
	require_once dirname(__FILE__) . '/core/Core.php';

	class TriggMine_Integrator_Wordpress_Ecommerce extends TriggMine_Core
	{
		const VERSION = '1.0.1';

		private $_scriptFiles = array();
		private $_scripts = array();

		protected $_logInFile = false;

		public function __construct($outputJavaScript = true)
		{
			parent::__construct();

			if ($outputJavaScript) {
				add_action('wp_print_footer_scripts', array($this, 'outputJavaScript'));
			}
		}

		public function isAjaxRequest()
		{
			return defined('DOING_AJAX') && DOING_AJAX;
		}

		public function supportsJavaScript()
		{
			return true;
		}

		public function registerJavaScript($script)
		{
			$this->_scripts[] = $script;
		}

		public function registerJavaScriptFile($url, $isPluginFile = true)
		{
			$this->_scriptFiles[] = $isPluginFile ? plugins_url($url, __FILE__) : $url;
		}

		public function outputJavaScript()
		{
			foreach ($this->_scriptFiles as $scriptFile) {
				echo "<script type='text/javascript' src='$scriptFile'></script>" . PHP_EOL;
			}

			foreach ($this->_scripts as $script) {
				echo "<script type='text/javascript'>/* <![CDATA[ */ $script /* ]]> */</script>" . PHP_EOL;
			}
		}

		public function getSiteUrl()
		{
			return site_url();
		}

		public function getBuyerInfo()
		{
			global $current_user;

			return array(
				'BuyerEmail' => $current_user->user_email,
				'FirstName'  => $current_user->first_name,
				'LastName'   => $current_user->last_name
			);
		}

		public function getCartUrl()
		{
			return get_option('shopping_cart_url');
		}

		public function getAgent()
		{
			return 'Wordpress';
		}

		public function getAgentVersion()
		{
			return get_bloginfo('version', 'raw');
		}

		public function exportOrders($data)
		{
			global $table_prefix, $wpdb;
			$span = $data->Span;
			$span = explode('-', $span);
			$spanMin = $span[0];
			$spanMax = $span[1];
			$offset = (int)$data->Offset;
			$next = (int)$data->Next;
			$nextQ = $offset + $next - 1;

			$mainOutput = array();

			// Use the DB method if it's around
			if (!empty($wpdb->prefix)) {
				$wp_table_prefix = $wpdb->prefix;
			} // Fallback on the wp_config.php global
			else if (!empty($table_prefix)) {
				$wp_table_prefix = $table_prefix;
			}

			$tablePurchaseLogs = "{$wp_table_prefix}wpsc_purchase_logs";
			$tableCheckoutForms = "{$wp_table_prefix}wpsc_checkout_forms";
			$tableCartContents = "{$wp_table_prefix}wpsc_cart_contents";
			$tableSubmitedFormData = "{$wp_table_prefix}wpsc_submited_form_data";

			$sql = apply_filters('wpsc_purchase_log_month_year_csv', "SELECT t.id, t.totalprice, t.processed, t.date FROM " . $tablePurchaseLogs . " t WHERE t.id BETWEEN " . $offset . " AND " . $nextQ . " AND t.id >= " . $spanMin . " AND t.id <= " . $spanMax . " ORDER BY t.id ASC");
			$data = $wpdb->get_results($sql, ARRAY_A);

			foreach ((array)$data as $purchase) {
				$localOutput = array();

				$localOutput['CartId'] = $purchase['id']; //Purchase ID
				$localOutput['Amount'] = (int)$purchase['totalprice']; //Purchase Total
				$localOutput['DateTime'] = gmdate("Y-m-d H:i:s\Z", $purchase['date']);
				$localOutput['State'] = ($purchase['processed'] == 5) ? 2 : 1;

				$form_sql = "SELECT * FROM `" . $tableCheckoutForms . "` WHERE `active` = '1' AND `type` != 'heading' ORDER BY `checkout_order` DESC;";
				$form_data = $wpdb->get_results($form_sql, ARRAY_A);
				foreach ((array)$form_data as $form_field) {
					if ($form_field['unique_name'] == 'billingemail') {

						$collected_data_sql = "SELECT * FROM `" . $tableSubmitedFormData . "` WHERE `log_id` = '" . $purchase['id'] . "' AND `form_id` = '" . $form_field['id'] . "' LIMIT 1";
						$collected_data = $wpdb->get_results($collected_data_sql, ARRAY_A);
						$collected_data = $collected_data[0];
						$localOutput['Email'] = $collected_data['value'];
					}
				}


				$cartsql = "SELECT * FROM " . $tableCartContents . " t WHERE t.purchaseid=" . $purchase['id'] . "";
				$cart = $wpdb->get_results($cartsql, ARRAY_A);
				foreach ((array)$cart as $item) {
					$catalogItem = get_post($item['prodid'], 'ARRAY_A');

					$data = array(
						'CartItemId'  => (string)$catalogItem['ID'],
						'Title'       => $catalogItem['post_title'],
						'Description' => $catalogItem['post_content'],
						'Price'       => (int)$item['price'],
						'Count'       => (int)$item['quantity'],
					);

					$att_img_args = array(
						'post_type'   => 'attachment',
						'numberposts' => 1,
						'post_parent' => $catalogItem['ID'],
						'orderby'     => 'menu_order',
						'order'       => 'DESC'
					);

					$attached_image = get_posts($att_img_args);
					$attached_image = array_shift($attached_image);
					if ($attached_image != null) {
						$data['ImageUrl'] = $attached_image->guid;
					}

					$localOutput['Content'][] = $data;
				}
				$mainOutput[] = $localOutput;

			}

			return $mainOutput;
		}

		public function install()
		{
			add_option(self::SETTING_IS_ON, 0);
			add_option(self::SETTING_REST_API, 'http://api.triggmine.com/');
			add_option(self::SETTING_TOKEN, '');
		}

		public function uninstall()
		{
			delete_option(self::SETTING_IS_ON);
			delete_option(self::SETTING_REST_API);
			delete_option(self::SETTING_TOKEN);
		}

		protected function _isUserAdmin()
		{
			return is_user_admin();
		}

		protected function _getUserDataFromDatabase($email)
		{
			$user = false;

			if (function_exists('get_user_by'))
				$user = get_user_by('email', $email);
			else {
				$userData = WP_User::get_data_by('email', $email);

				if ($userData) {
					$user = new WP_User;
					$user->init($userData);
				}
			}

			if ($user) {
				$data = array(
					'BuyerRegEnd' => gmdate("Y-m-d H:i:s", strtotime($user->data->user_registered))
				);

				return $data;
			}

			$data = array(
				'BuyerRegStart' => gmdate("Y-m-d H:i:s", time())
			);

			return $data;


		}

		protected function _fillShoppingCart($cartContent)
		{
			global $wpsc_cart;

			// Removing existing items
			$wpsc_cart->empty_cart();

			if (empty($cartContent['Items'])) {
				return false;
			}

			$cartItems = $cartContent['Items'];

			foreach ($cartItems as $cartItem) {
				$params = array();

				$ids = explode('|', $cartItem['CartItemId']);
				$cartItemId = array_shift($ids);

				foreach ($ids as $id) {
					$variationIds = explode('_', $id);
					$params['variation_values'][ $variationIds[0] ] = $variationIds[1];
				}

				$params['quantity'] = $cartItem['Count'];
				$wpsc_cart->set_item($cartItemId, $params);
			}

			$wpsc_cart->wpsc_refresh_cart_items();
			wp_redirect(get_option('shopping_cart_url'));
		}

		protected function _getSettingValue($key)
		{
			$options = (array)get_option('triggmine');
			$key = str_replace('triggmine_', '', $key);

			return isset($options[ $key ]) ? $options[ $key ] : '';
		}

		protected function _onSendExport($input)
		{
			$data = $this->_prepareExportData($input);
			$this->sendExport($data);
		}

		protected function _prepareExportData($input)
		{
			global $wpdb;

			if (!empty($wpdb->prefix)) {
				$wp_table_prefix = $wpdb->prefix;
			}

			$tablePurchaseLogs = "{$wp_table_prefix}wpsc_purchase_logs";

			$days = $input['time_export_option'];
			if ($days != 'all') {
				$startTimestamp = time() - ($days * 24 * 60 * 60);
				$endTimestamp = time();
				$start_end_sql = "SELECT id FROM `" . $tablePurchaseLogs . "` WHERE `date` BETWEEN '%d' AND '%d' ORDER BY `id` ASC";
				$start_end_sql = apply_filters('wpsc_purchase_log_start_end_csv', $start_end_sql);
				$data = $wpdb->get_results($wpdb->prepare($start_end_sql, $startTimestamp, $endTimestamp), ARRAY_A);
				$from = array_shift($data);
				$sameTo = array_pop($data);
			} else {
				$sql = apply_filters('wpsc_purchase_log_month_year_csv', "SELECT * FROM " . $tablePurchaseLogs . " ORDER BY `id` ASC");
				$data = $wpdb->get_results($sql, ARRAY_A);
				$from = array_shift($data);
				$sameTo = array_pop($data);
			}


			if (!$from && !$sameTo['id']){
				return false;
			}

			$data = array(
				'Url'  => $this->getSiteUrl() . '/?' . self::KEY_TRIGGMINE_EXPORT,
				'Span' => '' . $from['id'] . '-' . $sameTo['id'] . ''
			);

			return $data;
		}

		/**
		 * Handler for 'item added to the cart' event.
		 *
		 * @param wpsc_cart_item $item
		 */
		protected function _onCartItemAdded($item)
		{
			$data = $this->_prepareCartItemData($item);
			$this->addCartItem($data);
		}

		protected function _onCartItemAdded2( $product_id, $parameters, $cart )
		{
			$data = $this->_prepareCartItemData2($product_id, $parameters, $cart);
			if ( $data )
				$this->addCartItem($data);
		}

		protected function _prepareCartItemData2($product_id, $parameters, $cart) {
			$item = false;
			$data = false;

			foreach ( $cart->cart_items as $key => $cart_item ) {
				if ( $cart_item->product_id == $product_id ) {
					$item = $cart_item;
				}
			}

			if ( $item ) {
				$catalogItem = get_post($product_id, 'ARRAY_A');

				$data = array(
					'CartItemId'       => $this->_buildCartItemId($item, $catalogItem),
					'Title'            => $catalogItem['post_title'],
					'ShortDescription' => $catalogItem['post_excerpt'],
					'Description'      => $catalogItem['post_content'],
					'Price'            => $item->unit_price,
					'Count'            => $item->quantity
				);

				if (!empty($item->thumbnail_image->guid)) {
					$data['ImageUrl'] = $item->thumbnail_image->guid;
				}
			}

			return $data;
		}

		protected function _prepareCartItemData(wpsc_cart_item $item)
		{
			$catalogItem = get_post($item->product_id, 'ARRAY_A');

			$data = array(
				'CartItemId'       => $this->_buildCartItemId($item, $catalogItem),
				'Title'            => $catalogItem['post_title'],
				'ShortDescription' => $catalogItem['post_excerpt'],
				'Description'      => $catalogItem['post_content'],
				'Price'            => $item->unit_price,
				'Count'            => $item->quantity
			);

			if (!empty($item->thumbnail_image->guid)) {
				$data['ImageUrl'] = $item->thumbnail_image->guid;
			}

			return $data;
		}

		/**
		 * Builds unique CartItemId.
		 *
		 * @param wpsc_cart_item $item Cart item.
		 * @param array $catalogItem Null by default only to meet strict OOP conditions.
		 *
		 * @return string
		 */
		protected function _buildCartItemId($item, $catalogItem = null)
		{
			// проверка вариации
			if ($catalogItem['post_parent'] > 0) {
				if (!empty($item->variation_values)) {
					$itemId = (string)$item->product_id;

					foreach ($item->variation_values as $categoryId => $variationId) {
						$itemId .= '|' . $categoryId . '_' . $variationId;
					}
				}
			} else {
				$itemId = (string)$item->product_id;
			}

			return $itemId;
		}

		/**
		 * Handler for 'item deleted from the cart' event.
		 *
		 * @param wpsc_cart_item $item
		 */
		protected function _onCartItemDeleted($item)
		{
			$catalogItem = get_post($item->product_id, "ARRAY_A");
			$itemId = $this->_buildCartItemId($item, $catalogItem);
			$this->deleteCartItem($itemId);
		}

		protected function _onCartItemDeleted2($key, $class, $cart_item)
		{
			$catalogItem = get_post($cart_item->product_id, "ARRAY_A");
			$itemId = $this->_buildCartItemId($cart_item, $catalogItem);
			$this->deleteCartItem($itemId);
		}

		/**
		 * Handler for 'item updated in the cart' event.
		 *
		 * @param wpsc_cart_item $item
		 */
		protected function _onCartItemUpdated($item)
		{
			$data = $this->_prepareCartItemData($item);
			$this->updateCartItem($data);
		}

		protected function _onCartItemUpdated2($product_id, $parameters, $cart)
		{
			$data = $this->_prepareCartItemData2($product_id, $parameters, $cart);
			if ( $data ) {
				$data['ReplaceOnly'] = 1;
				$this->updateCartItem($data);
			}
		}

		protected function _onCartItemRefreshed(wpsc_cart_item $itemRefreshed)
		{
			$items = $itemRefreshed->cart->cart_items;
			$updateItem = false;
			$deleteItem = false;

			foreach ($items as $item) {
				if ($item->product_id == $itemRefreshed->product_id) {
					// Found changed items among the ones added to cart
					if ($item->quantity == 0) {
						$deleteItem = true;
					} else {
						$updateItem = true;
					}

					break;
				}
			}

			$addItem = !($updateItem || $deleteItem);

			if ($addItem) {
				$this->onCartItemAdded($itemRefreshed);
			}

			if ($updateItem) {
				$this->onCartItemUpdated($itemRefreshed);
			}

			if ($deleteItem) {
				$this->onCartItemDeleted($itemRefreshed);
			}
		}

		protected function _onCartCleanedUp($data = null)
		{
			$this->cleanupCart();
		}

		/**
		 * Handler for 'buyer logged in' event.
		 *
		 * @param string $login
		 * @param WP_User $user Null by default only to meet strict OOP conditions.
		 */
		protected function _onBuyerLoggedIn($login, $user = null)
		{
			$this->logInBuyer(array(
				'BuyerEmail' => $user->user_email,
				'FirstName'  => $user->first_name,
				'LastName'   => $user->last_name
			));
		}

		protected function _onCartMerge($id_from_wp_user)
		{
			$new_cart               = wpsc_get_customer_cart( $id_from_wp_user );
			$newItems               = $new_cart->get_items();
			$products = array();

			foreach ($newItems as $item) {

				$catalogItem = get_post($item->product_id, 'ARRAY_A');

				$data = array(
					'CartItemId'       => $this->_buildCartItemId($item, $catalogItem),
					'Title'            => $catalogItem['post_title'],
					'ShortDescription' => $catalogItem['post_excerpt'],
					'Description'      => $catalogItem['post_content'],
					'Price'            => $item->unit_price,
					'Count'            => $item->quantity
				);

				if (!empty($item->thumbnail_image->guid)) {
					$data['ImageUrl'] = $item->thumbnail_image->guid;
				}

				$products['Items'][] = $data;
			}
			if (!empty($products))
				$this->updateCartFull($products);
		}

		protected function _onBuyerLoggedOut()
		{
			$this->logOutBuyer();
		}

		protected function _onCartPurchased($log)
		{
			$formData = new WPSC_Checkout_Form_Data($log['purchase_log_id']);

			$buyerInfo = array(
				'BuyerEmail' => $formData->get('billingemail'),
				'FirstName'  => $formData->get('billingfirstname'),
				'LastName'   => $formData->get('billinglastname'),
			);

			$this->purchaseCart($buyerInfo);

			global $wpsc_cart;

			// Removing existing items
			$wpsc_cart->empty_cart();
		}


	}