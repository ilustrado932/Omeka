<?php
/**
 * @package Omeka
 **/
require_once MODEL_DIR.DIRECTORY_SEPARATOR.'User.php';
require_once 'Zend/Filter/Input.php';
require_once 'Kea/Controller/Action.php';
class UsersController extends Kea_Controller_Action
{	
	public function init() {
		$this->_table = Doctrine_Manager::getInstance()->getTable('User');
		$this->_modelClass = 'User';
	}
	
	public function activateAction()
	{
		$hash = $this->_getParam('u');
		$ua = Doctrine_Manager::getInstance()->getTable('UsersActivations')->findByUrl($hash);
		
		if(!$ua) {
			$this->errorAction();
			return;
		}
		
		if(!empty($_POST)) {
			if($_POST['new_password1'] == $_POST['new_password2']) {
				$ua->User->password = $_POST['new_password1'];
				$ua->User->active = 1;
				$ua->User->save();
				$ua->delete();
				$this->_redirect('users/login');				
			}
		}
		$user = $ua->User;
		$this->render('users/activate.php', compact('user'));
	}
	
	/**
	 *
	 * @return void
	 **/
	public function addAction() 
	{	
		$user = new User();
		$password = $user->generatePassword(8);
		if($this->commitForm($user)) {
			$ua = new UsersActivations;
			$ua->User = $user;
			$ua->generate();
			$ua->save();
			//send the user an email telling them about their great new user account
			$site_title = Doctrine_Manager::getInstance()->getTable('Option')->findByName('site_title');
			$from = Doctrine_Manager::getInstance()->getTable('Option')->findByName('administrator_email');
			
			$body = "Welcome!\n\nYour account for the ".$site_title." archive has been created. Please click the following link to activate your account: <a href=\"".WEB_ROOT."/users/activate?u={$ua->url}\">Activate</a> (or use any other page on the site).\n\nBe aware that we log you out after 15 minutes of inactivity to help protect people using shared computers (at libraries, for instance).\n\n".$site_title." Administrator";
			$title = "Activate your account with the ".$site_title." Archive";
			$header = 'From: '.$from. "\n" . 'X-Mailer: PHP/' . phpversion();
			mail($user->email, $title, $body);
			$this->_redirect('users/show/'.$user->id);
		}else {
			$this->render('users/add.php', compact('user'));
		}
	}
	
	protected function commitForm($user)
	{
		/* Permissions check to see if whoever is trying to change role to a super-user*/	
		if(!empty($_POST['role']) && $_POST['role'] == 'super') {
			if(!$this->isAllowed('makeSuperUser')) {
				$this->flash('User may not change permissions to super-user');
				return false;
			}
		} 
		
		if($_POST['active']) {
			$_POST['active'] = 1;
		}
		//potential security hole
		if(isset($_POST['password'])) {
			unset($_POST['password']);
		}
		//somebody is trying to change the password
		//@todo Put in a security check (superusers don't need to know the old password)
		if(!empty($_POST['new_password1'])) {
			$new1 = $_POST['new_password1'];
			$new2 = $_POST['new_password2'];
			$old = $_POST['old_password'];
			if(empty($new1) || empty($new2) || empty($old)) {
				$user->getErrorStack()->add('password', 'User must fill out all password fields in order to change password');
				return false;
			}
			//If the old passwords don't match up
			if(sha1($old) !== $user->password) {
				$user->getErrorStack()->add('password', 'Old password has been entered incorrectly');
				return false;
			} 
			
			if($new1 !== $new2) {
				$user->getErrorStack()->add('password', 'New password has been entered incorrectly');
				return false;
			}			
			$user->password = $new1;
		}
		return parent::commitForm($user);
	}
	
	public function loginAction()
	{
		if (!empty($_POST)) {
			
			require_once 'Zend/Session.php';

			$session = new Zend_Session;
			
			$filterPost = new Zend_Filter_Input($_POST);
			$auth = $this->_auth;

			$options = array('username' => $filterPost->testAlnum('username'),
							 'password' => $filterPost->testAlnum('password'));

			$token = $auth->authenticate($options);
			
			if ($token->isValid()) {
				//Avoid a redirect by passing an extra parameter to the AJAX call
				if($this->_getParam('noRedirect')) {
					$this->_forward('index', 'home');
				} else {
					$this->_redirect('/');
				}				
				return;
			}
			$this->render('users/login.php', array('errorMessage' => $token->getMessage()));
			return;
		}
		$this->render('users/login.php');
	}
	
