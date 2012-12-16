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
* Notifications service class
* @package notifications
*/
class phpbb_notification_manager
{
	/** @var array */
	protected $notification_types = null;

	/** @var array */
	protected $notification_methods = null;

	/** @var ContainerBuilder */
	protected $phpbb_container = null;

	/** @var phpbb_user_loader */
	protected $user_loader = null;

	/** @var phpbb_db_driver */
	protected $db = null;

	/** @var phpbb_user */
	protected $user = null;

	/** @var string */
	protected $phpbb_root_path = null;

	/** @var string */
	protected $php_ext = null;

	/** @var string */
	protected $notification_types_table = null;

	/** @var string */
	protected $notifications_table = null;

	/** @var string */
	protected $user_notifications_table = null;

	public function __construct($notification_types, $notification_methods, $phpbb_container, phpbb_user_loader $user_loader, phpbb_db_driver $db, $user, $phpbb_root_path, $php_ext, $notification_types_table, $notifications_table, $user_notifications_table)
	{
		$this->notification_types = $notification_types;
		$this->notification_methods = $notification_methods;
		$this->phpbb_container = $phpbb_container;

		$this->user_loader = $user_loader;
		$this->db = $db;
		$this->user = $user;

		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;

		$this->notification_types_table = $notification_types_table;
		$this->notifications_table = $notifications_table;
		$this->user_notifications_table = $user_notifications_table;
	}

	/**
	* Load the user's notifications
	*
	* @param array $options Optional options to control what notifications are loaded
	*				notification_id		Notification id to load (or array of notification ids)
	*				user_id				User id to load notifications for (Default: $user->data['user_id'])
	*				order_by			Order by (Default: time)
	*				order_dir			Order direction (Default: DESC)
	* 				limit				Number of notifications to load (Default: 5)
	* 				start				Notifications offset (Default: 0)
	* 				all_unread			Load all unread notifications? If set to true, count_unread is set to true (Default: false)
	* 				count_unread		Count all unread notifications? (Default: false)
	* 				count_total			Count all notifications? (Default: false)
	* @return array Array of information based on the request with keys:
	*	'notifications'		array of notification type objects
	*	'unread_count'		number of unread notifications the user has if count_unread is true in the options
	*	'total_count'		number of notifications the user has if count_total is true in the options
	*/
	public function load_notifications(array $options = array())
	{
		// Merge default options
		$options = array_merge(array(
			'notification_id'	=> false,
			'user_id'			=> $this->user->data['user_id'],
			'order_by'			=> 'notification_time',
			'order_dir'			=> 'DESC',
			'limit'				=> 0,
			'start'				=> 0,
			'all_unread'		=> false,
			'count_unread'		=> false,
			'count_total'		=> false,
		), $options);

		// If all_unread, count_unread must be true
		$options['count_unread'] = ($options['all_unread']) ? true : $options['count_unread'];

		// Anonymous users and bots never receive notifications
		if ($options['user_id'] == $this->user->data['user_id'] && ($this->user->data['user_id'] == ANONYMOUS || $this->user->data['user_type'] == USER_IGNORE))
		{
			return array(
				'notifications'		=> array(),
				'unread_count'		=> 0,
				'total_count'		=> 0,
			);
		}

		$notifications = $user_ids = array();
		$load_special = array();
		$total_count = $unread_count = 0;

		if ($options['count_unread'])
		{
			// Get the total number of unread notifications
			$sql = 'SELECT COUNT(n.notification_id) AS unread_count
				FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . ' nt
				WHERE n.user_id = ' . (int) $options['user_id'] . '
					AND n.notification_read = 0
					AND nt.notification_type = n.notification_type
					AND nt.notification_type_enabled = 1';
			$result = $this->db->sql_query($sql);
			$unread_count = (int) $this->db->sql_fetchfield('unread_count', $result);
			$this->db->sql_freeresult($result);
		}

		if ($options['count_total'])
		{
			// Get the total number of notifications
			$sql = 'SELECT COUNT(n.notification_id) AS total_count
				FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . ' nt
				WHERE n.user_id = ' . (int) $options['user_id'] . '
					AND nt.notification_type = n.notification_type
					AND nt.notification_type_enabled = 1';
			$result = $this->db->sql_query($sql);
			$total_count = (int) $this->db->sql_fetchfield('total_count', $result);
			$this->db->sql_freeresult($result);
		}

		if (!$options['count_total'] || $total_count)
		{
			$rowset = array();

			// Get the main notifications
			$sql = 'SELECT n.*
				FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . ' nt
				WHERE n.user_id = ' . (int) $options['user_id'] .
					(($options['notification_id']) ? ((is_array($options['notification_id'])) ? ' AND ' . $this->db->sql_in_set('n.notification_id', $options['notification_id']) : ' AND n.notification_id = ' . (int) $options['notification_id']) : '') . '
					AND nt.notification_type = n.notification_type
					AND nt.notification_type_enabled = 1
				ORDER BY n.' . $this->db->sql_escape($options['order_by']) . ' ' . $this->db->sql_escape($options['order_dir']);
			$result = $this->db->sql_query_limit($sql, $options['limit'], $options['start']);

			while ($row = $this->db->sql_fetchrow($result))
			{
				$rowset[$row['notification_id']] = $row;
			}
			$this->db->sql_freeresult($result);

			// Get all unread notifications
			if ($unread_count && $options['all_unread'] && !empty($rowset))
			{
				$sql = 'SELECT n.*
				FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . ' nt
					WHERE n.user_id = ' . (int) $options['user_id'] . '
						AND n.notification_read = 0
						AND ' . $this->db->sql_in_set('n.notification_id', array_keys($rowset), true) . '
						AND nt.notification_type = n.notification_type
						AND nt.notification_type_enabled = 1
					ORDER BY n.' . $this->db->sql_escape($options['order_by']) . ' ' . $this->db->sql_escape($options['order_dir']);
				$result = $this->db->sql_query_limit($sql, $options['limit'], $options['start']);

				while ($row = $this->db->sql_fetchrow($result))
				{
					$rowset[$row['notification_id']] = $row;
				}
				$this->db->sql_freeresult($result);
			}

			foreach ($rowset as $row)
			{
				$notification = $this->get_notification_type_class($row['notification_type'], $row);

				// Array of user_ids to query all at once
				$user_ids = array_merge($user_ids, $notification->users_to_query());

				// Some notification types also require querying additional tables themselves
				if (!isset($load_special[$row['notification_type']]))
				{
					$load_special[$row['notification_type']] = array();
				}
				$load_special[$row['notification_type']] = array_merge($load_special[$row['notification_type']], $notification->get_load_special());

				$notifications[$row['notification_id']] = $notification;
			}

			$this->user_loader->load_users($user_ids);

			// Allow each type to load its own special items
			foreach ($load_special as $notification_type => $data)
			{
				$item_class = $this->get_notification_type_class($notification_type);

				$item_class->load_special($data, $notifications);
			}
		}

		return array(
			'notifications'		=> $notifications,
			'unread_count'		=> $unread_count,
			'total_count'		=> $total_count,
		);
	}

