# CakeLDAP
## Reference Knowledge

http://book.cakephp.org/3.0/en/controllers/components/authentication.html#creating-custom-authorize-objects

## Configuration

This is a LDAP 3 authentication adapter for CakePHP 3.0, using a service account to do @ldap_bind() and then search for login user name and his dn on LDAP server, if there is a match, then we will use this dn and password to bind again and validate the user.

Put LdapAuthenticate.php into cakephp_project_root\src\Auth folder, and then setup the following settings.

### AppController Setup

```php
    public function initialize()
    {
        parent::initialize();
        $this->loadComponent('RequestHandler');
        $this->loadComponent('Flash');
        $this->loadComponent('Auth', [
            'loginAction' => [
                'controller' => 'Users',
                'action' => 'login'
            ],
            'flash' => [
    			'element' => 'Flash/error',
    		],
            'authError' => 'Please login to continue any further operations.',
            'authenticate' => [
                'Ldap' => [
					'fields' => [
						'username' => 'username',
						'password' => 'password'
					],

            	]
        	]
        ]);
    }
```

### App config

config/app.php:
```php
    /**
     * LDAP Configuration.
     *
     * Contains an array of settings to use for the LDAP configuration.
     *
     * ## Options
     *
     * - `host` - The domain controller hostname. 
     * - `port` - The port to use. Default is 636 and is not required.
     * - `bindAccount` - The service account to bind ldap server.
     * - `bindPassword` - The password to bind ldap server.
     * - `baseDN` - The base DN for directory
     * - `filter` - The attribute to search against. 
     * - `return` - The attributes to return'
     * - `errors` - Array of errors where key is the error and the value is the error
     *    message. Set in session to Flash.ldap for flashing
     *
     * @link http://php.net/manual/en/function.ldap-search.php - for more info on ldap search
     */
	'Ldap' => [
			'host' => 'SOMESERVER.SOMEROOT.NET',
			'port' => 636, /** ldaps:// port */
			'version' => 3,
			'baseDN' => 'DC=SOMETHING,DC=YOUR_ROOT,DC=YOUR_NET',
			'bindAccount' => 'CN=YOUR_LOGIN,OU=YOUR_ORG,DC=SOMETHING,DC=YOUR_ROOT,DC=YOUR_NET',
			'bindPassword' => 'PASSWORD',
            'filter'=>function($username) {
				return "(|(sn=*$username*)(givenname=*$username*)(sAMAccountName=*$username*)(displayname=*$username*))";
            },
            'return' => array("ou", "sAMAccountName", "givenname", "mail", "dn"),
			'errors' => [
					'data 773' => '773 error',
					'data 532' => '532 error',
			]
	],
```
