<?php
namespace Auth\Acl;

use Auth\Entity\Benutzer;

use Auth\Entity\Role;
use Zend\Authentication\AuthenticationService;

use Zend\Authentication\Storage\Session;
use Zend\Permissions\Acl\Acl as ZendAcl;

use Zend\Permissions\Acl\Resource\GenericResource;

use Zend\Permissions\Acl\Role\GenericRole;

class Acl extends AuthenticationService {

	private $acl = null;
	private $sm = null;
	private $em = null;

    CONST LOGIN_SUCCESS = 1;
    CONST LOGIN_WRONG = 2;
    CONST LOGIN_DISABLED = 3;


    /**
     * Liefert die ACL
     *
     * @return null
     */
    public function getAcl(){

		return $this->acl;
	}

    /**
     * Liefert den FlashMessenger
     *
     * @return mixed
     */
    public function getMessenger(){

        return $this->sm->get('ControllerPluginManager')->get('Message');
    }

    /**
     * Registriert die Module aus der DB mit Zend/Auth
     * Setzt die Rechte der Gruppen
     *
     * @param $sm
     */
    public function __construct( $sm ) {



        $authSessionStorage = new Session('AUTH_IDENTITY');
        parent::__construct($authSessionStorage);

		$em = $sm->get('Doctrine\ORM\EntityManager');
		$acl = new ZendAcl();
		
		// add roles
		foreach( $em->getRepository('Auth\Entity\Role')->findBy(array(), array('parentId' => 'ASC')) as $role){
				
			if( $role->parent ) {
				$parentName = $role->parent->name;
			} else {
				$parentName = null;
			}
				
			$acl->addRole( new GenericRole( $role->name ), $parentName );
		}
		
		// add resources + action
		foreach( $em->getRepository('Auth\Entity\Resource')->findBy(array(), array('modul' => 'DESC')) as $resource){

            $ressouceName = $resource->modul;

            if( $resource->action ){
                $ressouceName .= '/' . $resource->action;
            }

            if( $resource->subAction ) {
                $ressouceName .= '/' . $resource->subAction;
            }

			$acl->addResource( new GenericResource(
                $ressouceName
			));
		}

        unset($ressouceName);
		
		// deny all
		$acl->deny( null );
		
		// add permissions
		foreach( $em->getRepository('Auth\Entity\Permission')->findAll() as $permission ){
			// allow
            $permissionName = $permission->resource->modul;

            if( $permission->resource->action ){
                $permissionName .= '/' . $permission->resource->action;
            }

            if( $permission->resource->subAction ) {
                $permissionName .= '/' . $permission->resource->subAction;
            }

			$acl->allow(
					$permission->gruppe->name,
                    $permissionName
			);
		}

		// register identity
		if( ! $this->hasIdentity() ) {

			// register as gast
			$benutzer = new Benutzer();
			$benutzer->username = 'Unbekannter User';
			$benutzer->id = 0;
            $benutzer->loggedIn = false;

            $gruppe = new Role();
            $gruppe->id = 2;
			$gruppe->name = 'Gast';
            $gruppe->supervisor = 0;
			$benutzer->gruppe = $gruppe;			
			
			
			if( !$benutzer ) {
				throw new \Exception('Gastbenutzer mit der ID -1 nicht vorhanden - bitte direkt in der Datenbank anlegen');	
			}

			$this->getStorage()->write( $benutzer );
		}
		
		// register acl in navigation
		\Zend\View\Helper\Navigation\AbstractHelper::setDefaultAcl( $acl );
		\Zend\View\Helper\Navigation\AbstractHelper::setDefaultRole( $this->getIdentity()->gruppe->name );

		$this->acl = $acl;
		$this->sm = $sm;
		$this->em = $em;
		
		return $this;
	}

    /**
     * Validiert einen Benutzer mit der DB
     *
     * @param $username
     * @param $password
     * @return int
     */
    public function login($username, $password) {
	
		$benutzer = $this->em->getRepository('Auth\Entity\Benutzer')->findOneByUsername( $username );

        /**
         * @TODO MD5 durch bcrpyt ersetzten
         */
        if( $benutzer && $benutzer->password == md5( $password ) ) {

			if( $benutzer->disabled == true ) {
				// user is blocked
                return self::LOGIN_DISABLED;
			}

            // success
			$benutzer->loggedIn = true;
			$this->getStorage()->write( $benutzer );

			return self::LOGIN_SUCCESS;			
		}
		
		return self::LOGIN_WRONG;
	}

	/**
	 * Mithilfe dieser Methode kann ein redirect durchgeführt werden.
     * Führt einen hart Redirect aus!
	 *
	 * @param String $route
	 *
	 */
	public function redirect( $route ) {
	
		$event = $this->sm->get('Application')->getMvcEvent();
		$url = $event->getRouter()->assemble(
				array(), array(
						'name' => $route
				)
		);

        header('Location: ' . $url );
        die();
	}
	
	/**
	 * Ist der aktuelle Benutzer eingeloggt?
	 * @return boolean
	 */
	public function isLoggedIn() {
		
		return (bool) $this->getIdentity()->loggedIn;
		
		/**
		if( $this->getIdentity()->gruppe->name != 'Gast' ) {
			return true;
		}
		
		return false;
		**/
	}

    public function hasIdentity() {

        $parentHasIdentity = parent::hasIdentity();

        // gast account?
        if( $parentHasIdentity && $this->getIdentity()->id === 0 ) {

            // gast account - wie keine identity
            return false;
        }

        return $parentHasIdentity;
    }
	
	/**
	 * Liefert die Identität des Benutzers
	 * @return Benutzer
	 */
	public function getIdentity(){
		return $this->getStorage()->read();
	}
	
	/**
	 * has access?
	 * @param string $action
	 */
	public function hasAccess( $action ) {

        if( $this->getIdentity()->gruppe->supervisor === 1 )
        {
            // global admin
            return true;
        }

		Try {
			return $this->acl->isAllowed(
				$this->getIdentity()->gruppe->name,
				$action
			);
		} Catch( \Exception $e ) {

			// access not found return false;
			return false;
		}
		
		return false;
	}
	
}