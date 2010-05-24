<?php
/**
*
* @package phpBB3
* @version $Id$
* @copyright (c) 2010 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
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
* Language class
* @package phpBB3
*
* This class adds a cache for language files. It uses two layers
*
* 1. A cache for _all_ language definition. This layer is constructed from
*    all files in the corresponding language directory. The serialized array
*    has the following structure:
*
*    $array = array(
*      'name' => '<language iso name>',
*      'path' => '<(real) path to the language files>'
*      'help'	=> array(
*        '<langauge file>' => array(
*          <help array definition>
*        );
*      );
*      'lang'	=> array(
*        '<langauge file>' => array(
*          '<key>' => '<value>',
*        );
*      );
*    );
*
*    The cache IDs are defined as '_lang_<lang_name>_{md5(<(real) path>)}'.
*    An md5 hash of the (real) path to the language files is used to support
*    different language paths (like for the Support Toolkit).
*
*    To use this, the language file must only check for IN_PHPBB! If other
*    checks are used the creation of the cache will fail and you will see a
*    blank page.
*
* 2. A cache for each language file.
*
*    The cache ID '_lang_<lang_name>_path_{md5(<(real)path>)}' is used for
*    standard language file. The structure of the serialized array is:
*
*    $array = array(
*      'file' => '<language file>',
*      'name' => '<language iso name>',
*      'used' => array(<all used languages>),
*      'path' => '<(real) path to the language files>'
*      'data'	=> array(
*        '<key>' => '<value>',
*      );
*    );
*
*    The data array is composed of
*    (1) the English language file as standard definition
*    (2) the board default language as backup language
*    (3) the user language.
*
*    The language cache define by 1) is used here.
*
*    The cache ID '_lang_<lang_name>_help_{md5(<path>)}' is used for help
*    data. It is only available if the corresponding file is present in the
*    requested language. The serialized array is similar to the above
*    defined array:
*
*    $array = array(
*      'file' => '<language file>',
*      'name' => '<language iso name>',
*      'used' => array(<used language>),
*      'path' => '<(real) path to the language files>'
*      'help'	=> array(
*        <help array definition>
*      );
*    );
*
* For the language files two different formats can be used. On the one hand
* the array $lang can be extended (old format), on the other hand an array
* $language can be defined. It will be merged into the $array['lang] defined
* for the first cache layer.
*
* $language = array(
*   '<file 1>' => array(
*     '<key>' => '<value>',
*   ),
*   '<file 2>' => array(
*     '<key>' => '<value>',
*   );
* );
*
* Using this definition, mods can inset one file into the directory
* ./language/<lang name>/mods/ and the definitions will be included into the
* corresponding files.
*/

define('LANG_CACHE_TYPE_ALL', 1);
define('LANG_CACHE_TYPE_FILES', 2);
define('LANG_CACHE_TYPE_HELP', 3);
define('LANG_CACHE_TYPE_LANG', 4);

class language {
	// internal memory (cache) for loaded language files
	var $memory = array();

	/**
	* (Re)create the laguage cache for the given language
	* @param string $lang_name language name
	*/
	function lang_cache_create($lang_name)
	{
		$lang_data = &$this->read_lang($lang_name);

		// create all help data
		foreach (array_keys($lang_data['help']) as $file)
		{
			$this->get_help($lang_name, $file, true);
		}

		// create all language data
		foreach (array_keys($lang_data['lang']) as $file)
		{
			$this->get_lang($lang_name, $file, true);
		}
	}

	/**
	* Purge the laguage cache; as it is not possible to purge only selected data purge the entire cache
	*/
	function lang_cache_purge()
	{
		global $cache;

		// clear all language cache data
		$cache->purge();
		unset($this->memory);
		$this->memory = array();
	}

	/**
	* Get an array of all available language files
	*/
	function lang_files()
	{
		global $cache, $config, $user;

		// try in the following order: user language, board default language, English language
		$lang_to_test = array_unique(array($user->lang_name, basename($config['default_lang']), 'en'));

		$lang_files = array();
		foreach ($lang_to_test as $lang_test)
		{
			if (($files = $cache->get($this->generate_cache_id($this->get_lang_realpath(), LANG_CACHE_TYPE_FILES, $lang_test))) === false)
			{
				$lang_cache = $this->get_lang($lang_test, true);
				$files = array_merge(array_keys($lang_cache['help'], array_keys($lang_cache['lang'])));
			}

			$lang_files = array_merge($lang_files, $files);
		}

		return $lang_files;
	}

