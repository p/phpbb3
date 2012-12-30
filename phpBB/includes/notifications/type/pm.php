<?php
/**
*
* @package notifications
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Private message notifications class
* This class handles notifications for private messages
*
* @package notifications
*/
class phpbb_notifications_type_pm extends phpbb_notifications_type_base
{
	/**
	* Email template to use to send notifications
	*
	* @var string
	*/
	public $email_template = 'privmsg_notify';

	/**
	* Get the type of notification this is
	* phpbb_notifications_type_
	*/
	public static function get_item_type()
	{
		return 'pm';
	}

	/**
	* Get the id of the
	*
	* @param array $pm The data from the private message
	*/
	public static function get_item_id($pm)
	{
		return (int) $pm['msg_id'];
	}

	/**
	* Get the id of the parent
	*
	* @param array $pm The data from the pm
	*/
	public static function get_item_parent_id($pm)
	{
		// No parent
		return 0;
	}

	/**
	* Find the users who want to receive notifications
	*
	* @param ContainerBuilder $phpbb_container
	* @param array $pm Data from
	*
	* @return array
	*/
	public static function find_users_for_notification(ContainerBuilder $phpbb_container, $pm)
	{
		$service = $phpbb_container->get('notifications');
		$db = $phpbb_container->get('dbal.conn');
		$user = $phpbb_container->get('user');

		if (!sizeof($pm['recipients']))
		{
			return array();
		}

		$service->load_users(array_keys($pm['recipients']));

		$notify_users = array();

		foreach (array_keys($pm['recipients']) as $user_id)
		{
			$recipient = $service->get_user($user_id);

			if ($recipient['user_notify_pm'])
			{
				$notify_users[$recipient['user_id']] = array();

				if ($recipient['user_notify_type'] == NOTIFY_EMAIL || $recipient['user_notify_type'] == NOTIFY_BOTH)
				{
					$notify_users[$recipient['user_id']][] = 'email';
				}

				if ($recipient['user_notify_type'] == NOTIFY_IM || $recipient['user_notify_type'] == NOTIFY_BOTH)
				{
					$notify_users[$recipient['user_id']][] = 'jabber';
				}
			}
		}

		return $notify_users;
	}

	/**
	* Get the user's avatar
	*/
	public function get_avatar()
	{
		return $this->_get_avatar($this->get_data('from_user_id'));
	}

	/**
	* Get the HTML formatted title of this notification
	*
	* @return string
	*/
	public function get_title()
	{
		$user_data = $this->service->get_user($this->get_data('from_user_id'));

		$username = get_username_string('no_profile', $user_data['user_id'], $user_data['username'], $user_data['user_colour']);

		return $this->phpbb_container->get('user')->lang('NOTIFICATION_PM', $username, $this->get_data('message_subject'));
	}

	/**
	* Get email template variables
	*
	* @return array
	*/
	public function get_email_template_variables()
	{
		$user_data = $this->service->get_user($this->get_data('from_user_id'));

		return array(
			'AUTHOR_NAME'				=> htmlspecialchars_decode($user_data['username']),
			'SUBJECT'					=> htmlspecialchars_decode(censor_text($this->get_data('message_subject'))),

			'U_VIEW_MESSAGE'			=> generate_board_url() . '/ucp.' . $this->php_ext . "?i=pm&mode=view&p={$this->item_id}",
		);
	}

	/**
	* Get the url to this item
	*
	* @return string URL
	*/
	public function get_url()
	{
		return append_sid($this->phpbb_root_path . 'ucp.' . $this->php_ext, "i=pm&amp;mode=view&amp;p={$this->item_id}");
	}

	/**
	* Users needed to query before this notification can be displayed
	*
	* @return array Array of user_ids
	*/
	public function users_to_query()
	{
		return array($this->data['from_user_id']);
	}

	/**
	* Function for preparing the data for insertion in an SQL query
	* (The service handles insertion)
	*
	* @param array $post Data from submit_post
	*
	* @return array Array of data ready to be inserted into the database
	*/
	public function create_insert_array($pm)
	{
		$this->set_data('from_user_id', $pm['from_user_id']);

		$this->set_data('message_subject', $pm['message_subject']);

		return parent::create_insert_array($pm);
	}
}