	public function logoutAction()
	{
		$auth = $this->_auth;
		$auth->logout();
		$this->_redirect('');
	}

	/**
	 * This hook allows specific user actions to be allowed if and only if an authenticated user 
	 * is accessing their own user data.
	 *
	 **/
	public function preDispatch()
	{		
		$userActions = array('show','edit');
				
		if($current = Kea::loggedIn()) {
			try {
				$user = $this->findById();
				if($current->id == $user->id) {
					foreach ($userActions as $action) {
						$this->setAllowed($action);
					}
				}	
			} catch (Exception $e) {}
				
		}
		return parent::preDispatch();
	}

/**
 * Define Roles Actions
 */		
	public function rolesAction()
	{
		/* Permissions check */	
		if(!$this->isAllowed('showRoles')) {
			$this->_redirect('403');
			return;
		}
		$acl = $this->acl;
		
		$roles = array_keys($acl->getRoles());
		
		foreach($roles as $key => $val) {
			$roles[$val] = $val;
			unset($roles[$key]);
		}
		
		$rules = $acl->getRules();
		$resources = $acl->getResources();
		return $this->render('users/roles.php', compact('roles','rules','resources','acl'));
	}
	
	public function rulesFormAction() {
		/* Permissions check */	
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		$role = $_REQUEST['role'];
		$acl = $this->acl;

		$permissions = $acl->getRules();
		$params = array('permissions' => $permissions, 'role' => $role, 'acl' => $acl);
		$this->render('users/rulesForm.php', $params);
	}

	public function addRoleAction()
	{
		/* Permissions check */	
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		$filterPost = new Zend_Filter_Input($_POST);
		if ($roleName = $filterPost->testAlnum('name')) {
			$acl = $this->acl;
			if (!$acl->hasRole($roleName)) {
				$acl->addRole(new Zend_Acl_Role($roleName));
				$dbAcl = $this->getOption('acl');
				$dbAcl->value = serialize($acl);
				$dbAcl->save();
			}
			else {
				/**
				 * Return some message that the role name has already been taken
				 */
			}
		}
		
		/**
		 * Support some implementation abstract method of handling
		 * both ajax and regular calls
		 */
		if ($filterPost->getAlpha('request') == 'ajax') {
			return null;
		}
		else {
			$this->_redirect('users/roles');
		}
	}
	
	public function deleteRoleAction()
	{
		/* Permissions check */	
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		$filterPost = new Zend_Filter_Input($_POST);
		if ($roleName = $filterPost->testAlnum('role')) {
			$acl = $this->acl;
			if ($acl->hasRole($roleName)) {
				$acl->removeRole($roleName);
				$acl->save();
			}
		}
		$this->_redirect('users/roles');
	}
	
	public function setPermissionsAction() {
		/* Permissions check */	
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		$role = $_POST['role'];
		if (!empty($role)) {
			$acl = $this->acl;
			$acl->removeRulesByRole($role);
			foreach($_POST['permissions'] as $resource => $permissions) {
				$resource_permissions = array();
				foreach($permissions as $permission => $on) {
					$resource_permissions[] = $permission;
				}
				$acl->allow($role, $resource, $resource_permissions);
			}
			$acl->save();
		}
		$this->_redirect('users/roles');
	}

	/**
	 * IMPORTANT - This should only be used for testing (or to assist hackers in modding the Omeka codebase)
	 *
	 * @return void
	 **/
	public function addRuleAction()
	{
		/* Permissions check */
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		if(!empty($_POST)) {
			$this->acl->registerRule(new Zend_Acl_Resource($_POST['rule']), $_POST['action']);
			$this->acl->save();
		}
		$this->_redirect('users/roles');
	}
	
	public function deleteRuleAction()
	{
		/* Permissions check */
		if(!$this->isAllowed('editRoles')) $this->_redirect('403');
		
		if(!empty($_POST)) {
			$this->acl->removeRule(new Zend_Acl_Resource($_POST['rule']), $_POST['action']);
			$this->acl->save();
		}
		$this->_redirect('users/roles');
	}
	
    public function noRouteAction()
    {
        $this->_redirect('/');
    }
}
?>