	/**
	* Get language definition (used by session.php:set_lang())
	* @param string $lang_name requested language
	* @param string $lang_file path for the language file
	* @param bool $use_help is this a help definition
	*/
	function get_lang($lang_name, $lang_file)
	{
		global $cache;

		$cache_id = $this->generate_cache_id($this->get_lang_realpath(), LANG_CACHE_TYPE_LANG, $lang_name, $lang_file);
		if (($lang_cache = $cache->get($cache_id)) === false)
		{
			global $config;

			$lang_default = basename($config['default_lang']);

			$lang_cache['file'] = $lang_file;
			$lang_cache['name'] = $lang_name;
			$lang_cache['used'] = array();
			$lang_cache['path'] = $this->get_lang_realpath();
			$lang_cache['data'] = array();

			// first load the English language as fallback
			$lang_data = &$this->read_lang('en');
			if (isset($lang_data['lang'][$lang_file]))
			{
				$lang_cache['data'] = array_merge_replace_recursive($lang_cache['data'], $lang_data['lang'][$lang_file]);
				$lang_cache['used'][] = 'en';
			}

			// merge it with the board default language
			if ($lang_name != 'en' && $lang_default != 'en')
			{
				$lang_data = &$this->read_lang($lang_default);
				if (isset($lang_data['lang'][$lang_file]))
				{
					$lang_cache['data'] = array_merge_replace_recursive($lang_cache['data'], $lang_data['lang'][$lang_file]);
					$lang_cache['used'][] = $lang_default;
				}
			}

			// now load the user language
			if ($lang_name != $lang_default)
			{
				$lang_data = &$this->read_lang($lang_name);
				if (isset($lang_data['lang'][$lang_file]))
				{
					$lang_cache['data'] = array_merge_replace_recursive($lang_cache['data'], $lang_data['lang'][$lang_file]);
					$lang_cache['used'][] = $lang_name;
				}
			}

			if (empty($lang_cache['used']))
			{
				// fallback: try to load the language file directly
				global $user, $phpEx;

				add_log('admin', 'LOG_LANGUAGE_CACHE_MISS', $lang_file);

				// try in the following order: user language, board default language, English language
				$lang_to_test = array_unique(array($lang_name, $lang_default, 'en'));

				foreach ($lang_to_test as $lang_test)
				{
					$lang_filename = $user->lang_path . "$lang_test/$lang_file.$phpEx";

					$lang = array();
					// Do not suppress error if in DEBUG_EXTRA mode
					$include_result = defined('DEBUG_EXTRA') ? include($lang_filename) : @include($lang_filename);
					if ($include_result !== false)
					{
						// found a language definition file
						$lang_cache['file'] = $lang_filename;
						$lang_cache['used'][] = $lang_test;
						$lang_cache['data'] = &$lang;
						break;
					}
				}

				if (empty($lang_cache['used']))
				{
					// no language file found
					add_log('admin', 'LOG_LANGUAGE_NO_FILE', $lang_file);
					trigger_error('Language file “' . $lang_file . '“ not found (testet languages: ' . implode(', ', $lang_to_test) . ').', E_USER_ERROR);
				}
			}

			$cache->put($cache_id, $lang_cache);
			$cache->save();
		}

		return $lang_cache['data'];
	}

	/**
	* Get help definition (used by session.php:set_lang())
	* @param string $lang_name requested language
	* @param string $lang_file path for the language help file
	*/
	function get_help($lang_name, $lang_file)
	{
		global $cache;

		$cache_id = $this->generate_cache_id($this->get_lang_realpath(), LANG_CACHE_TYPE_HELP, $lang_name, $lang_file);
		if (($lang_cache = $cache->get($cache_id)) === false)
		{
			global $config;

			$lang_default = basename($config['default_lang']);

			$lang_cache['file'] = $lang_file;
			$lang_cache['name'] = $lang_name;
			$lang_cache['used'] = array();
			$lang_cache['path'] = $this->get_lang_realpath();

			// try in the following order: user language, board default language, English language
			$lang_to_test = array_unique(array($lang_name, $lang_default, 'en'));

			foreach ($lang_to_test as $lang_test)
			{
				$lang_data = &$this->read_lang($lang_test);
				if (isset($lang_data['help'][$lang_file]))
				{
					$lang_cache['used'][] = $lang_test;
					$lang_cache['data'] = $lang_data['help'][$lang_file];
					break;
				}
			}

			if (!isset($lang_cache['data']))
			{
				// fallback: try to load the language file directly
				global $user, $phpEx;

				add_log('admin', 'LOG_LANGUAGE_CACHE_MISS', $lang_file);

				// try in the following order: user language, board default language, English language
				$lang_to_test = array_unique(array($lang_name, $lang_default, 'en'));

				foreach ($lang_to_test as $lang_test)
				{
					if (strpos($lang_file, '/') !== false)
					{
						$lang_filename = $user->lang_path . "$lang_test/" . substr($lang_file, 0, stripos($lang_file, '/') + 1) . 'help_' . substr($lang_file, stripos($lang_file, '/') + 1) . '.' . $phpEx;
					}
					else
					{
						$lang_filename = $user->lang_path . "$lang_test/help_$lang_file.$phpEx";
					}

					// Do not suppress error if in DEBUG_EXTRA mode
					$include_result = defined('DEBUG_EXTRA') ? include($lang_filename) : @include($lang_filename);
					if ($include_result !== false && isset($help))
					{
						// found a language definition file
						$lang_cache['file'] = $lang_filename;
						$lang_cache['used'][] = $lang_test;
						$lang_cache['data'] = &$help;
						break;
					}
				}

				if (empty($lang_cache['used']))
				{
					// no language file found
					add_log('admin', 'LOG_LANGUAGE_NO_FILE', $lang_file);
					trigger_error('Help language file “' . $lang_file . '“ not found (testet languages: ' . implode(', ', $lang_to_test) . ').', E_USER_ERROR);
				}
			}

			$cache->put($cache_id, $lang_cache);
			$cache->save();
		}

		return $lang_cache['data'];
	}

