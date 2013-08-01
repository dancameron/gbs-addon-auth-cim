<?php

class Group_Buying_AuthnetCIM extends Group_Buying_Credit_Card_Processors {

	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_USERNAME_OPTION = 'gb_auth_cim_username';
	const API_PASSWORD_OPTION = 'gb_auth_cim_password';
	const API_MODE_OPTION = 'gb_auth_cim_mode';
	const USER_META_PROFILE_ID = 'gb_authnet_cim_profile_id';
	const PAYMENT_METHOD = 'Credit (Authorize.net CIM)';
	const AJAX_ACTION = 'cim_card_mngt';
	const USER_CIM_CARD_OPTION = 'cim_card_mngt_hidden';
	protected static $instance;
	protected static $cim_request;
	private $api_mode = self::MODE_TEST;
	private $api_username = '';
	private $api_password = '';


	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_sandbox() {
		if ( get_option( self::API_MODE_OPTION, self::MODE_TEST ) == self::MODE_LIVE ) {
			return FALSE;
		} else {
			return TRUE;
		}
	}

	public function get_test_mode() {
		if ( self::is_sandbox() ) {
			return 'none';
		}
		return 'liveMode';
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();

		self::init_authrequest();

		$this->api_username = get_option( self::API_USERNAME_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'capture_purchase' ), 10, 1 );
		if ( GBS_DEV ) {
			add_action( 'init', array( $this, 'capture_pending_payments' ) );
		} else {
			add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
		}

		add_filter( 'wp_head', array( $this, 'credit_card_template_js' ) );
		add_filter( 'gb_payment_fields', array( $this, 'filter_payment_fields' ), 100, 3 );
		add_filter( 'gb_payment_review_fields', array( $this, 'payment_review_fields' ), 100, 3 );

