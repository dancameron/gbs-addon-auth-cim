<?php

class Group_Buying_AuthnetCIM extends Group_Buying_Credit_Card_Processors {

	const PAYMENT_METHOD = 'Credit (Authorize.net CIM)';

	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';

	const API_MODE_OPTION = 'gb_auth_cim_mode';
	const API_USERNAME_OPTION = 'gb_auth_cim_username';
	const API_PASSWORD_OPTION = 'gb_auth_cim_password';
	const PROCESS_PAYMENT_AUTHOIZATION_OPTION = 'cim_process_payment_authorization';
	const SINGLE_DEAL_PURCHASING = 'cim_single_deal_purchasing_authorization';

	const USER_META_PROFILE_ID = 'gb_authnet_cim_profile_id';

	const AJAX_ACTION = 'cim_card_mngt';
	const USER_CIM_CARD_OPTION = 'cim_card_mngt_hidden';

	protected static $instance;
	protected static $cim_request;

	private $api_mode = self::MODE_TEST;
	private $api_username = '';
	private $api_password = '';
	private $initial_authorization = 1;
	private $single_deal = 0;


	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Authorize.net CIM' ) );
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

	public static function accepted_cards() {
		$accepted_cards = array(
			'visa',
			'mastercard',
			'amex',
			// 'diners',
			// 'discover',
			// 'jcb',
			// 'maestro'
		);
		return apply_filters( 'gb_accepted_credit_cards', $accepted_cards );
	}

	protected function __construct() {
		parent::__construct();

		// Init lib
		self::init_authrequest();

		// Options
		$this->api_username = get_option( self::API_USERNAME_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		$this->initial_authorization = get_option( self::PROCESS_PAYMENT_AUTHOIZATION_OPTION, 1 );
		$this->single_deal = get_option( self::SINGLE_DEAL_PURCHASING, 0 );

		if ( $this->single_deal == 'enabled' ) { // force authorization on single deal purchasing
			$this->initial_authorization = 1;
			// pop the products array
			add_action( 'gb_cart_load_products_get', array( get_class(), 'pop_cart_products' ), 100, 2 );
		}
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'capture_purchase' ), 10, 1 );

		// Capture penging payments
		if ( GBS_DEV ) {
			add_action( 'init', array( $this, 'capture_pending_payments' ) );
		} else {
			add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
		}

		// checkout template updates
		add_filter( 'wp_head', array( $this, 'credit_card_template_js' ) );
		add_filter( 'gb_payment_fields', array( $this, 'filter_payment_fields' ), 100, 3 );
		add_filter( 'gb_payment_review_fields', array( $this, 'payment_review_fields' ), 100, 3 );