	/**
	* Mark notifications read
	*
	* @param bool|string|array $notification_type Type identifier or array of item types (only acceptable if the $data is identical for the specified types). False to mark read for all item types
	* @param bool|int|array $notification_type_id Item id or array of item ids. False to mark read for all item ids
	* @param bool|int|array $user_id User id or array of user ids. False to mark read for all user ids
	* @param bool|int $time Time at which to mark all notifications prior to as read. False to mark all as read. (Default: False)
	*/
	public function mark_notifications_read($notification_type, $notification_type_id, $user_id, $time = false)
	{
		$time = ($time !== false) ? $time : time();

		$sql = 'UPDATE ' . $this->notifications_table . "
			SET notification_read = 1
			WHERE notification_time <= " . $time .
				(($notification_type !== false) ? ' AND ' . (is_array($notification_type) ? $this->db->sql_in_set('notification_type', $notification_type) : " notification_type = '" . $this->db->sql_escape($notification_type) . "'") : '') .
				(($notification_type_id !== false) ? ' AND ' . (is_array($notification_type_id) ? $this->db->sql_in_set('notification_type_id', $notification_type_id) : 'notification_type_id = ' . (int) $notification_type_id) : '') .
				(($user_id !== false) ? ' AND ' . (is_array($user_id) ? $this->db->sql_in_set('user_id', $user_id) : 'user_id = ' . (int) $user_id) : '');
		$this->db->sql_query($sql);
	}

