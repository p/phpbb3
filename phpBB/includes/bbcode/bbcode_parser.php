<?php
/**
*
* @package phpBB3
* @version $Id$
* @copyright (c) 2005 phpBB Group
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
 * The phpBB version of the BBCode parser
 *
 */
class phpbb_bbcode_parser extends phpbb_bbcode_parser_base
{
	private $list_stack = array();
	private $php_code = false;
	protected $tags = array();
	public $warn_msg = array();
	public $mode = false;

	public function __construct()
	{
		global $db, $user;

		$this->tags = array(
			// The exact B BBcode from phpBB
			'b' => array(
				'replace' => '<span style="font-weight: bold">',
				'close' => '</span>',
				'attributes' => array(),
				'children' => array(true, 'quote' => true, 'code' => true, 'list' => true),
				'parents' => array(true),
			),
			// The exact I BBcode from phpBB
			'i' => array(
				'replace' => '<span style="font-style: italic">',
				'close' => '</span>',
				'attributes' => array(),
				'children' => array(true, 'quote' => true, 'code' => true, 'list' => true),
				'parents' => array(true),
			),
			// The exact U BBcode from phpBB
			'u' => array(
				'replace' => '<span style="text-decoration: underline">',
				'close' => '</span>',
				'attributes' => array(),
				'children' => array(true, 'quote' => true, 'code' => true, 'list' => true),
				'parents' => array(true),
			),

			// Quote tag attempt.
			'quote' => array(
				//'replace' => '<div class="quotetitle">{_}</div><div class="quotecontent">',
				'close' => '</div>',
				'attributes' => array(
					'_' => array(
						'replace' => '',
					),
				),
				'replace_func' => array($this, 'quote_open'),
				'children' => array(true),
				'parents' => array(true),
			),

			// code tag (without the =php functionality)
			'code' => array(
				'close' => '</div>',
				'replace_func' => array($this, 'code_open'),
				'content_func' => array($this, 'code_content'),
				'attributes' => array(
					'_' => array(
						'replace' => '',
					),
				),
				'children' => array(false),
				'parents' => array(true),
			),

			// list tag
			'list' => array(
				'replace' => '',
				'replace_func' => array($this, 'list_open'),
				'close' => '',
				'close_func' => array($this, 'list_close'),
				'attributes' => array(
					'_' => array(
						'replace' => '',
					),
				),
				'children' => array(false, '*' => true),
				'parents' => array(true),
			),

			'*' => array(
				'replace' => '<li>',
				'close' => '</li>',
				'close_shadow' => true,
				'attributes' => array(),
				'children' => array(true, '*' => true),
				'parents' => array(false, 'list' => true),
			),

			// Almost exact img tag from phpBB...
			'img' => array(
				'replace' => '<img alt="Image" src="',
				'close' => '" />',
				'attributes' => array(
					'__' => array(
						'replace' => '%s',
					),
				),
				'children' => array(false),
				'parents' => array(true),

			),

			'url' => array(
				'replace' => '',
				'replace_func' => array($this, 'url_tag'),
				'close' => '</a>',
				'attributes' => array(
					// The replace value is not important empty because the replace_func handles this.
					'_' => array(
						'replace' => ''
					),
					'__' => array(
						'replace' => ''
					),
				),
				// If the _ attribute is used the value corresponding to 'children' will be changed to array(true, array('url' => true)).
				'children_func' => array($this, 'url_children'),
				'parents' => array(true),
				'attribute_check' => array($this, 'url_check'),

			),

			'email' => array(
				'replace' => '',
				'replace_func' => array($this, 'email_tag'),
				'close' => '</a>',
				'attributes' => array(
					// The replace value is not important empty because the replace_func handles this.
					'_' => array(
						'replace' => ''
					),
					'__' => array(
						'replace' => ''
					),
				),
				// If the _ attribute is used the value corresponding to 'children' will be changed to array(true, array('url' => true)).
				'children_func' => array($this, 'email_children'),
				'parents' => array(true),
				'attribute_check' => array($this, 'email_check'),

			),

			'color' => array(
				'replace' => '<span style="color: {_}">',
				'close' => '</span>',
				'attributes' => array(
					'_' => array(
						'replace' => '%s',
						'required' => true
					),
				),
				'children' => array(true, 'color' => true),
				'parents' => array(true),
			),

			'size' => array(
				'replace' => '<span style="font-size: {_}px; line-height: normal">',
				'close' => '</span>',
				'attributes' => array(
					'_' => array(
						'replace' => '%s',
						'required' => true
					),
				),
				'children' => array(true, 'size' => true),
				'parents' => array(true),
				'attribute_check' => array($this, 'size_check'),
			),

			// FLASH tag implementation attempt.
			'flash' => array(
				'replace' => '',
				'replace_func' => array($this, 'flash_tag'),
				'close' => false,
				'attributes' => array(
					'_' => array(
						'replace' => '',
						'required' => true,
					),
					'__' => array(
						'replace' => '',
						'required' => true,
					),
				),
				'children' => array(false),
				'parents' => array(true),
				'attribute_check' => array($this, 'flash_check'),
			),

			// The spoiler tag from area51.phpbb.com :p
			'spoiler' => array(
				'replace' => '<span class="quotetitle"><b>Spoiler:</b></span><span style="background-color:white;color:white;">',
				'close' => '</span>',
				'attributes' => array(),
				'children' => array(false),
				'parents' => array(true),
			),
			// a noparse tag
			'noparse' => array(
				'replace' => '',
				'close' => '',
				'attributes' => array(),
				'children' => array(false),
				'parents' => array(true),
			),
		);

		// See if the smilies array has already been filled on an earlier invocation
		$match = $replace = array();

		// NOTE: obtain_* function? chaching the table contents?

		// For now setting the ttl to 10 minutes
		switch ($db->sql_layer)
		{
			case 'mssql':
			case 'mssql_odbc':
			case 'mssqlnative':
				$sql = 'SELECT *
					FROM ' . SMILIES_TABLE . '
					ORDER BY LEN(code) DESC';
			break;

			case 'firebird':
				$sql = 'SELECT *
					FROM ' . SMILIES_TABLE . '
					ORDER BY CHAR_LENGTH(code) DESC';
			break;

			// LENGTH supported by MySQL, IBM DB2, Oracle and Access for sure...
			default:
				$sql = 'SELECT *
					FROM ' . SMILIES_TABLE . '
					ORDER BY LENGTH(code) DESC';
			break;
		}
		$result = $db->sql_query($sql, 600);

		while ($row = $db->sql_fetchrow($result))
		{
			if (empty($row['code']))
			{
				continue;
			}

			$this->smilies[$row['code']] = '<!-- s' . $row['code'] . ' --><img src="{SMILIES_PATH}/' . $row['smiley_url'] . '" alt="' . $row['code'] . '" title="' . $row['emotion'] . '" /><!-- s' . $row['code'] . ' -->';
		}
		$db->sql_freeresult($result);

		//$this->smilies = array(
		//	':)' => '<img src="http://area51.phpbb.com/phpBB/images/smilies/icon_e_smile.gif" />',
		//	':(' => '<img src="http://area51.phpbb.com/phpBB/images/smilies/icon_e_sad.gif" />',
		//);
		
//		$this->text_callback = 'strtoupper';
		parent::__construct();
	}