		// Don't validate using parent method, instead handle it uniquely
		remove_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ) );
		add_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ), 20, 1 );

		// AJAX callback
		add_action( 'wp_ajax_cim_card_mngt', array( get_class(), 'ajax_cim' ) );

	}

	/**
	 * Load up the library and instantiate into a static object
	 *
	 * @return OBJECT
	 */
	public static function init_authrequest() {
		if ( !( isset( self::$cim_request ) && is_a( self::$cim_request, 'AuthorizeNetCIM' ) ) ) {
			require_once 'lib/AuthorizeNet.php';
			self::$cim_request = new AuthorizeNetCIM;
		}
		return self::$cim_request;

	}

	/**
	 * Limit the cart to single item purchasing.
	 *
	 * @param array   $products
	 * @param Group_Buying_Cart $cart
	 * @return                       product array
	 */
	public static function pop_cart_products( $products, Group_Buying_Cart $cart ) {
		if ( count( $products ) > 1 ) {
			return array( array_pop( $products ) );
		}
		return $products;
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
		if ( !$profile_id ) {
			if ( GBS_DEV ) error_log( "could not create profile id: " . print_r( $profile_id, true ) );
			return FALSE;
		}

		// Save shipping
		$customer_address_id = $this->ship_to_list( $profile_id, $checkout, $purchase );
		if ( !$customer_address_id ) {
			if ( GBS_DEV ) error_log( "could not create shipping profile id: " . print_r( $customer_address_id, true ) );
			return FALSE;
		}

		// Check if CC was submitted
		if (
			// If the customer is submitting a CC from the review page the payment method isn't passed
			( !isset( $_POST['gb_credit_payment_method'] ) && isset( $_POST['gb_credit_cc_cache'] ) ) ||
			// If payment method isset, then confirm it's not CIM
			( isset( $_POST['gb_credit_payment_method'] ) && ( $_POST['gb_credit_payment_method'] == 'cc' || $_POST['gb_credit_payment_method'] == 'credit' ) ) ) {
			$payment_profile_id = NULL;
		}
		$new_profile_created = FALSE;
		// Since no CC was submitted determine what the payment profile id was selected, if any.
		if ( !$payment_profile_id ) {
			// Check if the payment method was passed
			if ( isset( $_POST['gb_credit_payment_method'] ) ) {
				$payment_profile_id = $_POST['gb_credit_payment_method'];
			}
			// If the payment method wasn't passed check the checkout cache for the cim profile id
			elseif ( isset( $checkout->cache['cim_payment_profile'] ) ) {
				$payment_profile_id = $checkout->cache['cim_payment_profile'];
			}
		}
		if ( GBS_DEV ) error_log( "payment_profile_id: " . print_r( $payment_profile_id, true ) );
		// Create a payment profile id.
		if ( !is_numeric( $payment_profile_id ) ) {
			$payment_profile_id = $this->add_payment_profile( $profile_id, $customer_address_id, $checkout, $purchase );
			$new_profile_created = TRUE;
			if ( GBS_DEV ) error_log( "adding payment profile: " . print_r( $payment_profile_id, true ) );
		}

		if ( !$payment_profile_id ) {
			self::set_error_messages( 'Payment Error: 3742' );
			return FALSE;
		}

		$transaction_id = 0; // If not reset than a PriorCapture
		$response_array = array();

		// (default) run an authorization against the card, regardless if being used later.
		if ( $this->initial_authorization ) {
			// Create AUTHORIZATION Transaction
			$transaction_response = $this->create_cart_transaction( $profile_id, $payment_profile_id, $customer_address_id, $checkout, $purchase );
			$transaction_id = $transaction_response->transaction_id;

			if ( GBS_DEV ) error_log( '----------CREATE Response----------' . print_r( $transaction_response, TRUE ) );

			if ( $transaction_response->response_reason_code != 1 ) {
				$this->set_error_messages( $transaction_response->response_reason_text );
				return FALSE;
			}

			// remove the payment profile if store cc is unchecked
			if ( $new_profile_created ) {
				if ( !isset( $_POST['gb_credit_store_cc'] ) && !isset( $checkout->cache['gb_credit_store_cc'] ) ) {
					$this->remove_payment_profile( $payment_profile_id );
					if ( GBS_DEV ) error_log( '----------DELETE PROFILE----------' . print_r( $payment_profile_id, TRUE ) );
				}
			}

			// convert the transaction_response object to an array for the payment record
			$transaction_json = json_encode( $transaction_response );
			$transaction = json_decode( $transaction_json, true );
		}

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
					'transaction_id' => $transaction_id,
					'profile_id' => $profile_id,
					'payment_profile_id' => $payment_profile_id,
					'customer_address_id' => $customer_address_id,
					'api_response' => $transaction,
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

	////////////////////////
	// Profile Managment //
	////////////////////////

	/**
	 * Create the Profile within CIM if one doesn't exist.
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	public function create_profile( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase, $force = FALSE ) {

		$user = get_userdata( $purchase->get_user() );
		$profile_id = self::get_customer_profile_id( $user->ID, TRUE );
		if ( $profile_id ) {
			if ( GBS_DEV ) error_log( "create_profile return saved id: " . print_r( $new_customer_id, true ) );
			return $profile_id;
		}

		// Create new customer profile
		$customerProfile = new AuthorizeNetCustomer;
		$customerProfile->description = gb_get_name( $purchase->get_user() );
		$customerProfile->merchantCustomerId = $user->ID;
		$customerProfile->email = $user->user_email;

		// Request and response
		$response = self::$cim_request->createCustomerProfile( $customerProfile );
		if ( GBS_DEV ) error_log( "create customer profile response: " . print_r( $response, true ) );

		// Error check
		if ( $response->isError() ) {
			$error_message = $response->getMessageText();
			// If the ID already exists tie it to the current user, hopefully the CIM profile is based on more than just email.
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
		else { // New customer profile was created.
			$new_customer_id = $response->getCustomerProfileId();
		}
		// Save profile id with the user for future reference.
		update_user_meta( $user->ID, self::USER_META_PROFILE_ID, $new_customer_id );

		if ( GBS_DEV ) error_log( "create_profile return: " . print_r( $new_customer_id, true ) );

		// Return
		return $new_customer_id;

	}

	public static function destroy_profile( $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		delete_user_meta( $user_id, self::USER_META_PROFILE_ID );
	}

	public static function get_customer_profile_id( $user_id = 0, $validate = FALSE ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$profile_id = get_user_meta( $user_id, self::USER_META_PROFILE_ID, TRUE );

		if ( $validate && $profile_id ) {
			$customer_profile = self::get_customer_profile( $profile_id );
			// If the profile exists than return it's id
			if ( !$customer_profile->isError() || $customer_profile->getMessageCode() != 'E00040' ) {
				return $profile_id;
			}
			// profile validation produced an error, remove it from this user and continue creating a new one.
			$profile_id = 0;
			self::destroy_profile( $user_id );
			if ( GBS_DEV ) error_log( "get customer profile from create_profile resulted in an error: " . print_r( $response, true ) );
		}

		return $profile_id;
	}

	/**
	 * Get the profile id of a user.
	 *
	 * @param int     $profile_id Profile id stored in user meta
	 * @return object
	 */
	public static function get_customer_profile( $profile_id = 0, $user_id = 0 ) {
		if ( !$profile_id ) {
			$profile_id = self::get_customer_profile_id( $user_id );
			if ( !$profile_id ) {
				return FALSE;
			}
		}
		self::init_authrequest();
		$customer_profile = self::$cim_request->getCustomerProfile( $profile_id );
		return $customer_profile;
	}

	///////////////
	// Shipping //
	///////////////

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
		}
		else {
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
		}

		$response = self::$cim_request->createCustomerShippingAddress( $profile_id, $address );
		if ( GBS_DEV ) error_log( "shipping address response: " . print_r( $response, true ) );

		$customer_address_id = $response->getCustomerAddressId();
		// In case there's an error, get the address already in the profile
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

	//////////////////////
	// Payment Profile //
	//////////////////////

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
		// $paymentProfile->billTo->customerAddressId = $customer_address_id;

		// CC info
		$paymentProfile->payment->creditCard->cardNumber = $this->cc_cache['cc_number'];
		$paymentProfile->payment->creditCard->expirationDate = $this->cc_cache['cc_expiration_year'] . '-' . sprintf( "%02s", $this->cc_cache['cc_expiration_month'] );
		$paymentProfile->payment->creditCard->cardCode = $this->cc_cache['cc_cvv'];

		// Create
		$create_profile_response = self::$cim_request->createCustomerPaymentProfile( $profile_id, $paymentProfile, 'testMode' );
		// Get profile id
		$payment_profile_id = $create_profile_response->getPaymentProfileId();

		if ( GBS_DEV ) {
			error_log( "createCustomerPaymentProfile create_profile_response: " . print_r( $create_profile_response, true ) );
			error_log( "payment_profile_id: " . print_r( $payment_profile_id, true ) );
		}

		// Validate
		$validation = $create_profile_response->getValidationResponse();
		if ( $validation->error ) {
			if ( GBS_DEV ) error_log( "validation response: " . print_r( $validation, true ) );
			self::set_error_messages( self::__( 'Credit Card Validation Declined.' ) );
			return FALSE;
		}

		// In case no validation response is given but there's an error.
		if ( !$payment_profile_id && isset( $create_profile_response->xml->messages->message->text ) ) {
			self::set_error_messages( $create_profile_response->xml->messages->message->text );
			return FALSE;
		}

		// Save the profile, even if it may be removed later
		$this->save_payment_profile( $payment_profile_id );

		return $payment_profile_id;
	}

	/**
	 * Build an array of card from the profile
	 *
	 * @param integer $profile_id CIM profile ID
	 * @return array
	 */
	public static function payment_card_profiles( $profile_id = 0 ) {
		// Get profile object
		$customer_profile = self::get_customer_profile( $profile_id );
		if ( !$customer_profile )
			return FALSE;
		// Create an array of payment profile card numbers
		$cards = array();
		if ( isset( $customer_profile->xpath_xml->profile->paymentProfiles ) ) {
			foreach ( $customer_profile->xpath_xml->profile->paymentProfiles as $profile ) {
				$cards[(int)$profile->customerPaymentProfileId] = $profile->payment->creditCard->cardNumber;
			}
		}
		return $cards;
	}

	/**
	 * Does the user have any card profiles
	 *
	 * @param unknown $user_id
	 * @return
	 */
	public static function has_payment_profile( $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		if ( $profile_id = self::get_customer_profile_id( $user_id ) ) {
			return count( self::payment_card_profiles( $profile_id ) );
		}
		return FALSE;
	}

	//////////////////
	// Transaction //
	//////////////////

	public function create_cart_transaction( $profile_id, $payment_profile_id, $customer_address_id, Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
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
		$response = self::$cim_request->createCustomerProfileTransaction( 'AuthOnly', $transaction );
		if ( GBS_DEV ) error_log( "createCustomerProfileTransaction response : " . print_r( $response, true ) );

		// Error check
		if ( $response->xpath_xml->messages->resultCode == "Error" ) {
			self::set_error_messages( $response->xpath_xml->messages->message->text );
			return FALSE;
		}

		$transaction_response = $response->getTransactionResponse();
		// $transaction_id = $transaction_response->transaction_id;

		return $transaction_response;
	}

	//////////////
	// Capture //
	//////////////

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

	public function capture_payment( Group_Buying_Payment $payment ) {

		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			//wp_delete_post( $payment->get_id(), TRUE);
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['profile_id'] ) && isset( $data['payment_profile_id'] ) ) {
				$total = 0;
				$items_to_capture = $this->items_to_capture( $payment );

				if ( $items_to_capture ) {
					$status = ( count( $items_to_capture ) < count( $data['uncaptured_deals'] ) )? 'NotComplete' : 'Complete';

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

					// If a transaction id exists check if PriorAuthCapture should be used
					if ( isset( $data['transaction_id'] ) && $data['transaction_id'] ) {

						// If payment is complete based on prior AuthOnly, w/ or w/o $single_deal option, use the prior_auth
						if ( $status == 'Complete' ) {
							$transaction->transId = $data['transaction_id'];
							$create_transaction_response = self::$cim_request->createCustomerProfileTransaction( 'PriorAuthCapture', $transaction );
						}
						else {
							// Void transaction since it cannot be used
							$void_response = $this->void_transaction( $data );
							if ( GBS_DEV ) error_log( '----------VOID Response----------' . print_r( $void_response, TRUE ) );
						}
					}
					// Otherwise AuthCapture create a new transaction
					if ( !isset( $transaction->transId ) ) {
						$transaction->order->invoiceNumber = $payment->get_id();
						$create_transaction_response = self::$cim_request->createCustomerProfileTransaction( 'AuthCapture', $transaction );
					}

					$transaction_response = $create_transaction_response->getTransactionResponse();
					$transaction_id = $transaction_response->transaction_id;

					if ( GBS_DEV ) {
						error_log( "transaction sent: " . print_r( $transaction, true ) );
						error_log( "capture trans raw response: " . print_r( $create_transaction_response, true ) );
						error_log( "capture trans response: " . print_r( $transaction_response, true ) );
					}

					if ( $transaction_id ) { // A transaction_id will show the transaction was valid
						foreach ( $items_to_capture as $deal_id => $amount ) {
							unset( $data['uncaptured_deals'][$deal_id] );
						}
						if ( !isset( $data['capture_response'] ) ) {
							$data['capture_response'] = array();
						}
						$data['capture_response'][] = $transaction_response;
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

	public function void_transaction( $data = array() ) {
		self::init_authrequest();
		$transaction = new AuthorizeNetTransaction;
		$transaction->transId = $data['transaction_id'];
		$transaction->customerProfileId = $data['profile_id'];
		$transaction->customerPaymentProfileId = $data['payment_profile_id'];
		$transaction->customerShippingAddressId = $data['customer_address_id'];
		$void_response = self::$cim_request->createCustomerProfileTransaction( "Void", $transaction );
		return $void_response;
	}

	///////////////////////////////////////
	// Local payment profile management //
	///////////////////////////////////////

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


	///////////////
	// Checkout //
	///////////////

	public function is_payment_profile_hidden( $profile_id, $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$account_id = Group_Buying_Account::get_account_id_for_user( $user_id );
		$hidden_profiles = get_post_meta( $account_id, self::USER_CIM_CARD_OPTION, TRUE );
		if ( !is_array( $hidden_profiles ) ) {
			return FALSE;
		}
		return in_array( $profile_id, $hidden_profiles );
	}



	public function credit_card_template_js() {
		if ( self::has_payment_profile() ) { ?>
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
						jQuery.post( gb_ajax_url, { action: '<?php echo self::AJAX_ACTION ?>', 'cim_action': 'remove_payment_profile', remove_profile: $payment_profile },
							function( data ) {
								$remove_card.parent().parent().fadeOut();
							}
						);
					});
				});
			</script> <?php 
		}
	}

	public function filter_payment_fields( $fields ) {
		if ( self::has_payment_profile() ) {
			// If multiple payments isn't selected add the credit-card option
			if ( !isset( $fields['payment_method']['type'] ) ) {
				$fields['payment_method']['type'] = 'radios';
				$fields['payment_method']['weight'] = 0;
				$fields['payment_method']['label'] = self::__( 'Payment Method' );
				$fields['payment_method']['required'] = TRUE;
				$fields['payment_method']['options']['credit'] = self::load_view_to_string( 'checkout/credit-card-option', array( 'accepted_cards' => self::accepted_cards() ) );
			}

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

		if ( isset( $_POST['gb_credit_store_cc'] ) ) {
			$checkout->cache['gb_credit_store_cc'] = TRUE;
		}
	}

	///////////////
	// Settings //
	///////////////

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'Authorize.net CIM' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::PROCESS_PAYMENT_AUTHOIZATION_OPTION );
		register_setting( $page, self::SINGLE_DEAL_PURCHASING );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::PROCESS_PAYMENT_AUTHOIZATION_OPTION, self::__( 'Pre-Authorization' ), array( $this, 'display_api_preauth_field' ), $page, $section );
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

	public function display_api_preauth_field() {
		echo '<label><input type="radio" name="'.self::PROCESS_PAYMENT_AUTHOIZATION_OPTION.'" value="1" '.checked( 1, $this->initial_authorization, FALSE ).'/> '.self::__( 'Authorize card before checkout.' ).'</label><p class="description">'.self::__( 'Regardless if the same authorization can be used for a future payment capture, i.e. authorizations for orders with items that have differing tipping points could not be used. Authorizations that cannot be used are now voided (ver. 2.1).' ).'</p>';
		echo '<label><input type="radio" name="'.self::PROCESS_PAYMENT_AUTHOIZATION_OPTION.'" value="0" '.checked( 0, $this->initial_authorization, FALSE ).'/> '.self::__( 'Do not run an authorization before checkout completes.' ).'</label><p class="description">'.self::__( 'Not a recomended setting.' ).'</p>';
		printf( '<p><label for="%s"><input type="checkbox" value="enabled" name="%s" %s /> %s</label></p><p class="description">%s</p>', self::SINGLE_DEAL_PURCHASING, self::SINGLE_DEAL_PURCHASING, checked( 'enabled', $this->single_deal, FALSE ), self::__( 'Disable cart and limit purchasing to a single item' ),  self::__( 'This will allow for the every pre-authorization to be used for  payment capturing when the deal tips (instead of the possibility of an authorization being voided and new auth_capture created).' ) );
	}

	public function display_currency_code_field() {
		echo 'Specified in your Authorize.Net Merchant Interface.';
	}


	//////////
	// API //
	//////////



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

	////////////
	// Misc. //
	////////////

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
}
Group_Buying_AuthnetCIM::register();