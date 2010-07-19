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
				'replace' => '<strong>',
				'close' => '</strong>',
				'attributes' => array(),
				'children' => array(true, 'quote' => true, 'code' => true, 'list' => true),
				'parents' => array(true),
			),
			// The exact I BBcode from phpBB
			'i' => array(
				'replace' => '<em>',
				'close' => '</em>',
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
				'replace' => '<blockquote class="uncited"><div>',
				'replace_username' => '<blockquote><div><cite>{USERNAME} {L_WROTE}:</cite>',
				'close' => '</div></blockquote>',
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
				'replace' => '<dl class="codebox"><dt>{L_CODE}: <a href="#" onclick="selectCode(this); return false;">{L_SELECT_ALL_CODE}</a></dt><dd><code>',
				'close' => '</code></dd></dl>',
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
				'content_func' => array($this, 'img_tag'),
				'replace' => '<img src="',
				'close' => '" alt="{L_IMAGE}" />',
				'attributes' => array(
					'__' => array(
						'replace' => '',
					),
				),
				'children' => array(false),
				'parents' => array(true),
				'attribute_check' => array($this, 'img_check'),

			),

			'url' => array(
				'replace' => '<a href="{URL}" class="postlink">',
				'replace_func' => array($this, 'url_tag'),
				'close' => '',
				'close_func' => array($this, 'url_close'),
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
				'replace' => '<a href="mailto:{EMAIL}">',
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
				'replace' => '<span style="font-size: {_}px; line-height: 116%">',
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
				'replace' => '<object classid="clsid:D27CDB6E-AE6D-11CF-96B8-444553540000" codebase="http://active.macromedia.com/flash2/cabs/swflash.cab#version=5,0,0,0" width="{WIDTH}" height="{HEIGHT}"><param name="movie" value="{URL}" /><param name="play" value="false" /><param name="loop" value="false" /><param name="quality" value="high" /><param name="allowScriptAccess" value="never" /><param name="allowNetworking" value="internal" /><embed src="{URL}" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/shockwave/download/index.cgi?P1_Prod_Version=ShockwaveFlash" width="{WIDTH}" height="{HEIGHT}" play="false" loop="false" quality="high" allowscriptaccess="never" allownetworking="internal"></embed></object>',
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
				'content_func' => array($this, 'blank'),
				'parents' => array(true),
				'attribute_check' => array($this, 'flash_check'),
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

		foreach ($this->tags as &$tag)
		{
			$tag['replace'] = preg_replace('/{L_([A-Z_]+)}/e', "(!empty(\$user->lang['\$1'])) ? \$user->lang['\$1'] : ucwords(strtolower(str_replace('_', ' ', '\$1')))", $tag['replace']);
			$tag['close'] = preg_replace('/{L_([A-Z_]+)}/e', "(!empty(\$user->lang['\$1'])) ? \$user->lang['\$1'] : ucwords(strtolower(str_replace('_', ' ', '\$1')))", $tag['close']);
		}
		
//		$this->text_callback = 'strtoupper';
		parent::__construct();
	}

	public function first_pass($string)
	{
		$this->warn_msg = array();

		return parent::first_pass($string);
	}

	public function disable($tag)
	{
		unset($this->tags[$tag]);
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

		$error = false;

		if ($config['max_' . $this->mode . '_img_height'] || $config['max_' . $this->mode . '_img_width'])
		{

			if ($config['max_' . $this->mode . '_img_height'] && $config['max_' . $this->mode . '_img_height'] < $height)
			{
				$error = true;
				$this->warn_msg[] = sprintf($user->lang['MAX_FLASH_HEIGHT_EXCEEDED'], $config['max_' . $this->mode . '_img_height']);
			}

			if ($config['max_' . $this->mode . '_img_width'] && $config['max_' . $this->mode . '_img_width'] < $width)
			{
				$error = true;
				$this->warn_msg[] = sprintf($user->lang['MAX_FLASH_WIDTH_EXCEEDED'], $config['max_' . $this->mode . '_img_width']);
			}
		}

		return !$error && $this->path_not_in_domain($attributes['__']);
	}

 	protected function flash_tag(array $attributes = array(), array $definition = array())
	{
		list($width, $height) = explode(',', $attributes['_']);

		return str_replace(array('{URL}', '{WIDTH}', '{HEIGHT}'), array($attributes['__'], $width, $height), $definition['replace']);
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

	protected function img_check(array $attributes)
	{
		global $user, $config;

		$in = trim($attributes['__']);
		$error = false;

		$in = str_replace(' ', '%20', $in);

		if (!preg_match('#^' . get_preg_expression('url') . '$#i', $in) && !preg_match('#^' . get_preg_expression('www_url') . '$#i', $in))
		{
			return false;
		}

		// Try to cope with a common user error... not specifying a protocol but only a subdomain
		if (!preg_match('#^[a-z0-9]+://#i', $in))
		{
			$in = 'http://' . $in;
		}

		if ($config['max_' . $this->mode . '_img_height'] || $config['max_' . $this->mode . '_img_width'])
		{
			$stats = @getimagesize($in);

			if ($stats === false)
			{
				$error = true;
				$this->warn_msg[] = $user->lang['UNABLE_GET_IMAGE_SIZE'];
			}
			else
			{
				if ($config['max_' . $this->mode . '_img_height'] && $config['max_' . $this->mode . '_img_height'] < $stats[1])
				{
					$error = true;
					$this->warn_msg[] = sprintf($user->lang['MAX_IMG_HEIGHT_EXCEEDED'], $config['max_' . $this->mode . '_img_height']);
				}

				if ($config['max_' . $this->mode . '_img_width'] && $config['max_' . $this->mode . '_img_width'] < $stats[0])
				{
					$error = true;
					$this->warn_msg[] = sprintf($user->lang['MAX_IMG_WIDTH_EXCEEDED'], $config['max_' . $this->mode . '_img_width']);
				}
			}
		}

		return !$error && $this->path_not_in_domain($in);
	}

	protected function img_tag($in)
	{
		$in = trim($in);
		$in = str_replace(' ', '%20', $in);

		// Try to cope with a common user error... not specifying a protocol but only a subdomain
		if (!preg_match('#^[a-z0-9]+://#i', $in))
		{
			$in = 'http://' . $in;
		}

		return $in;
	}

 	protected function url_tag(array $attributes = array(), array $definition = array())
	{
		$url = isset($attributes['_']) ? $attributes['_'] : $attributes['__'];

		$url = str_replace("\r\n", "\n", str_replace('\"', '"', trim($url)));
		$url = str_replace(' ', '%20', $url);

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

		return str_replace('{URL}', $url, $definition['replace']);
	}

	protected function url_children(array $attributes)
	{
		if (isset($attributes['_']))
		{
			return array(true, 'url' => true, 'email' => true, '__url' => true, '__smiley' => true);
		}
		return array(false);
	}

	protected function url_close(array $attributes = array())
	{
		$this->num_urls++;
		return '</a>';
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

		$email = str_replace("\r\n", "\n", str_replace('\"', '"', trim($email)));

		return str_replace('{EMAIL}', $email, $definition['replace']);
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

	protected function quote_open(array $attributes = array(), array $definition = array())
	{
		static $quote_parser;
		global $user;

		if (!isset($attributes['_']))
		{
			return $definition['replace'];
		}

		if (!isset($quote_parser))
		{
			$quote_parser = new quote_bbcode_parser();
		}

		$value = $quote_parser->second_pass($quote_parser->first_pass($attributes['_']));

		$definition['replace_username'] = preg_replace('/{L_([A-Z_]+)}/e', "(!empty(\$user->lang['\$1'])) ? \$user->lang['\$1'] : ucwords(strtolower(str_replace('_', ' ', '\$1')))", $definition['replace_username']);

		return str_replace('{USERNAME}', $value, $definition['replace_username']);
	}

	protected function code_open(array $attributes, array $definition = array())
	{
		$this->php_code = isset($attributes['_']) && strtolower($attributes['_']) == 'php';

		return $definition['replace'];
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

	protected function blank($in)
	{
		return '';
	}

	/**
	* Check if url is pointing to this domain/script_path/php-file
	*
	* @param string $url the url to check
	* @return false if the url is pointing to this domain/script_path/php-file, true if not
	*
	* @access private
	*/
	protected function path_not_in_domain($url)
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