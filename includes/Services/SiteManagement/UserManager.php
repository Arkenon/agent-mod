<?php

/**
 * User manager.
 *
 * Native equivalent of the `wp user` WP-CLI command family, with additional
 * privilege-escalation guards that WP-CLI does not apply because it assumes an
 * operator with shell access.
 *
 * Passwords are never generated into a response: ability results are written
 * verbatim into the chat transcript and the stored conversation history, so a
 * plaintext password there would outlive the conversation. Account setup and
 * password changes go out as standard WordPress emails instead.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use AgentMod\Services\SettingsService;
use WP_Error;
use WP_User_Query;

defined('ABSPATH') || exit;

class UserManager
{
	/**
	 * Upgrader context, used to load the wp-admin user functions.
	 *
	 * @var UpgraderContext
	 * @since 1.1.0
	 */
	private UpgraderContext $context;

	/**
	 * Pre-flight guard.
	 *
	 * @var Guard
	 * @since 1.1.0
	 */
	private Guard $guard;

	/**
	 * Settings service, used for result limits.
	 *
	 * @var SettingsService
	 * @since 1.1.0
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 *
	 * @param UpgraderContext $context  Upgrader context.
	 * @param Guard           $guard    Pre-flight guard.
	 * @param SettingsService $settings Settings service.
	 *
	 * @since 1.1.0
	 */
	public function __construct(UpgraderContext $context, Guard $guard, SettingsService $settings)
	{
		$this->context  = $context;
		$this->guard    = $guard;
		$this->settings = $settings;
	}

	/**
	 * Lists user accounts. Equivalent of `wp user list`.
	 *
	 * @param array<string, mixed> $input Optional 'search' and 'role' filters.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function listUsers(array $input)
	{
		$denied = $this->guard->assertCapability('list_users');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$limit = $this->settings->getMaxSearchResults();

		$args = [
			'number'  => $limit,
			'orderby' => 'ID',
			'order'   => 'ASC',
		];

		if (! empty($input['search'])) {
			$args['search']         = '*' . sanitize_text_field((string) $input['search']) . '*';
			$args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
		}

		if (! empty($input['role'])) {
			$args['role'] = sanitize_key((string) $input['role']);
		}

		$query = new WP_User_Query($args);
		$users = [];

		foreach ($query->get_results() as $user) {
			$users[] = [
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => array_values((array) $user->roles),
				'registered'   => $user->user_registered,
			];
		}

		$total = (int) $query->get_total();

		return [
			'users'     => $users,
			'total'     => $total,
			'truncated' => $total > count($users),
			'roles'     => array_keys(wp_roles()->roles),
		];
	}

	/**
	 * Creates or modifies a user account.
	 *
	 * @param array<string, mixed> $input Requires 'action'; other keys depend on it.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function manage(array $input)
	{
		$this->context->bootUserAdmin();

		$action = isset($input['action']) ? sanitize_key((string) $input['action']) : '';

		switch ($action) {
			case 'create':
				return $this->create($input);

			case 'update':
				return $this->update($input);

			case 'set-role':
				return $this->setRole($input);

			case 'send-password-reset':
				return $this->sendPasswordReset($input);
		}

		return new WP_Error(
			'agent_mod_invalid_action',
			__('Unknown action. Use one of: create, update, set-role, send-password-reset.', 'agent-mod')
		);
	}

	/**
	 * Deletes a user account, reassigning their content.
	 *
	 * Equivalent of `wp user delete --reassign=`.
	 *
	 * @param array<string, mixed> $input Requires 'id' and 'reassign_to'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function remove(array $input)
	{
		$denied = $this->guard->assertCapability('delete_users');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		if (is_multisite()) {
			return new WP_Error(
				'agent_mod_multisite_user_delete',
				__('On a multisite network, deleting a user removes them from every site. That is too broad to do conversationally — please use the Network Admin → Users screen.', 'agent-mod')
			);
		}

		$this->context->bootUserAdmin();

		$userId = isset($input['id']) ? (int) $input['id'] : 0;

		$blocked = $this->guard->assertUserTarget($userId, 'delete');

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$lastAdmin = $this->guard->assertNotLastAdministrator($userId);

		if ($lastAdmin instanceof WP_Error) {
			return $lastAdmin;
		}

		$reassignTo = isset($input['reassign_to']) ? (int) $input['reassign_to'] : 0;

		if ($reassignTo <= 0 || ! get_userdata($reassignTo)) {
			return new WP_Error(
				'agent_mod_missing_reassign',
				__('Deleting a user requires reassign_to: the ID of the user who should inherit their posts and pages. Use get-users to pick one.', 'agent-mod')
			);
		}

		if ($reassignTo === $userId) {
			return new WP_Error(
				'agent_mod_invalid_reassign',
				__('Content cannot be reassigned to the user being deleted.', 'agent-mod')
			);
		}

		$user = get_userdata($userId);
		$name = $user->display_name;

		if (! wp_delete_user($userId, $reassignTo)) {
			return new WP_Error(
				'agent_mod_user_delete_failed',
				__('WordPress could not delete that user account.', 'agent-mod')
			);
		}

		return [
			'success'      => true,
			'id'           => $userId,
			'display_name' => $name,
			'reassigned_to' => $reassignTo,
			'message'      => sprintf(
				/* translators: 1: deleted user display name, 2: display name of the user inheriting the content. */
				__('The account "%1$s" was deleted and its content reassigned to "%2$s".', 'agent-mod'),
				$name,
				get_userdata($reassignTo)->display_name
			),
		];
	}

	// =========================================================================
	// Internal actions
	// =========================================================================

	/**
	 * Creates a new user account.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function create(array $input)
	{
		$denied = $this->guard->assertCapability('create_users');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$login = isset($input['login']) ? sanitize_user((string) $input['login'], true) : '';
		$email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
		$role  = isset($input['role']) ? sanitize_key((string) $input['role']) : get_option('default_role', 'subscriber');

		if ('' === $login) {
			return new WP_Error('agent_mod_missing_login', __('A username is required.', 'agent-mod'));
		}

		if (! is_email($email)) {
			return new WP_Error('agent_mod_invalid_email', __('A valid email address is required.', 'agent-mod'));
		}

		if (username_exists($login)) {
			return new WP_Error(
				'agent_mod_login_exists',
				sprintf(
					/* translators: %s: username. */
					__('The username "%s" is already taken.', 'agent-mod'),
					$login
				)
			);
		}

		if (email_exists($email)) {
			return new WP_Error(
				'agent_mod_email_exists',
				sprintf(
					/* translators: %s: email address. */
					__('An account already uses the email address %s.', 'agent-mod'),
					$email
				)
			);
		}

		$roleAllowed = $this->guard->assertRoleAssignable($role);

		if ($roleAllowed instanceof WP_Error) {
			return $roleAllowed;
		}

		$userId = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				// Never surfaced: the user sets their own via the emailed link.
				'user_pass'    => wp_generate_password(24, true, true),
				'display_name' => isset($input['display_name']) ? sanitize_text_field((string) $input['display_name']) : $login,
				'first_name'   => isset($input['first_name']) ? sanitize_text_field((string) $input['first_name']) : '',
				'last_name'    => isset($input['last_name']) ? sanitize_text_field((string) $input['last_name']) : '',
				'role'         => $role,
			]
		);

		if (is_wp_error($userId)) {
			return $userId;
		}

		wp_send_new_user_notifications((int) $userId, 'user');

		return [
			'success'      => true,
			'id'           => (int) $userId,
			'login'        => $login,
			'email'        => $email,
			'role'         => $role,
			'message'      => sprintf(
				/* translators: 1: username, 2: role slug, 3: email address. */
				__('Created the account "%1$s" with the %2$s role. A set-your-password email was sent to %3$s; no password is shown here on purpose.', 'agent-mod'),
				$login,
				$role,
				$email
			),
		];
	}

	/**
	 * Updates a user's profile fields.
	 *
	 * The username is immutable in WordPress and the password is never set here.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function update(array $input)
	{
		$userId = isset($input['id']) ? (int) $input['id'] : 0;

		$blocked = $this->guard->assertUserTarget($userId, 'edit');

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$fields = ['id' => $userId];

		if (isset($input['email'])) {
			$email = sanitize_email((string) $input['email']);

			if (! is_email($email)) {
				return new WP_Error('agent_mod_invalid_email', __('A valid email address is required.', 'agent-mod'));
			}

			$existing = email_exists($email);

			if ($existing && (int) $existing !== $userId) {
				return new WP_Error(
					'agent_mod_email_exists',
					sprintf(
						/* translators: %s: email address. */
						__('Another account already uses the email address %s.', 'agent-mod'),
						$email
					)
				);
			}

			$fields['user_email'] = $email;
		}

		foreach (['display_name', 'first_name', 'last_name', 'nickname'] as $field) {
			if (isset($input[$field])) {
				$fields[$field] = sanitize_text_field((string) $input[$field]);
			}
		}

		if (isset($input['description'])) {
			$fields['description'] = sanitize_textarea_field((string) $input['description']);
		}

		if (count($fields) < 2) {
			return new WP_Error(
				'agent_mod_nothing_to_update',
				__('Provide at least one field to change: email, display_name, first_name, last_name, nickname or description. Usernames cannot be changed in WordPress, and passwords are changed with the send-password-reset action.', 'agent-mod')
			);
		}

		$result = wp_update_user($fields);

		if (is_wp_error($result)) {
			return $result;
		}

		$user = get_userdata($userId);

		return [
			'success'      => true,
			'id'           => $userId,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'message'      => sprintf(
				/* translators: %s: username. */
				__('The account "%s" was updated.', 'agent-mod'),
				$user->user_login
			),
		];
	}

	/**
	 * Changes a user's role.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function setRole(array $input)
	{
		$userId = isset($input['id']) ? (int) $input['id'] : 0;
		$role   = isset($input['role']) ? sanitize_key((string) $input['role']) : '';

		$blocked = $this->guard->assertUserTarget($userId, 'promote');

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$roleAllowed = $this->guard->assertRoleAssignable($role);

		if ($roleAllowed instanceof WP_Error) {
			return $roleAllowed;
		}

		// Demoting the only administrator is as damaging as deleting them.
		if ('administrator' !== $role) {
			$lastAdmin = $this->guard->assertNotLastAdministrator($userId);

			if ($lastAdmin instanceof WP_Error) {
				return $lastAdmin;
			}
		}

		$user     = get_userdata($userId);
		$previous = array_values((array) $user->roles);

		$user->set_role($role);

		return [
			'success'        => true,
			'id'             => $userId,
			'login'          => $user->user_login,
			'role'           => $role,
			'previous_roles' => $previous,
			'message'        => sprintf(
				/* translators: 1: username, 2: new role slug. */
				__('The account "%1$s" now has the %2$s role.', 'agent-mod'),
				$user->user_login,
				$role
			),
		];
	}

	/**
	 * Emails a password reset link to a user.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function sendPasswordReset(array $input)
	{
		$userId = isset($input['id']) ? (int) $input['id'] : 0;

		$blocked = $this->guard->assertUserTarget($userId, 'edit');

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$user   = get_userdata($userId);
		$result = retrieve_password($user->user_login);

		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'success' => true,
			'id'      => $userId,
			'login'   => $user->user_login,
			'message' => sprintf(
				/* translators: 1: username, 2: email address. */
				__('A password reset link was emailed to "%1$s" at %2$s. The assistant never sees or sets the password itself.', 'agent-mod'),
				$user->user_login,
				$user->user_email
			),
		];
	}
}
