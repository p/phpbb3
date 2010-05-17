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
						'replace' => '',
					),
					'__' => array(
						'replace' => '',
					),
				),
				// If the _ attribute is used the value corresponding to 'children' will be changed to array(true, array('url' => true)).
				'children_func' => array($this, 'url_children'),
				'parents' => array(true),

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
						'required' => true,
						'type_check' => 'ctype_digit'
					),
				),
				'children' => array(true, 'size' => true),
				'parents' => array(true),
			),


			// FLASH tag implementation attempt.
			'flash' => array(
				'replace' => '<object classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=7,0,19,0"{w}{h}>
<param name="movie" value="{m}" />
<param name="quality" value="high" />
<embed src="{m}" quality="high" pluginspage="http://www.macromedia.com/go/getflashplayer" type="application/x-shockwave-flash"{w}{h}>
</embed>
</object>',
				'close' => false,
				'attributes' => array(
					'm' => array(
						'replace' => '%s',
						'required' => true,
					),
					'w' => array(
						'replace' => ' width="%s"',
						'type_check' => 'ctype_digit',
					),
					'h' => array(
						'replace' => ' height="%s"',
						'type_check' => 'ctype_digit',
					),
				),
				'children' => array(false),
				'parents' => array(true),
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


 	protected function url_tag(array $attributes = array(), array $definition = array())
	{
		if (isset($attributes['_']))
		{
			return '<a href="' . $attributes['_'] . '">';
		}
		return '<a href="' . $attributes['__'] . '">';
	}

	protected function url_children(array $attributes)
	{
		$this->num_urls++;
		if (isset($attributes['_']))
		{
			return array(true, 'url' => true, '__url' => true, '__smiley' => true);
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