	public function first_pass($string)
	{
		$this->warn_msg = array();

		return parent::first_pass($string);
	}

 	protected function flash_check(array $attributes)
	{
		global $user, $config;

		$attr = $attributes['_'];

		if (!preg_match('#^([0-9]+),([0-9]+)$#', $attr))
		{
			return false;
		}

		list($width, $height) = explode(',', $attr);

		if ($width <= 0 || $height <= 0)
		{
			return false;
		}

		if ($config['max_' . $this->mode . '_img_height'] || $config['max_' . $this->mode . '_img_width'])
		{

			if ($config['max_' . $this->mode . '_img_height'] && $config['max_' . $this->mode . '_img_height'] < $height)
			{
				$this->warn_msg[] = sprintf($user->lang['MAX_FLASH_HEIGHT_EXCEEDED'], $config['max_' . $this->mode . '_img_height']);

				return false;
			}

			if ($config['max_' . $this->mode . '_img_width'] && $config['max_' . $this->mode . '_img_width'] < $width)
			{
				$this->warn_msg[] = sprintf($user->lang['MAX_FLASH_WIDTH_EXCEEDED'], $config['max_' . $this->mode . '_img_width']);

				return false;
			}
		}

		return $this->path_not_in_domain($attributes['__']);
	}

 	protected function flash_tag(array $attributes = array(), array $definition = array())
	{
		list($width, $height) = explode(',', $attributes['_']);
		$width = " width=\"$width\"";
		$height = " height=\"$height\"";

		return '<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=7,0,19,0"' . $width . $height . '>
<param name="movie" value="' . $attributes['__'] . '" />
<param name="quality" value="high" />
<embed src="' . $attributes['__'] . '" quality="high" pluginspage="http://www.macromedia.com/go/getflashplayer" type="application/x-shockwave-flash"' . $width . $height . '>
</embed>
</object>';
	}

