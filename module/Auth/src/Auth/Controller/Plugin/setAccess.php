<?php
namespace Auth\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class setAccess extends AbstractPlugin
{
	protected $_authService;
	protected $_sm;
	
	public function setAuthService( $authService ){
		$this->_authService = $authService;
	}
	
	public function getAuthService() {
		return $this->_authService;
	}
	
	public function __invoke( $action, $redirectRoute = null ){
		
		$authService = $this->getAuthService();
		$hasAccess = $authService->hasAccess( $action );

        $controllerClass = get_class($this->getController());
        $moduleNamespace = substr($controllerClass, 0, strpos($controllerClass, '\\'));

        // admin
		if( strtolower( $moduleNamespace ) == 'administration' ) {
            $ns = 'admin';
        } else {
            $ns = 'frontend';
        }

        if( ! $hasAccess ) {

            if( $authService->isLoggedIn() ) {
                // display only if logged in
                $this->getAuthService()->getMessenger()->addErrorMessage( strtoupper($ns) . '_ACCESS_NOT_ALLOWED');
            }

            if( ! $redirectRoute ) {

                if( $this->getAuthService()->hasIdentity() ) {
                    // im frontend auf die startseite leiten
                    $redirectRoute = $ns;
                } else {
                    // im admin auf login verweisen
                    $redirectRoute = $ns . '/login';
                }
            }

            return $this->getAuthService()->redirect(
                $redirectRoute
            );

        }

        return true;

	}
	
}
