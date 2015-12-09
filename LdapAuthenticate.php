<?php

/**
 *
 * Licensed under The MIT License
 *
 * @link          https://github.com/kalpaca/CakeLDAP
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Auth;

use Cake\Core\Configure;
use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Log\LogTrait;
use Cake\Network\Exception\InternalErrorException;
use Cake\Network\Exception\UnauthorizedException;
use Cake\Network\Request;
use Cake\Network\Response;

/**
 * LDAP Authentication adapter for AuthComponent.
 *
 * Provides LDAP authentication support for AuthComponent. LDAP will
 * authenticate users against the specified LDAP Server
 *
 * ### Using LDAP auth
 *
 * In your controller's components array, add auth + the required config
 * ```
 * public $components = [
 * 'Auth' => [
 * 'authenticate' => ['Ldap']
 * ]
 * ];
 * ```
 */
class LdapAuthenticate extends BaseAuthenticate {
	
	use LogTrait;
	
	/**
	 * LDAP Object
	 *
	 * @var object
	 */
	private $ldapConnection;
	
	/**
	 * Constructor
	 *
	 * @param \Cake\Controller\ComponentRegistry $registry
	 *        	The Component registry used on this request.
	 * @param array $config
	 *        	Array of config to use.
	 */
	public function __construct(ComponentRegistry $registry, array $config = []) {
		parent::__construct ( $registry, $config );
		
		if (! defined ( 'LDAP_OPT_DIAGNOSTIC_MESSAGE' )) {
			define ( 'LDAP_OPT_DIAGNOSTIC_MESSAGE', 0x0032 );
		}
		
		$this->_config ['port'] = Configure::read ( 'Ldap.port' );
		$this->_config ['host'] = Configure::read ( 'Ldap.host' );
		$this->_config ['bindAccount'] = Configure::read ( 'Ldap.bindAccount' );
		$this->_config ['bindPassword'] = Configure::read ( 'Ldap.bindPassword' );
		$this->_config ['baseDN'] = Configure::read ( 'Ldap.baseDN' );
		$this->_config ['filter'] = Configure::read ( 'Ldap.filter' );
		$this->_config ['return'] = Configure::read ( 'Ldap.return' );
		$this->_config ['errors'] = Configure::read ( 'Ldap.errors' );
		
		if (isset ( $this->_config ['host'] ) && is_object ( $this->_config ['host'] ) && ($this->_config ['host'] instanceof \Closure)) {
			$this->_config ['host'] = $config ['host'] ();
		}
		
		if (empty ( $this->_config ['host'] )) {
			throw new InternalErrorException ( 'LDAP Server not specified!' );
		}
		
		if (empty ( $config ['port'] )) {
			$this->_config ['port'] = null;
		}
		
		$this->connect();
	}
	
	/**
	 * Destructor
	 */
	public function __destruct() {
		$this->disconnect();
	}
	/**
	 * disconnect close connection 
	 */
	function disconnect() {
		@ldap_unbind ( $this->ldapConnection );
		@ldap_close ( $this->ldapConnection );
	}
	/**
	 * connect ldap connection
	 */
	function connect() {
		try {
			$this->ldapConnection = @ldap_connect ( $this->_config ['host'] );
			ldap_set_option ( $this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3 );
			ldap_set_option ( $this->ldapConnection, LDAP_OPT_REFERRALS, 0 );
			ldap_set_option ( $this->ldapConnection, LDAP_OPT_NETWORK_TIMEOUT, 5 );
		} catch ( Exception $e ) {
			throw new InternalErrorException ( 'Unable to connect to specified LDAP Server(s)!' );
		}
	}
	/**
	 * Authenticate a user using HTTP auth.
	 * Will use the configured User model and attempt a
	 * login using HTTP auth.
	 *
	 * @param \Cake\Network\Request $request
	 *        	The request to authenticate with.
	 * @param \Cake\Network\Response $response
	 *        	The response to add headers to.
	 * @return mixed Either false on failure, or an array of user data on success.
	 */
	public function authenticate(Request $request, Response $response) {
		return $this->getUser ( $request );
	}
	
	/**
	 * Get a user based on information in the request.
	 * Used by cookie-less auth for stateless clients.
	 *
	 * @param \Cake\Network\Request $request
	 *        	Request object.
	 * @return mixed Either false or an array of user information
	 */
	public function getUser(Request $request) {
		if (! isset ( $request->data ['username'] ) || ! isset ( $request->data ['password'] )) {
			return false;
		}
		
		set_error_handler ( function ($errorNumber, $errorText, $errorFile, $errorLine) {
			throw new \ErrorException ( $errorText, 0, $errorNumber, $errorFile, $errorLine );
		}, E_ALL );
		
		$bindAccount = $this->_config ['bindAccount'];
		$bindPassword = $this->_config ['bindPassword'];
		
		try {
			// bind with service account first
			if (! empty ( $bindAccount )) 
			{
				$ldapBind = ldap_bind ( $this->ldapConnection, $bindAccount, $bindPassword );
				if ($ldapBind === true) {
					$filter = $this->_config ['filter'] ( $request->data ['username'] );
					$searchResults = ldap_search ( $this->ldapConnection, $this->_config ['baseDN'], $filter, $this->_config ['return'] );
					$results = ldap_get_entries ( $this->ldapConnection, $searchResults );
					$entry = ldap_first_entry ( $this->ldapConnection, $searchResults );
					
					// get login user dn
					$dn = ldap_get_dn ( $this->ldapConnection, $entry );					
				}
			} 
			else // if no service account try to use username + basedn 
			{
				$dn = 'CN=' . $request->data ['username'] . ',' . $this->_config ['baseDN'];
			}
			// bind with login id
			$ldapBind = ldap_bind ( $this->ldapConnection, $dn, $request->data ['password'] );
			if ($ldapBind === true)
				return ldap_get_attributes ( $this->ldapConnection, $entry );
			
		} catch ( \ErrorException $e ) {
			$this->log ( $e->getMessage () );
			if (ldap_get_option ( $this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $extendedError )) {
				if (! empty ( $extendedError )) {
					foreach ( $this->_config ['errors'] as $error => $errorMessage ) {
						if (strpos ( $extendedError, $error ) !== false) {
							$messages [] = [ 
									'message' => $errorMessage,
									'key' => $this->_config ['flash'] ['key'],
									'element' => $this->_config ['flash'] ['element'],
									'params' => $this->_config ['flash'] ['params'] 
							];
						}
					}
				}
			}
		}
		restore_error_handler ();
		
		if (! empty ( $messages )) {
			$request->session ()->write ( 'Flash.' . $this->_config ['flash'] ['key'], $messages );
		}
		
		return false;
	}
}
