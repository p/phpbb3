<?php
/**
*
* additional language entries for the language cache
*
* @package language
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* DO NOT CHANGE
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine

$language = array(
	"acp/language" => array(
		'LANGUAGE_CACHE_PURGED'		=> 'The cache for the language pack <strong>%s</strong> has been successful purged.',
		'LANGUAGE_CACHE_CREATED'	=> 'The cache for the language pack <strong>%s</strong> has been successful created.',
		'LANG_CACHE_PURGE'				=> 'Purge cache',
		'LANG_CACHE_CREATE'				=> 'Create cache',
	),
);

?>