		remove_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ) );
		add_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ), 20, 1 );

		add_action( 'wp_ajax_cim_card_mngt', array( get_class(), 'ajax_cim' ) );

	}

	public static function init_authrequest() {
		if ( !( isset( self::$cim_request ) && is_a( self::$cim_request, 'AuthorizeNetCIM' ) ) ) {
			require_once 'lib/AuthorizeNet.php';
			self::$cim_request = new AuthorizeNetCIM;
		}
		return self::$cim_request;

	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Authorize.net CIM' ) );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		self::init_authrequest();

		// Create Profile
		$profile_id = $this->create_profile( $checkout, $purchase );
		if ( GBS_DEV ) error_log( "profile_id: " . print_r( $profile_id, true ) );
		if ( !$profile_id ) {
			return FALSE;
		}

		// Save shipping
		$customer_address_id = $this->ship_to_list( $profile_id, $checkout, $purchase );
		if ( GBS_DEV ) error_log( "customer_address: " . print_r( $customer_address_id, true ) );

		if ( GBS_DEV ) error_log( 'post: ' . print_r( $_POST, TRUE ) );
		if ( GBS_DEV ) error_log( 'cache: ' . print_r( $checkout->cache, TRUE ) );

		// Create new payment profile if using a different cc number
		if (
			( !isset( $_POST['gb_credit_payment_method'] ) && isset( $_POST['gb_credit_cc_cache'] ) ) || // If the customer is submitting a CC from the review page the payment method isn't passed
			( isset( $_POST['gb_credit_payment_method'] ) && ( $_POST['gb_credit_payment_method'] == 'cc' || $_POST['gb_credit_payment_method'] == 'credit' ) ) ) // If payment method isset, then confirm it's not CIM
			{
			// Add Payment Profile
			$payment_profile_id = $this->add_payment_profile( $profile_id, $customer_address_id, $checkout, $purchase );
			if ( GBS_DEV ) error_log( "adding payment profile: " . print_r( $payment_profile_id, true ) );
		}
		// Using a CIM payment profile
		else {
			$payment_profile_id = ( isset( $_POST['gb_credit_payment_method'] ) ) ? $_POST['gb_credit_payment_method'] : $checkout->cache['cim_payment_profile'];
			if ( GBS_DEV ) error_log( 'payment profile after payment process ++++++++++++' . print_r( $payment_profile_id, TRUE ) );
			if ( !is_numeric( $payment_profile_id ) ) {
				$payment_profile_id = $this->add_payment_profile( $profile_id, $customer_address_id, $checkout, $purchase );
				if ( GBS_DEV ) error_log( "adding payment profile: " . print_r( $payment_profile_id, true ) );
			}
		}

		if ( !$payment_profile_id ) {
			// self::destroy_profile( $checkout, $purchase );
			self::set_error_messages( 'Payment Error: 3742' );
			return FALSE;
		}

		if ( GBS_DEV ) error_log( "payment_profile_id:" . print_r( $payment_profile_id, true ) );

		/*/
		// Create AUTHORIZATION Transaction
		$response = $this->create_transaction( $profile_id, $payment_profile_id, $customer_address_id, $checkout, $purchase );
		$transaction_id = $response->transaction_id;

		if ( GBS_DEV ) error_log( '----------Response----------' . print_r( $response, TRUE ) );

		if ( $response->response_reason_code != 1 ) {
			$this->set_error_messages( $response->response_reason_text );
			return FALSE;
		}

		// remove the payment profile is store cc is unchecked
		if ( !isset( $_POST['gb_credit_store_cc'] ) || !isset( $checkout->cache['gb_credit_store_cc'] ) ) {
			$this->remove_payment_profile( $payment_profile_id );
			if ( GBS_DEV ) error_log( '----------DELETE PROFILE----------' . print_r( $payment_profile_id, TRUE ) );
		}

		// convert the response object to an array for the payment record
		$response_json  = json_encode( $response );
		$response_array = json_decode( $response_json, true );
		/**/

		// Setup deal info for the payment
		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}

		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ),
				'data' => array(
					//'transaction_id' => $transaction_id,
					'profile_id' => $profile_id,
					'payment_profile_id' => $payment_profile_id,
					'customer_address_id' => $customer_address_id,
					//'api_response' => $response_array,
					'uncaptured_deals' => $deal_info,
					//'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		return $payment;
	}

	public function process_api_payment( Group_Buying_Purchase $purchase, $cc_data, $amount, $cart, $billing_address, $shipping_address, $data ) {

		self::init_authrequest();

		if ( !isset( $data['profile_id'] ) || !isset( $data['customer_address_id'] ) || !isset( $data['payment_profile_id'] ) )
			return;

		// Create Auth & Capture Transaction
		$transaction = new AuthorizeNetTransaction;
		$transaction->amount = gb_get_number_format( $amount );
		// Removed tax and shipping
		$transaction->customerProfileId = $data['profile_id'];
		$transaction->customerPaymentProfileId = $data['payment_profile_id'];
		$transaction->customerShippingAddressId = $data['customer_address_id'];
		$transaction->order->invoiceNumber = (int)$purchase->get_id();

		foreach ( $purchase->get_products() as $item ) {
			$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
			$lineItem              = new AuthorizeNetLineItem;
			$lineItem->itemId      = $item['deal_id'];
			$lineItem->name        = substr( $deal->get_slug(), 0, 31 );
			$lineItem->description = substr( $deal->get_title(), 0, 255 );
			$lineItem->quantity    = $item['quantity'];
			$lineItem->unitPrice   = gb_get_number_format( $item['unit_price'] );
			$lineItem->taxable     = '';
			$transaction->lineItems[] = $lineItem;
		}

		// Authorize
		$response = self::$cim_request->createCustomerProfileTransaction( 'AuthOnly', $transaction );

		if ( $response->xpath_xml->messages->resultCode == "Error" ) {
			return $response;
		}

		// Juggle
		$transaction_response = $response->getTransactionResponse();
		$transaction_id = $transaction_response->transaction_id;

		if ( GBS_DEV ) error_log( '----------Response----------' . print_r( $transaction_response, TRUE ) );

		if ( $transaction_response->response_reason_code != 1 ) {
			return $transaction_response;
		}

		// convert the response object to an array for the payment record
		$response_json  = json_encode( $transaction_response );
		$response_array = json_decode( $response_json, true );

		// Setup deal info for the payment
		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}

		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $amount,
				'data' => array(
					'transaction_id' => $transaction_id,
					'profile_id' => $data['profile_id'],
					'payment_profile_id' => $data['payment_profile_id'],
					'customer_address_id' => $data['customer_address_id'],
					'api_response' => $response_array,
					'uncaptured_deals' => $deal_info,
					//'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		return $payment;
	}


	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( (string) $response, self::MESSAGE_STATUS_ERROR );
		} else {
			if ( GBS_DEV ) error_log( $response );
		}
	}

	/**
	 * Create the Profile within CIM if one doesn't exist.
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	public function create_profile( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase, $force = FALSE ) {
		$user = get_userdata( $purchase->get_user() );
		$profile_id = get_user_meta( $user->ID, self::USER_META_PROFILE_ID, TRUE );

		if ( $profile_id ) {

			// Check if profile exists
			$response = self::$cim_request->getCustomerProfile( $profile_id );
			// Check to make sure the profile doesn't already exists
			if ( !$response->isError() || $response->getMessageCode() != 'E00040' ) {
				return $profile_id;
			} // otherwise continue to create a profile
			if ( GBS_DEV ) error_log( "get customer profile from create_profile resulted in an error: " . print_r( $response, true ) );
		}

		// Create new customer profile
		$customerProfile = new AuthorizeNetCustomer;

		$customerProfile->description = gb_get_name( $purchase->get_user() );

		$customerProfile->merchantCustomerId = $user->ID;

		// $email = ( $force ) ? microtime() . '-' . $user->user_email : $user->user_email ;
		$customerProfile->email = $user->user_email;

		// Request and response
		$response = self::$cim_request->createCustomerProfile( $customerProfile );

		if ( GBS_DEV ) error_log( "create customer profile response: " . print_r( $response, true ) );

		if ( $response->isError() ) {
			$error_message = $response->getMessageText();

			// If the ID already exists lets just tie it to this user, hopefully the CIM profile is based on more than just email.
			if ( strpos( $error_message, 'duplicate record with ID' ) ) {
				preg_match( '~ID\s+(\S+)~', $error_message, $matches );
				$new_customer_id = $matches[1];
				if ( !is_numeric( $new_customer_id ) ) {
					self::set_error_messages( gb__( 'A duplicate profile was found. Please contact the site administrator.' ) );
					return FALSE;
				}
			} else {
				self::set_error_messages( $error_message );
				return FALSE;
			}
		}
		else {
			$new_customer_id = $response->getCustomerProfileId();
		}
		// Save Profile ID
		update_user_meta( $user->ID, self::USER_META_PROFILE_ID, $new_customer_id );
		// Return
		return $new_customer_id;

	}

	public static function destroy_profile( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$user = get_userdata( $purchase->get_user() );
		// TODO send destroy to auth.net

		// delete_user_meta( $user->ID, self::USER_META_PROFILE_ID );
	}

	public static function get_customer_profile_id( $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$profile_id = get_user_meta( $user_id, self::USER_META_PROFILE_ID, TRUE );
		return $profile_id;
	}

	/**
	 * Get the profile id of a user.
	 *
	 * @param int     $profile_id Profile id stored in user meta
	 * @return object
	 */
	public static function get_customer_profile( $profile_id = 0 ) {

		$profile_id = self::get_customer_profile_id();
		if ( !$profile_id ) {
			return FALSE;
		}
		$customer_profile = self::$cim_request->getCustomerProfile( $profile_id );
		//if ( GBS_DEV ) error_log( "------------- Profile ----------: " . print_r( $profile, true ) );
		return $customer_profile;
	}

	/**
	 * Create the Profile within CIM if one doesn't exist.
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	public function add_payment_profile( $profile_id, $customer_address_id, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		// Create new customer profile
		$paymentProfile = new AuthorizeNetPaymentProfile;
		$paymentProfile->customerType = "individual";
		$paymentProfile->billTo->firstName = $checkout->cache['billing']['first_name'];
		$paymentProfile->billTo->lastName = $checkout->cache['billing']['last_name'];
		$paymentProfile->billTo->address = $checkout->cache['billing']['street'];
		$paymentProfile->billTo->city = $checkout->cache['billing']['city'];
		$paymentProfile->billTo->state = $checkout->cache['billing']['zone'];
		$paymentProfile->billTo->zip = $checkout->cache['billing']['postal_code'];
		$paymentProfile->billTo->country = $checkout->cache['billing']['country'];
		$paymentProfile->billTo->phoneNumber = '';
		//$paymentProfile->billTo->customerAddressId = $customer_address_id;
		// CC info
		$paymentProfile->payment->creditCard->cardNumber = $this->cc_cache['cc_number'];
		$paymentProfile->payment->creditCard->expirationDate = $this->cc_cache['cc_expiration_year'] . '-' . sprintf( "%02s", $this->cc_cache['cc_expiration_month'] );
		$paymentProfile->payment->creditCard->cardCode = $this->cc_cache['cc_cvv'];

		if ( GBS_DEV ) error_log( "paymentProfile: " . print_r( $paymentProfile, true ) );

		// Create
		$response = self::$cim_request->createCustomerPaymentProfile( $profile_id, $paymentProfile, self::get_test_mode() );
		if ( GBS_DEV ) error_log( "paymentProfile response: " . print_r( $response, true ) );

		// Get profile id
		$payment_profile_id = $response->getPaymentProfileId();
		if ( GBS_DEV ) error_log( "payment_profile_id: " . print_r( $payment_profile_id, true ) );

		// if saved is check remove it from the meta so it's not hidden.
		if ( $payment_profile_id ) {
			$this->save_payment_profile( $payment_profile_id );
		}
		else {
			self::set_error_messages( self::__( 'Card Profile Exists: Select from Saved Cards.' ) );
			$this->save_payment_profile( FALSE );
			return FALSE;
		}

		// Validate
		$validation = $response->getValidationResponse();
		if ( GBS_DEV ) error_log( "validation response: " . print_r( $validation, true ) );
		if ( $validation->error ) {
			self::set_error_messages( self::__( 'Credit Card Validation Declined.' ) );
			return FALSE;
		}


		// In case there's an error (e.g. duplicate addition), check to see if the payment profile id is in the profile
		if ( !$payment_profile_id ) {
			$payment_profile_id = $this->payment_profile_id( $profile_id );
		}

		// In case no validation response is given but there's an error.
		if ( !$payment_profile_id && isset( $response->xml->messages->message->text ) ) {
			self::set_error_messages( $response->xml->messages->message->text );
			return FALSE;
		}

		return $payment_profile_id;
	}

	public static function payment_card_profiles( $profile_id = 0 ) {

		// Get profile object
		$customer_profile = self::get_customer_profile( $profile_id );
		if ( !$customer_profile ) {
			return FALSE;
		}

		$cards = array();
		if ( isset( $customer_profile->xpath_xml->profile->paymentProfiles ) ) {
			foreach ( $customer_profile->xpath_xml->profile->paymentProfiles as $profile ) {
				$cards[(int)$profile->customerPaymentProfileId] = $profile->payment->creditCard->cardNumber;
			}
		}

		return $cards;
	}

	public static function has_payment_profile( $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		if ( $profile_id = self::get_customer_profile_id( $user_id ) ) {
			return count( self::payment_card_profiles( $profile_id ) );
		}
		return FALSE;
	}

	public static function ship_to_list( $profile_id, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( isset( $checkout->cache['shipping'] ) ) {
			// Add shipping address.
			$address2 = new AuthorizeNetAddress;
			$address2->firstName = $checkout->cache['shipping']['first_name'];
			$address2->lastName = $checkout->cache['shipping']['last_name'];
			$address2->company = '';
			$address2->address = $checkout->cache['shipping']['street'];
			$address2->city = $checkout->cache['shipping']['city'];
			$address2->state = $checkout->cache['shipping']['zone'];
			$address2->zip = $checkout->cache['shipping']['postal_code'];
			$address2->country = $checkout->cache['shipping']['country'];
			$address2->phoneNumber = $checkout->cache['shipping']['phone'];
			$address2->faxNumber = '';

			$response = self::$cim_request->createCustomerShippingAddress( $profile_id, $address2 );
			if ( GBS_DEV ) error_log( "shipping address response: " . print_r( $response, true ) );
			return $response->getCustomerAddressId();
		}

		// Add billing address as shipping.
		$address = new AuthorizeNetAddress;
		$address->firstName = $checkout->cache['billing']['first_name'];
		$address->lastName = $checkout->cache['billing']['last_name'];
		$address->company = '';
		$address->address = $checkout->cache['billing']['street'];
		$address->city = $checkout->cache['billing']['city'];
		$address->state = $checkout->cache['billing']['zone'];
		$address->zip = $checkout->cache['billing']['postal_code'];
		$address->country = $checkout->cache['billing']['country'];
		$address->phoneNumber = $checkout->cache['billing']['phone'];
		$address->faxNumber = '';

		$response = self::$cim_request->createCustomerShippingAddress( $profile_id, $address );
		if ( GBS_DEV ) error_log( "shipping address response: " . print_r( $response, true ) );
		$customer_address_id = $response->getCustomerAddressId();

		// In case there's an error, check to see if the address is in the profile
		if ( !$customer_address_id ) {
			// Get profile object
			$customer_profile = self::get_customer_profile( $profile_id );
			if ( !$customer_profile ) {
				return FALSE;
			}

			// Get address id
			$customer_address_id = $customer_profile->getCustomerAddressId();
		}

		return $customer_address_id;
	}

	public function create_transaction( $profile_id, $payment_profile_id, $customer_address_id, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		self::init_authrequest();
		// Vars
		$cart = $checkout->get_cart();
		$user = get_userdata( $purchase->get_user() );
		$local_billing = $this->get_checkout_local( $checkout, $purchase, TRUE );

		// Create Auth & Capture Transaction
		$transaction = new AuthorizeNetTransaction;
		$transaction->amount = gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) );
		$tax_total = gb_get_number_format( $purchase->get_tax_total( $this->get_payment_method() ) );
		if ( $tax_total > 0.01 ) {
			$transaction->tax->amount = $tax_total;
		}
		$shipping_total = gb_get_number_format( $purchase->get_shipping_total( $this->get_payment_method() ) );
		if ( $shipping_total > 0.01 ) {
			$transaction->shipping->amount = $shipping_total;
		}
		$transaction->customerProfileId = $profile_id;
		$transaction->customerPaymentProfileId = $payment_profile_id;
		$transaction->customerShippingAddressId = $customer_address_id;
		$transaction->order->invoiceNumber = (int)$purchase->get_id();

		if ( gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ) == ( $cart->get_shipping_total() + $cart->get_tax_total() ) ) {

			$lineItem              = new AuthorizeNetLineItem;
			$lineItem->itemId      = $purchase->get_id();
			$lineItem->name        = gb__( 'Cart Total' );
			$lineItem->description = gb__( 'Shipping and Tax for the cart.' );
			$lineItem->quantity    = '1';
			$lineItem->unitPrice   = gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) );

			$transaction->lineItems[] = $lineItem;
		} else {
			foreach ( $purchase->get_products() as $item ) {
				if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
					if ( GBS_DEV ) error_log( "item: " . print_r( $item, true ) );
					$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
					$tax = $deal->get_tax( $local_billing );
					$taxable = ( !empty( $tax ) && $tax > '0' ) ? 'true' : '' ;

					$lineItem              = new AuthorizeNetLineItem;
					$lineItem->itemId      = $item['deal_id'];
					$lineItem->name        = substr( $deal->get_slug(), 0, 31 );
					$lineItem->description = substr( $deal->get_title(), 0, 255 );
					$lineItem->quantity    = $item['quantity'];
					$lineItem->unitPrice   = gb_get_number_format( $item['unit_price'] );
					$lineItem->taxable     = $taxable;

					$transaction->lineItems[] = $lineItem;
				}
			}
		}
		if ( GBS_DEV ) error_log( "transaction: " . print_r( $transaction , true ) );
		$response = self::$cim_request->createCustomerProfileTransaction( 'AuthOnly', $transaction );
		if ( GBS_DEV ) error_log( "raw response : " . print_r( $response, true ) );
		if ( $response->xpath_xml->messages->resultCode == "Error" ) {
			self::set_error_messages( $response->xpath_xml->messages->message->text );
			return FALSE;
		}

		$transactionResponse = $response->getTransactionResponse();
		$transactionId = $transactionResponse->transaction_id;

		return $transactionResponse;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	public  function capture_payment( Group_Buying_Payment $payment ) {

		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			//wp_delete_post( $payment->get_id(), TRUE);
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['profile_id'] ) && isset( $data['payment_profile_id'] ) ) {
				$total = 0;
				$items_to_capture = $this->items_to_capture( $payment );

				if ( $items_to_capture ) {
					$status = ( count( $items_to_capture ) < count( $data['uncaptured_deals'] ) )?'NotComplete':'Complete';

					// Total to capture
					foreach ( $items_to_capture as $price ) {
						$total += $price;
					}

					// Create Auth & Capture Transaction
					$transaction = new AuthorizeNetTransaction;
					$transaction->amount = gb_get_number_format( $total );
					$transaction->customerProfileId = $data['profile_id'];
					$transaction->customerPaymentProfileId = $data['payment_profile_id'];
					$transaction->customerShippingAddressId = $data['customer_address_id'];
					$transaction->order->invoiceNumber = $payment->get_id();
					$transaction_response = self::$cim_request->createCustomerProfileTransaction( 'AuthCapture', $transaction );

					// Auth.net will not allow for multiple captures on a single auth
					// $transaction->transId = $data['transaction_id'];
					// $transaction_response = self::$cim_request->createCustomerProfileTransaction( 'PriorAuthCapture', $transaction );

					if ( GBS_DEV ) error_log( "transaction sent: " . print_r( $transaction, true ) );
					if ( GBS_DEV ) error_log( "capture trans raw response: " . print_r( $transaction_response, true ) );
					$response = $transaction_response->getTransactionResponse();
					if ( GBS_DEV ) error_log( "capture trans response: " . print_r( $response, true ) );
					$transaction_id = $response->transaction_id;

					if ( $transaction_id ) { // Check to make sure the response was valid

						foreach ( $items_to_capture as $deal_id => $amount ) {
							unset( $data['uncaptured_deals'][$deal_id] );
						}
						if ( !isset( $data['capture_response'] ) ) {
							$data['capture_response'] = array();
						}
						$data['capture_response'][] = $response;
						$payment->set_data( $data );
						do_action( 'payment_captured', $payment, array_keys( $items_to_capture ) );
						if ( $status == 'Complete' ) {
							$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
							do_action( 'payment_complete', $payment );
						} else {
							$payment->set_status( Group_Buying_Payment::STATUS_PARTIAL );
						}
					}
				}
			}
		}
	}

	public function ajax_cim() {
		switch ( $_REQUEST['cim_action'] ) {
		case 'remove_payment_profile':
			self::remove_payment_profile( $_REQUEST['remove_profile'] );
			exit();
			break;
		default:
			break;
		}
	}

	public function remove_payment_profile( $profile_id, $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$account_id = Group_Buying_Account::get_account_id_for_user( $user_id );
		$hidden_profiles = get_post_meta( $account_id, self::USER_CIM_CARD_OPTION, TRUE );
		if ( !is_array( $hidden_profiles ) ) {
			$hidden_profiles = array();
		}
		$hidden_profiles[] = $profile_id;
		update_post_meta( $account_id, self::USER_CIM_CARD_OPTION, $hidden_profiles );

		// modify via CIM
		$customer_profile = self::get_customer_profile_id( $user_id );
		$response = self::$cim_request->deleteCustomerPaymentProfile( $customer_profile, $profile_id );
	}

	public function save_payment_profile( $profile_id, $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$account_id = Group_Buying_Account::get_account_id_for_user( $user_id );
		if ( !$profile_id ) {
			update_post_meta( $account_id, self::USER_CIM_CARD_OPTION, array() );
			return;
		}
		$hidden_profiles = get_post_meta( $account_id, self::USER_CIM_CARD_OPTION, TRUE );
		if ( !is_array( $hidden_profiles ) ) {
			return;
		}
		// search for position
		$pos = array_search( $profile_id, $hidden_profiles );
		// remove
		unset( $hidden_profiles[$pos] );
		// save
		update_post_meta( $account_id, self::USER_CIM_CARD_OPTION, $hidden_profiles );
	}

	public function is_payment_profile_hidden( $profile_id, $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$account_id = Group_Buying_Account::get_account_id_for_user( $user_id );
		$hidden_profiles = get_post_meta( $account_id, self::USER_CIM_CARD_OPTION, TRUE );
		return in_array( $profile_id, $hidden_profiles );
	}



	public function credit_card_template_js() {
		if ( self::has_payment_profile() ) {
			?>
			<style type="text/css">.cim_delete_card img { opacity: .3; } .cim_delete_card:hover img { opacity: 1.0; }</style>
			<script type="text/javascript" charset="utf-8">
				jQuery(document).ready(function() {
					jQuery('[name="gb_credit_payment_method"]').on( 'click', function(event){
						var selected = jQuery(this).val();   // get value of checked radio button
						if (selected != 'credit') {
							jQuery('.gb_credit_card_field_wrap').fadeOut();
							jQuery("[for$='gb_credit_store_cc']").fadeOut();
						} else {
							jQuery('.gb_credit_card_field_wrap').fadeIn();
							jQuery("[for$='gb_credit_store_cc']").fadeIn();
						}
					});
					jQuery('.cim_delete_card').on( 'click', function(event){
						event.preventDefault();
						var $remove_card = jQuery( this );
						var $payment_profile = $remove_card.attr( 'ref' );
						jQuery.post( ajaxurl, { action: '<?php echo self::AJAX_ACTION ?>', 'cim_action': 'remove_payment_profile', remove_profile: $payment_profile },
							function( data ) {
								$remove_card.parent().parent().fadeOut();
							}
						);
					});
				});
			</script>
			<?php
		}
	}

	public function filter_payment_fields( $fields ) {
		if ( self::has_payment_profile() ) {
			// Add CC options to the checkout fields
			$cards = self::payment_card_profiles( $profile_id );
			foreach ( $cards as $payment_profile_id => $card_number ) {
				if ( !self::is_payment_profile_hidden( $payment_profile_id ) ) {
					$fields['payment_method']['options'][$payment_profile_id] = self::__( 'Credit Card: ' ) . $card_number . '&nbsp;<a href="javascript:void(0)" ref="'.$payment_profile_id.'" class="cim_delete_card" title="'.gb__( 'Remove this CC from your account.' ).'"><img src="http://f.cl.ly/items/041u1f1W06451c0V361W/1372818887_delete.png"/></a>';
				}
			}
		}
		$fields['store_cc'] = array(
			'type' => 'checkbox',
			'weight' => 10,
			'label' => self::__( 'Save Credit Card' ),
			'default' => TRUE
		);
		return $fields;
	}

	public function payment_review_fields( $fields, $processor, Group_Buying_Checkouts $checkout ) {
		if ( isset( $_POST['gb_credit_payment_method'] ) && $_POST['gb_credit_payment_method'] != 'cc' ) {
			$fields['cim'] = array(
				'label' => self::__( 'Primary Method' ),
				'value' => self::__( 'Credit Card' ),
				'weight' => 10,
			);
			unset( $fields['cc_name'] );
			unset( $fields['cc_number'] );
			unset( $fields['cc_expiration'] );
			unset( $fields['cc_cvv'] );
		}
		return $fields;
	}

	/**
	 * Validate the submitted credit card info
	 * Store the submitted credit card info in memory for processing the payment later
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @return void
	 */
	public function process_payment_page( Group_Buying_Checkouts $checkout ) {
		// Don't try to validate a CIM payment
		if ( !isset( $_POST['gb_credit_payment_method'] ) || ( isset( $_POST['gb_credit_payment_method'] ) && ( $_POST['gb_credit_payment_method'] == 'cc' || $_POST['gb_credit_payment_method'] == 'credit' ) ) ) {
			$fields = $this->payment_fields( $checkout );
			foreach ( array_keys( $fields ) as $key ) {
				if ( $key == 'cc_number' ) { // catch the cc_number so it can be sanatized
					if ( isset( $_POST['gb_credit_cc_number'] ) && strlen( $_POST['gb_credit_cc_number'] ) > 0 ) {
						$this->cc_cache['cc_number'] = preg_replace( '/\D+/', '', $_POST['gb_credit_cc_number'] );
					}
				}
				elseif ( isset( $_POST['gb_credit_'.$key] ) && strlen( $_POST['gb_credit_'.$key] ) > 0 ) {
					$this->cc_cache[$key] = $_POST['gb_credit_'.$key];
				}
			}
			$this->validate_credit_card( $this->cc_cache, $checkout );
		}
		elseif ( isset( $_POST['gb_credit_payment_method'] ) && is_numeric( $_POST['gb_credit_payment_method'] ) ) {
			$checkout->cache['cim_payment_profile'] = $_POST['gb_credit_payment_method'];
		}
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'Authorize.net CIM' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'API Login (Username)' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'Transaction Key (Password)' ), array( $this, 'display_api_password_field' ), $page, $section );
		//add_settings_field(null, self::__('Currency'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->api_username.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->api_password.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, $this->api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, $this->api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_currency_code_field() {
		echo 'Specified in your Authorize.Net Merchant Interface.';
	}


}
Group_Buying_AuthnetCIM::register();
