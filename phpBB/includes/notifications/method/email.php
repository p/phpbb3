<?php
/**
*
* @package notifications
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Email notification method class
* This class handles sending emails for notifications
*
* @package notifications
*/
class phpbb_notifications_method_email extends phpbb_notifications_method_base
{
	/**
	* Is this method available for the user?
	* This is checked on the notifications options
	*/
	public static function is_available()
	{
		// Email is always available
		return true;
	}

	public function notify()
	{
		if (!sizeof($this->queue))
		{
			return;
		}

		// Load all users we want to notify (we need their email address)
		$user_ids = $users = array();
		foreach ($this->queue as $notification)
		{
			$user_ids[] = $notification->user_id;
		}

		// We do not send emails to banned users
		if (!function_exists('phpbb_get_banned_user_ids'))
		{
			include($this->phpbb_container->getParameter('core.root_path') . 'includes/functions_user.' . $this->phpbb_container->getParameter('core.php_ext'));
		}
		$banned_users = phpbb_get_banned_user_ids($user_ids);

		// Load all the users we need
		$this->service->load_users($user_ids);

		// Load the messenger
		if (!class_exists('messenger'))
		{
			include($this->phpbb_root_path . 'includes/functions_messenger.' . $this->php_ext);
		}
		$messenger = new messenger();
		$board_url = generate_board_url();

		// Time to go through the queue and send emails
		foreach ($this->queue as $notification)
		{
			$user = $this->service->get_user($notification->user_id);

			if ($user['user_type'] == USER_IGNORE || in_array($notification->user_id, $banned_users))
			{
				continue;
			}

			$messenger->template('notification', $user['user_lang']);

			$messenger->to($user['user_email'], $user['username']);

			$messenger->assign_vars(array(
				'USERNAME'			=> $user['username'],

				'MESSAGE'			=> htmlspecialchars_decode($notification->get_title()),

				'U_VIEW_MESSAGE'	=> $notification->get_full_url(),

				'U_UNSUBSCRIBE'		=> $notification->get_unsubscribe_url(),
			));

			$messenger->send('email');
		}

		// Save the queue in the messenger class (has to be called or these emails could be lost?)
		$messenger->save_queue();

		// We're done, empty the queue
		$this->empty_queue();
	}
}
