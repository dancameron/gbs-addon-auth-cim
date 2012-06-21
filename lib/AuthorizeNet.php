<?php
/**
 * The AuthorizeNet PHP SDK. Include this file in your project.
 *
 * @package AuthorizeNet
 */

define( "AUTHORIZENET_API_LOGIN_ID", get_option( Group_Buying_AuthnetCIM::API_USERNAME_OPTION, '' ) );
define( "AUTHORIZENET_TRANSACTION_KEY", get_option( Group_Buying_AuthnetCIM::API_PASSWORD_OPTION, '' ) );

if ( !Group_Buying_AuthnetCIM::is_sandbox() ) {
	define( "AUTHORIZENET_SANDBOX", FALSE );
}
	
require dirname(__FILE__) . '/lib/shared/AuthorizeNetRequest.php';
require dirname(__FILE__) . '/lib/shared/AuthorizeNetTypes.php';
require dirname(__FILE__) . '/lib/shared/AuthorizeNetXMLResponse.php';
require dirname(__FILE__) . '/lib/shared/AuthorizeNetResponse.php';

require dirname(__FILE__) . '/lib/AuthorizeNetAIM.php';
require dirname(__FILE__) . '/lib/AuthorizeNetARB.php';
require dirname(__FILE__) . '/lib/AuthorizeNetSIM.php';
require dirname(__FILE__) . '/lib/AuthorizeNetDPM.php';
require dirname(__FILE__) . '/lib/AuthorizeNetTD.php';
require dirname(__FILE__) . '/lib/AuthorizeNetCP.php';


require dirname(__FILE__) . '/lib/AuthorizeNetCIM.php';

if (class_exists("SoapClient")) {
    require dirname(__FILE__) . '/lib/AuthorizeNetSOAP.php';
}
/**
 * Exception class for AuthorizeNet PHP SDK.
 *
 * @package AuthorizeNet
 */
class AuthorizeNetException extends Exception
{
}