 	protected function size_check(array $attributes)
	{
		global $user, $config;

		$stx = $attributes['_'];

		if (!preg_match('#^[\-\+]?\d+$#', $stx))
		{
			return false;
		}

		if ($config['max_' . $this->mode . '_font_size'] && $config['max_' . $this->mode . '_font_size'] < $stx)
		{
			$this->warn_msg[] = sprintf($user->lang['MAX_FONT_SIZE_EXCEEDED'], $config['max_' . $this->mode . '_font_size']);

			return false;
		}

		// Do not allow size=0
		if ($stx <= 0)
		{
			return false;
		}

		return true;
	}

	protected function url_check(array $attributes)
	{
		$url = isset($attributes['_']) ? $attributes['_'] : $attributes['__'];

		$url = str_replace("\r\n", "\n", str_replace('\"', '"', trim($url)));
		$url = str_replace(' ', '%20', $url);

		// Checking urls
		return preg_match('#^' . get_preg_expression('url') . '$#i', $url) ||
			preg_match('#^' . get_preg_expression('www_url') . '$#i', $url) ||
			preg_match('#^' . preg_quote($this->local_url, '#') . get_preg_expression('relative_url') . '$#i', $url);
	}

 	protected function url_tag(array $attributes = array(), array $definition = array())
	{
		$url = isset($attributes['_']) ? $attributes['_'] : $attributes['__'];

		// if there is no scheme, then add http schema
		if (!preg_match('#^[a-z][a-z\d+\-.]*:/{2}#i', $url))
		{
			$url = 'http://' . $url;
		}

		// Is this a link to somewhere inside this board? If so then remove the session id from the url
		if (strpos($url, $this->local_url) !== false && strpos($url, 'sid=') !== false)
		{
			$url = preg_replace('/(&amp;|\?)sid=[0-9a-f]{32}&amp;/', '\1', $url);
			$url = preg_replace('/(&amp;|\?)sid=[0-9a-f]{32}$/', '', $url);
			$url = append_sid($url);
		}

		return '<a href="' . $url . '">';
	}

	protected function url_children(array $attributes)
	{
		$this->num_urls++;
		if (isset($attributes['_']))
		{
			return array(true, 'url' => true, 'email' => true, '__url' => true, '__smiley' => true);
		}
		return array(false);
	}

	protected function email_check(array $attributes)
	{
		$email = isset($attributes['_']) ? $attributes['_'] : $attributes['__'];

		$email = str_replace("\r\n", "\n", str_replace('\"', '"', trim($email)));

		return preg_match('/^' . get_preg_expression('email') . '$/i', $email);
	}

 	protected function email_tag(array $attributes = array(), array $definition = array())
	{
		$email = isset($attributes['_']) ? $attributes['_'] : $attributes['__'];

		return '<a href="mailto:' . $email . '">';
	}

	protected function email_children(array $attributes)
	{
		if (isset($attributes['_']))
		{
			return array(true, 'url' => true, 'email' => true, '__url' => true, '__smiley' => true);
		}
		return array(false);
	}

 	protected function list_open(array $attributes = array(), array $definition = array())
	{
		if (isset($attributes['_']))
		{
			return '<ol style="list-style-type: ' . $attributes['_'] . '">';
		}
		return '<ul>';
	}

	protected function list_close(array $attributes = array())
	{
		if (isset($attributes['_']))
		{
			return '</ol>';
		}
		return '</ul>';
	}

	protected function quote_open(array $attributes)
	{
		static $quote_parser;

		if (!isset($attributes['_']))
		{
			return '<div class="quotecontent">';
		}

		if (!isset($quote_parser))
		{
			$quote_parser = new quote_bbcode_parser();
		}

		$value = $quote_parser->second_pass($quote_parser->first_pass($attributes['_']));

		return '<div class="quotetitle">' . $value . ' wrote: </div><div class="quotecontent">';
	}