	/**
	* Mark notifications read from a parent identifier
	*
	* @param string|array $notification_type Type identifier or array of item types (only acceptable if the $data is identical for the specified types)
	* @param bool|int|array $notification_type_parent_id Item parent id or array of item parent ids. False to mark read for all item parent ids
	* @param bool|int|array $user_id User id or array of user ids. False to mark read for all user ids
	* @param bool|int $time Time at which to mark all notifications prior to as read. False to mark all as read. (Default: False)
	*/
	public function mark_notifications_read_by_parent($notification_type, $notification_type_parent_id, $user_id, $time = false)
	{
		if (is_array($notification_type))
		{
			foreach ($notification_type as $type)
			{
				$this->mark_notifications_read_by_parent($type, $notification_type_parent_id, $user_id, $time);
			}

			return;
		}

		$time = ($time !== false) ? $time : time();

		$sql = 'UPDATE ' . $this->notifications_table . "
			SET notification_read = 1
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND notification_time <= " . $time .
				(($notification_type_parent_id !== false) ? ' AND ' . (is_array($notification_type_parent_id) ? $this->db->sql_in_set('notification_type_parent_id', $notification_type_parent_id) : 'notification_type_parent_id = ' . (int) $notification_type_parent_id) : '') .
				(($user_id !== false) ? ' AND ' . (is_array($user_id) ? $this->db->sql_in_set('user_id', $user_id) : 'user_id = ' . (int) $user_id) : '');
		$this->db->sql_query($sql);
	}

	/**
	* Mark notifications read
	*
	* @param int|array $notification_id Notification id or array of notification ids.
	* @param bool|int $time Time at which to mark all notifications prior to as read. False to mark all as read. (Default: False)
	*/
	public function mark_notifications_read_by_id($notification_id, $time = false)
	{
		$time = ($time !== false) ? $time : time();

		$sql = 'UPDATE ' . $this->notifications_table . "
			SET notification_read = 1
			WHERE notification_time <= " . $time . '
				AND ' . ((is_array($notification_id)) ? $this->db->sql_in_set('notification_id', $notification_id) : 'notification_id = ' . (int) $notification_id);
		$this->db->sql_query($sql);
	}

	/**
	* Add a notification
	*
	* @param string|array $notification_type Type identifier or array of item types (only acceptable if the $data is identical for the specified types)
	*			Note: If you send an array of types, any user who could receive multiple notifications from this single item will only receive
	* 			a single notification. If they MUST receive multiple notifications, call this function multiple times instead of sending an array
	* @param array $data Data specific for this type that will be inserted
	* @param array $options Optional options to control what notifications are loaded
	* 			ignore_users	array of data to specify which users should not receive certain types of notifications
	* @return array Information about what users were notified and how they were notified
	*/
	public function add_notifications($notification_type, $data, array $options = array())
	{
		$options = array_merge(array(
			'ignore_users'		=> array(),
		), $options);

		if (is_array($notification_type))
		{
			$notified_users = array();
			$temp_options = $options;

			foreach ($notification_type as $type)
			{
				$temp_options['ignore_users'] = $options['ignore_users'] + $notified_users;
				$notified_users += $this->add_notifications($type, $data, $temp_options);
			}

			return $notified_users;
		}

		$notification_type_id = $this->get_notification_type_class($notification_type)->get_notification_type_id($data);

		// find out which users want to receive this type of notification
		$notify_users = $this->get_notification_type_class($notification_type)->find_users_for_notification($data, $options);

		$this->add_notifications_for_users($notification_type, $data, $notify_users);

		return $notify_users;
	}

	/**
	* Add a notification for specific users
	*
	* @param string|array $notification_type Type identifier or array of item types (only acceptable if the $data is identical for the specified types)
	* @param array $data Data specific for this type that will be inserted
	* @param array $notify_users User list to notify
	*/
	public function add_notifications_for_users($notification_type, $data, $notify_users)
	{
		if (is_array($notification_type))
		{
			foreach ($notification_type as $type)
			{
				$this->add_notifications_for_users($type, $data, $notify_users);
			}

			return;
		}

		$sql = 'SELECT notification_type
			FROM ' . $this->notification_types_table . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'";
		$result = $this->db->sql_query($sql);

		if ($this->db->sql_fetchrow($result) === false)
		{
			// Does not exist in the database, must add the item type
			$sql = 'INSERT INTO ' . $this->notification_types_table . ' ' . $this->db->sql_build_array('INSERT', array(
				'notification_type'				=> $notification_type,
				'notification_type_enabled'		=> 1,
			));
			$this->db->sql_query($sql);
		}

		$this->db->sql_freeresult($result);

		$notification_type_id = $this->get_notification_type_class($notification_type)->get_notification_type_id($data);

		$user_ids = array();
		$notification_objects = $notification_methods = array();
		$new_rows = array();

		// Never send notifications to the anonymous user!
		unset($notify_users[ANONYMOUS]);

		// Make sure not to send new notifications to users who've already been notified about this item
		// This may happen when an item was added, but now new users are able to see the item
		$sql = 'SELECT n.user_id
			FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . " nt
			WHERE n.notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND n.notification_type_id = " . (int) $notification_type_id . '
				AND nt.notification_type = n.notification_type
				AND nt.notification_type_enabled = 1';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			unset($notify_users[$row['user_id']]);
		}
		$this->db->sql_freeresult($result);

