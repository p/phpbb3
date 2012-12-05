<?php
/**
*
* @package testing
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

require_once dirname(__FILE__) . '/../../phpBB/includes/functions.php';
require_once dirname(__FILE__) . '/../../phpBB/includes/functions_messenger.php';

// must extend database test case as unique_id uses db connection
class phpbb_messenger_message_id_test extends phpbb_database_test_case
{
        protected $messenger;
        
	public function getDataSet()
	{
		return $this->createXMLDataSet(dirname(__FILE__) . '/../fixtures/empty.xml');
	}

        protected function setUp() {
                parent::setUp();
                
		global $db, $config;

		$db = $this->new_dbal();
		$config = new phpbb_config(array());
                
                $this->messenger = new messenger;
        }
        
	public function test_with_server_name()
	{
                $message_id = $this->messenger->generate_message_id();
                var_dump($message_id);
	}
}