	protected function code_open(array $attributes)
	{
		$this->php_code = isset($attributes['_']) && strtolower($attributes['_']) == 'php';

		return '<div class="codetitle"><b>Code:</b></div><div class="codecontent">';
	}

	protected function code_content($code)
	{
		if (!$this->php_code)
		{
			return $code;
		}

		$remove_tags = false;

		$str_from = array('&lt;', '&gt;', '&#91;', '&#93;', '&#46;', '&#58;', '&#058;');
		$str_to = array('<', '>', '[', ']', '.', ':', ':');

		$code = str_replace($str_from, $str_to, $code);

		if (!preg_match('/\<\?.*?\?\>/is', $code))
		{
			$remove_tags = true;
			$code = "<?php $code ?>";
		}

		$conf = array('highlight.bg', 'highlight.comment', 'highlight.default', 'highlight.html', 'highlight.keyword', 'highlight.string');
		foreach ($conf as $ini_var)
		{
			@ini_set($ini_var, str_replace('highlight.', 'syntax', $ini_var));
		}

		// Because highlight_string is specialcharing the text (but we already did this before), we have to reverse this in order to get correct results
		$code = htmlspecialchars_decode($code);
		$code = highlight_string($code, true);

		$str_from = array('<span style="color: ', '<font color="syntax', '</font>', '<code>', '</code>','[', ']', '.', ':');
		$str_to = array('<span class="', '<span class="syntax', '</span>', '', '', '&#91;', '&#93;', '&#46;', '&#58;');

		if ($remove_tags)
		{
			$str_from[] = '<span class="syntaxdefault">&lt;?php </span>';
			$str_to[] = '';
			$str_from[] = '<span class="syntaxdefault">&lt;?php&nbsp;';
			$str_to[] = '<span class="syntaxdefault">';
		}

		$code = str_replace($str_from, $str_to, $code);
		$code = preg_replace('#^(<span class="[a-z_]+">)\n?(.*?)\n?(</span>)$#is', '$1$2$3', $code);

		if ($remove_tags)
		{
			$code = preg_replace('#(<span class="[a-z]+">)?\?&gt;(</span>)#', '$1&nbsp;$2', $code);
		}

		$code = preg_replace('#^<span class="[a-z]+"><span class="([a-z]+)">(.*)</span></span>#s', '<span class="$1">$2</span>', $code);
		$code = preg_replace('#(?:\s++|&nbsp;)*+</span>$#u', '</span>', $code);

		// remove newline at the end
		if (!empty($code) && substr($code, -1) == "\n")
		{
			$code = substr($code, 0, -1);
		}

		return $code;
	}

	/**
	* Check if url is pointing to this domain/script_path/php-file
	*
	* @param string $url the url to check
	* @return false if the url is pointing to this domain/script_path/php-file, true if not
	*
	* @access private
	*/
	function path_not_in_domain($url)
	{
		global $config, $phpEx, $user;

		if ($config['force_server_vars'])
		{
			$check_path = $config['script_path'];
		}
		else
		{
			$check_path = ($user->page['root_script_path'] != '/') ? substr($user->page['root_script_path'], 0, -1) : '/';
		}

		// Is the user trying to link to a php file in this domain and script path?
		if (strpos($url, ".{$phpEx}") !== false && strpos($url, $check_path) !== false)
		{
			$server_name = $user->host;

			// Forcing server vars is the only way to specify/override the protocol
			if ($config['force_server_vars'] || !$server_name)
			{
				$server_name = $config['server_name'];
			}

			// Check again in correct order...
			$pos_ext = strpos($url, ".{$phpEx}");
			$pos_path = strpos($url, $check_path);
			$pos_domain = strpos($url, $server_name);

			if ($pos_domain !== false && $pos_path >= $pos_domain && $pos_ext >= $pos_path)
			{
				// Ok, actually we allow linking to some files (this may be able to be extended in some way later...)
				if (strpos($url, '/' . $check_path . '/download/file.' . $phpEx) !== false)
				{
					return true;
				}

				return false;
			}
		}

		return true;
	}
}

/**
 * A BBcode parser for quote usernames
 *
 */
class quote_bbcode_parser extends phpbb_bbcode_parser
{
	public function __construct()
	{
		parent::__construct();
		$this->tags = array('b' => $this->tags['b'], 'i' => $this->tags['i'], 'u' => $this->tags['u'], 'color' => $this->tags['color'], 'url' => $this->tags['url']);
	}
}