		if (!sizeof($notify_users))
		{
			return;
		}

		// Allow notifications to perform actions before creating the insert array (such as run a query to cache some data needed for all notifications)
		$notification = $this->get_notification_type_class($notification_type);
		$pre_create_data = $notification->pre_create_insert_array($data, $notify_users);
		unset($notification);

		// Go through each user so we can insert a row in the DB and then notify them by their desired means
		foreach ($notify_users as $user => $methods)
		{
			$notification = $this->get_notification_type_class($notification_type);

			$notification->user_id = (int) $user;

			// Store the creation array in our new rows that will be inserted later
			$new_rows[] = $notification->create_insert_array($data, $pre_create_data);

			// Users are needed to send notifications
			$user_ids = array_merge($user_ids, $notification->users_to_query());

			foreach ($methods as $method)
			{
				// setup the notification methods and add the notification to the queue
				if ($method) // blank means we just insert it as a notification, but do not notify them by any other means
				{
					if (!isset($notification_methods[$method]))
					{
						$notification_methods[$method] = $this->get_method_class($method);
					}

					$notification_methods[$method]->add_to_queue($notification);
				}
			}
		}

		// insert into the db
		$this->db->sql_multi_insert($this->notifications_table, $new_rows);

		// We need to load all of the users to send notifications
		$this->user_loader->load_users($user_ids);

