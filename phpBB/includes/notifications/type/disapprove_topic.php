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
* Post notifications class
* This class handles notifications for replies to a topic
*
* @package notifications
*/
class phpbb_notifications_type_disapprove_topic extends phpbb_notifications_type_approve_topic
{
	/**
	* Email template to use to send notifications
	*
	* @var string
	*/
	public $email_template = 'topic_disapproved';

	/**
	* Language key used to output the text
	*
	* @var string
	*/
	protected $language_key = 'NOTIFICATION_TOPIC_DISAPPROVED';

	/**
	* Get the type of notification this is
	* phpbb_notifications_type_
	*/
	public static function get_item_type()
	{
		return 'disapprove_topic';
	}

	/**
	* Get the HTML formatted title of this notification
	*
	* @return string
	*/
	public function get_title()
	{
		return $this->phpbb_container->get('user')->lang(
			$this->language_key,
			censor_text($this->get_data('topic_title')),
			$this->get_data('disapprove_reason')
		);
	}

	/**
	* Get the url to this item
	*
	* @return string URL
	*/
	public function get_url()
	{
		return '';
	}

	/**
	* Get email template variables
	*
	* @return array
	*/
	public function get_email_template_variables()
	{
		return array(
			'TOPIC_TITLE'		=> htmlspecialchars_decode(censor_text($this->get_data('topic_title'))),

			'REASON'			=> htmlspecialchars_decode($this->get_data('disapprove_reason')),
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
		$this->set_data('disapprove_reason', $post['disapprove_reason']);
		$this->time = time();

		return parent::create_insert_array($post);
	}
}