	/**
	* get the real path to the language definitions
	* @access private
	*/
	private function get_lang_realpath()
	{
		global $user;

		// use realpath here to generate identical md5 hashes for the forum ("./language/<file>") and the acp ("./../language<file>")
		return phpbb_realpath($user->lang_path);
	}

	/**
	* Read all language files for the given language into the cache
	* @access private
	*/
	private function read_lang($lang_name, $force = false)
	{
		global $cache, $phpEx;

		$lang_realpath = $this->get_lang_realpath();
		$cache_id = $this->generate_cache_id($lang_realpath, LANG_CACHE_TYPE_ALL, $lang_name);

		if (isset($this->memory[$lang_name][$cache_id]))
		{
			return $this->memory[$lang_name][$cache_id];
		}

		if ($force || ($lang_cache = $cache->get($cache_id)) === false)
		{
			$lang_cache = array();
			$lang_cache['name'] = $lang_name;
			$lang_cache['path'] = $lang_realpath;

			$lang_files = $this->get_lang_files("$lang_realpath/$lang_name", '');
			ksort($lang_files);

			// save file list
			$cache->put($this->generate_cache_id($lang_realpath, LANG_CACHE_TYPE_FILES, $lang_name), $lang_files);
			$cache->save();

			foreach($lang_files as $file)
			{
				if (strpos($file, 'help_') !== false)
				{
					// special case for help files
					$file_help = substr($file, 0, stripos($file, 'help_')) . substr($file, stripos($file, 'help_') + 5);
					$help = array();
					include("$lang_realpath/$lang_name/$file.$phpEx");
					$lang_cache['help'][$file_help] = $help;
					unset($help);

					continue;
				}

				$lang = array();
				include("$lang_realpath/$lang_name/$file.$phpEx");

				if (!empty($lang))
				{
					// old style definition
					if (isset($lang_cache['lang'][$file]))
					{
						$lang_cache['lang'][$file] = array_merge_replace_recursive($lang_cache['lang'][$file], $lang);
					}
					else
					{
						$lang_cache['lang'][$file] = $lang;
					}
					unset($lang);
					continue;
				}

				if (isset($language) && is_array($language))
				{
					// new style defintion
					foreach ($language as $lang_file => $lang_data)
					{
						if (isset($lang_cache['lang'][$lang_file]))
						{
							$lang_cache['lang'][$lang_file] = array_merge_replace_recursive($lang_cache['lang'][$lang_file], $lang_data);
						}
						else
						{
							$lang_cache['lang'][$lang_file] = $lang_data;
						}
					}
					unset($language);
					continue;
				}
			}

			$cache->put($cache_id, $lang_cache);
			$cache->save();
		}

		// save the data in the internal cache
		$this->memory[$lang_name][$cache_id] = &$lang_cache;

		return $lang_cache;
	}

	/**
	* Get all language files for the given language
	* (similar to functions_admin.php:filelist())
	* @access private
	*/
	private function get_lang_files($lang_dir, $lang_realpath)
	{
		global $phpEx;

		$lang_files = array();

		$dir = @opendir("$lang_dir/$lang_realpath");
		if (!$dir)
		{
			return $lang_files;
		}

		while (($entry = readdir($dir)) !== false)
		{
			if ($entry == "." || $entry == "..")
			{
				continue;
			}

			$file = empty($lang_realpath) ? $entry : "$lang_realpath/$entry";
			if (is_dir($lang_dir . '/' . $file))
			{
				$lang_files = array_merge($lang_files, $this->get_lang_files($lang_dir, $file));
				continue;
			}

			if (!is_file("$lang_dir/$file") || substr($file, -4) != ".$phpEx")
			{
				continue;
			}

			$lang_files[] = substr($file, 0, -4);
		}
		closedir($dir);

		return $lang_files;
	}

	/**
	* Generate a cache id
	* @access private
	*/
	private function generate_cache_id($lang_realpath, $cache_type, $lang_name, $lang_file = '')
	{
		switch ($cache_type)
		{
			case LANG_CACHE_TYPE_ALL:
				return '_lang_' . $lang_name . '_' . md5($lang_realpath);
			case LANG_CACHE_TYPE_FILES:
				return '_lang_' . $lang_name . '_files_' . md5($lang_realpath);
			case LANG_CACHE_TYPE_HELP:
				return '_lang_' . $lang_name . "_help_" . md5($lang_realpath . '/' . $lang_file);
			case LANG_CACHE_TYPE_LANG:
				return '_lang_' . $lang_name . "_path_" . md5($this->get_lang_realpath() . '/' . $lang_file);
			default:
				trigger_error('Unknown language cache type “' . $cache_type . '“.', E_USER_ERROR);
		}
	}
}