		// run the queue for each method to send notifications
		foreach ($notification_methods as $method)
		{
			$method->notify();
		}
	}

	/**
	* Update a notification
	*
	* @param string|array $notification_type Type identifier or array of item types (only acceptable if the $data is identical for the specified types)
	* @param array $data Data specific for this type that will be updated
	*/
	public function update_notifications($notification_type, $data)
	{
		if (is_array($notification_type))
		{
			foreach ($notification_type as $type)
			{
				$this->update_notifications($type, $data);
			}

			return;
		}

		$notification = $this->get_notification_type_class($notification_type);

		// Allow the notifications class to over-ride the update_notifications functionality
		if (method_exists($notification, 'update_notifications'))
		{
			// Return False to over-ride the rest of the update
			if ($notification->update_notifications($data) === false)
			{
				return;
			}
		}

		$notification_type_id = $notification->get_notification_type_id($data);
		$update_array = $notification->create_update_array($data);

		$sql = 'UPDATE ' . $this->notifications_table . '
			SET ' . $this->db->sql_build_array('UPDATE', $update_array) . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND notification_type_id = " . (int) $notification_type_id;
		$this->db->sql_query($sql);
	}

	/**
	* Delete a notification
	*
	* @param string|array $notification_type Type identifier or array of item types (only acceptable if the $notification_type_id is identical for the specified types)
	* @param int|array $notification_type_id Identifier within the type (or array of ids)
	* @param array $data Data specific for this type that will be updated
	*/
	public function delete_notifications($notification_type, $notification_type_id)
	{
		if (is_array($notification_type))
		{
			foreach ($notification_type as $type)
			{
				$this->delete_notifications($type, $notification_type_id);
			}

			return;
		}

		$sql = 'DELETE FROM ' . $this->notifications_table . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND " . (is_array($notification_type_id) ? $this->db->sql_in_set('notification_type_id', $notification_type_id) : 'notification_type_id = ' . (int) $notification_type_id);
		$this->db->sql_query($sql);
	}

	/**
	* Get all of the subscription types
	*
	* @return array Array of item types
	*/
	public function get_subscription_types()
	{
		$subscription_types = array();

		foreach ($this->notification_types as $type_name => $data)
		{
			$type = $this->get_notification_type_class($type_name);

			if ($type instanceof phpbb_notification_type_interface && $type->is_available())
			{
				$options = array_merge(array(
					'id'		=> $type->get_type(),
					'lang'		=> 'NOTIFICATION_TYPE_' . strtoupper($type->get_type()),
					'group'		=> 'NOTIFICATION_GROUP_MISCELLANEOUS',
				), (($type::$notification_option !== false) ? $type::$notification_option : array()));

				$subscription_types[$options['group']][$options['id']] = $options;
			}
		}

		// Move Miscellaneous to the very last section
		if (isset($subscription_types['NOTIFICATION_GROUP_MISCELLANEOUS']))
		{
			$miscellaneous = $subscription_types['NOTIFICATION_GROUP_MISCELLANEOUS'];
			unset($subscription_types['NOTIFICATION_GROUP_MISCELLANEOUS']);
			$subscription_types['NOTIFICATION_GROUP_MISCELLANEOUS'] = $miscellaneous;
		}

		return $subscription_types;
	}

	/**
	* Get all of the subscription methods
	*
	* @return array Array of methods
	*/
	public function get_subscription_methods()
	{
		$subscription_methods = array();

		foreach ($this->notification_methods as $method_name => $data)
		{
			$method = $this->get_method_class($method_name);

			if ($method instanceof phpbb_notification_method_interface && $method->is_available())
			{
				$subscription_methods[$method_name] = array(
					'id'		=> $method->get_type(),
					'lang'		=> 'NOTIFICATION_METHOD_' . strtoupper($method->get_type()),
				);
			}
		}

		return $subscription_methods;
	}

	/**
	* Get global subscriptions (notification_type_id = 0)
	*
	* @param bool|int $user_id The user_id to add the subscription for (bool false for current user)
	*
	* @return array Subscriptions
	*/
	public function get_global_subscriptions($user_id = false)
	{
		$user_id = ($user_id === false) ? $this->user->data['user_id'] : $user_id;

		$subscriptions = array();

		foreach ($this->get_subscription_types() as $group_name => $types)
		{
			foreach ($types as $id => $type)
			{
				$sql = 'SELECT notification_method, notification_notify
					FROM ' . $this->user_notifications_table . '
					WHERE user_id = ' . (int) $user_id . "
						AND notification_type = '" . $this->db->sql_escape($id) . "'
						AND notification_type_id = 0";
				$result = $this->db->sql_query($sql);

				$row = $this->db->sql_fetchrow($result);
				if (!$row)
				{
					// No rows at all, default to ''
					$subscriptions[$id] = array('');
				}
				else
				{
					do
					{
						if (!$row['notification_notify'])
						{
							continue;
						}

						if (!isset($subscriptions[$id]))
						{
							$subscriptions[$id] = array();
						}

						$subscriptions[$id][] = $row['notification_method'];
					}
					while ($row = $this->db->sql_fetchrow($result));
				}

				$this->db->sql_freeresult($result);
			}
		}

		return $subscriptions;
	}

	/**
	* Add a subscription
	*
	* @param string $notification_type Type identifier of the subscription
	* @param int $notification_type_id The id of the item
	* @param string $method The method of the notification e.g. '', 'email', or 'jabber'
	* @param bool|int $user_id The user_id to add the subscription for (bool false for current user)
	*/
	public function add_subscription($notification_type, $notification_type_id = 0, $method = '', $user_id = false)
	{
		if ($method !== '')
		{
			$this->add_subscription($notification_type, $notification_type, '', $user_id);
		}

		$user_id = ($user_id === false) ? $this->user->data['user_id'] : $user_id;

		$sql = 'SELECT notification_notify
			FROM ' . $this->user_notifications_table . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND notification_type_id = " . (int) $notification_type_id . '
				AND user_id = ' .(int) $user_id . "
				AND notification_method = '" . $this->db->sql_escape($method) . "'";
		$this->db->sql_query($sql);
		$current = $this->db->sql_fetchfield('notification_notify');
		$this->db->sql_freeresult();

		if ($current === false)
		{
			$sql = 'INSERT INTO ' . $this->user_notifications_table . ' ' .
				$this->db->sql_build_array('INSERT', array(
					'notification_type'	   		=> $notification_type,
					'notification_type_id'		=> (int) $notification_type_id,
					'user_id'					=> (int) $user_id,
					'notification_method'		=> $method,
					'notification_notify'		=> 1,
				));
			$this->db->sql_query($sql);
		}
		else if (!$current)
		{
			$sql = 'UPDATE ' . $this->user_notifications_table . "
				SET notification_notify = 1
				WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
					AND notification_type_id = " . (int) $notification_type_id . '
					AND user_id = ' .(int) $user_id . "
					AND notification_method = '" . $this->db->sql_escape($method) . "'";
			$this->db->sql_query($sql);
		}
	}

	/**
	* Delete a subscription
	*
	* @param string $notification_type Type identifier of the subscription
	* @param int $notification_type_id The id of the item
	* @param string $method The method of the notification e.g. '', 'email', or 'jabber'
	* @param bool|int $user_id The user_id to add the subscription for (bool false for current user)
	*/
	public function delete_subscription($notification_type, $notification_type_id = 0, $method = '', $user_id = false)
	{
		$user_id = ($user_id === false) ? $this->user->data['user_id'] : $user_id;

		// If no method, make sure that no other notification methods for this item are selected before deleting
		if ($method === '')
		{
			$sql = 'SELECT COUNT(*) as num_notifications
				FROM ' . $this->user_notifications_table . "
				WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
					AND notification_type_id = " . (int) $notification_type_id . '
					AND user_id = ' .(int) $user_id . "
					AND notification_method <> ''
					AND notification_notify = 1";
			$this->db->sql_query($sql);
			$num_notifications = $this->db->sql_fetchfield('num_notifications');
			$this->db->sql_freeresult();

			if ($num_notifications)
			{
				return;
			}
		}

		$sql = 'UPDATE ' . $this->user_notifications_table . "
			SET notification_notify = 0
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'
				AND notification_type_id = " . (int) $notification_type_id . '
				AND user_id = ' .(int) $user_id . "
				AND notification_method = '" . $this->db->sql_escape($method) . "'";
		$this->db->sql_query($sql);

		if (!$this->db->sql_affectedrows())
		{
			$sql = 'INSERT INTO ' . $this->user_notifications_table . ' ' .
				$this->db->sql_build_array('INSERT', array(
					'notification_type'			=> $notification_type,
					'notification_type_id'		=> (int) $notification_type_id,
					'user_id'	   				=> (int) $user_id,
					'notification_method'		=> $method,
					'notification_notify'		=> 0,
				));
			$this->db->sql_query($sql);
		}
	}

	/**
	* Disable all notifications of a certain type
	*
	* This should be called when an extension which has notification types
	* is disabled so that all those notifications are hidden and do not
	* cause errors
	*
	* @param string $notification_type Type identifier of the subscription
	*/
	public function disable_notifications($notification_type)
	{
		$sql = 'UPDATE ' . $this->notification_types_table . "
			SET notification_type_enabled = 0
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'";
		$this->db->sql_query($sql);
	}

	/**
	* Purge all notifications of a certain type
	*
	* This should be called when an extension which has notification types
	* is purged so that all those notifications are removed
	*
	* @param string $notification_type Type identifier of the subscription
	*/
	public function purge_notifications($notification_type)
	{
		$sql = 'DELETE FROM ' . $this->notifications_table . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'";
		$this->db->sql_query($sql);

		$sql = 'DELETE FROM ' . $this->notification_types_table . "
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'";
		$this->db->sql_query($sql);
	}

	/**
	* Enable all notifications of a certain type
	*
	* This should be called when an extension which has notification types
	* that was disabled is re-enabled so that all those notifications that
	* were hidden are shown again
	*
	* @param string $notification_type Type identifier of the subscription
	*/
	public function enable_notifications($notification_type)
	{
		$sql = 'UPDATE ' . $this->notification_types_table . "
			SET notification_type_enabled = 1
			WHERE notification_type = '" . $this->db->sql_escape($notification_type) . "'";
		$this->db->sql_query($sql);
	}

	/**
	* Delete all notifications older than a certain time
	*
	* @param int $timestamp Unix timestamp to delete all notifications that were created before
	*/
	public function prune_notifications($timestamp)
	{
		$sql = 'DELETE FROM ' . $this->notifications_table . '
			WHERE notification_time < ' . (int) $timestamp;
		$this->db->sql_query($sql);
	}

	/**
	* Helper to get the notifications item type class and set it up
	*/
	public function get_notification_type_class($notification_type, $data = array())
	{
		$notification_type = (strpos($notification_type, 'notification.type.') === 0) ? $notification_type : 'notification.type.' . $notification_type;

		$item = $this->load_object($notification_type);

		$item->set_initial_data($data);

		return $item;
	}

	/**
	* Helper to get the notifications method class and set it up
	*/
	public function get_method_class($method_name)
	{
		$method_name = (strpos($method_name, 'notification.method.') === 0) ? $method_name : 'notification.method.' . $method_name;

		return $this->load_object($method_name);
	}

	/**
	* Helper to load objects (notification types/methods)
	*/
	protected function load_object($object_name)
	{
		return $this->phpbb_container->get($object_name);
	}
}
