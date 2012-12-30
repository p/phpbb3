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
* Private message notifications class
* This class handles notifications for reporting private messages
*
* @package notifications
*/
class phpbb_notification_type_report_pm extends phpbb_notification_type_pm
{
	/**
	* Email template to use to send notifications
	*
	* @var string
	*/
	public $email_template = 'notifications/report_post';

	/**
	* Permission to check for (in find_users_for_notification)
	*
	* @var string Permission name
	*/
	protected $permission = 'm_report';

	/**
	* Notification option data (for outputting to the user)
	*
	* @var bool|array False if the service should use it's default data
	* 					Array of data (including keys 'id' and 'lang')
	*/
	public static $notification_option = array(
		'id'	=> 'report',
		'lang'	=> 'NOTIFICATION_TYPE_REPORT',
	);

	/**
	* Get the type of notification this is
	* phpbb_notification_type_
	*/
	public static function get_item_type()
	{
		return 'report_pm';
	}

	/**
	* Find the users who want to receive notifications
	*
	* @param array $post Data from the post
	*
	* @return array
	*/
	public function find_users_for_notification($post, $options = array())
	{
		$options = array_merge(array(
			'ignore_users'		=> array(),
		), $options);

		$auth_approve = $this->auth->acl_get_list(false, $this->permission, $post['forum_id']);

		if (empty($auth_approve))
		{
			return array();
		}

		$notify_users = array();

		$sql = 'SELECT *
			FROM ' . USER_NOTIFICATIONS_TABLE . "
			WHERE item_type = '" . self::$notification_option['id'] . "'
				AND " . $this->db->sql_in_set('user_id', $auth_approve[$post['forum_id']][$this->permission]);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			if (isset($options['ignore_users'][$row['user_id']]) && in_array($row['method'], $options['ignore_users'][$row['user_id']]))
			{
				continue;
			}

			if (!isset($rowset[$row['user_id']]))
			{
				$notify_users[$row['user_id']] = array();
			}

			$notify_users[$row['user_id']][] = $row['method'];
		}
		$this->db->sql_freeresult($result);

		return $notify_users;
	}

	/**
	* Get email template variables
	*
	* @return array
	*/
	public function get_email_template_variables()
	{
		$board_url = generate_board_url();

		return array(
			'POST_SUBJECT'				=> htmlspecialchars_decode(censor_text($this->get_data('post_subject'))),
			'TOPIC_TITLE'				=> htmlspecialchars_decode(censor_text($this->get_data('topic_title'))),

			'U_VIEW_REPORT'				=> "{$board_url}mcp.{$this->php_ext}?f={$this->get_data('forum_id')}&amp;p={$this->item_id}&amp;i=reports&amp;mode=report_details#reports",
			'U_VIEW_POST'				=> "{$board_url}/viewtopic.{$this->php_ext}?p={$this->item_id}#p{$this->item_id}",
			'U_NEWEST_POST'				=> "{$board_url}/viewtopic.{$this->php_ext}?f={$this->get_data('forum_id')}&t={$this->item_parent_id}&view=unread#unread",
			'U_TOPIC'					=> "{$board_url}/viewtopic.{$this->php_ext}?f={$this->get_data('forum_id')}&t={$this->item_parent_id}",
			'U_VIEW_TOPIC'				=> "{$board_url}/viewtopic.{$this->php_ext}?f={$this->get_data('forum_id')}&t={$this->item_parent_id}",
			'U_FORUM'					=> "{$board_url}/viewforum.{$this->php_ext}?f={$this->get_data('forum_id')}",
		);
	}

	/**
	* Get the url to this item
	*
	* @return string URL
	*/
	public function get_url()
	{
		return append_sid($this->phpbb_root_path . 'mcp.' . $this->php_ext, "f={$this->get_data('forum_id')}&amp;p={$this->item_id}&amp;i=reports&amp;mode=report_details#reports");
	}

	/**
	* Get the HTML formatted title of this notification
	*
	* @return string
	*/
	public function get_title()
	{
		$this->user->add_lang('mcp');

		if (isset($this->user->lang[$this->get_data('reason_title')]))
		{
			return $this->user->lang(
				$this->language_key,
				censor_text($this->get_data('post_subject')),
				$this->user->lang[$this->get_data('reason_title')]
			);
		}

		return $this->user->lang(
			$this->language_key,
			censor_text($this->get_data('post_subject')),
			$this->get_data('reason_description')
		);
	}

	/**
	* Function for preparing the data for insertion in an SQL query
	* (The service handles insertion)
	*
	* @param array $post Data from submit_post
	*
	* @return array Array of data ready to be inserted into the database
	*/
	public function create_insert_array($post)
	{
		$this->set_data('reason_title', strtoupper($post['reason_title']));
		$this->set_data('reason_description', $post['reason_description']);

		return parent::create_insert_array($post);
	}
}
