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
 * A stack based BBCode parser.
 *
 * To make this work without phpBB you would need to replace the generate_board_url() and get_preg_expression() calls.
 *
 */
abstract class phpbb_bbcode_parser_base
{
	/**
	 * Array holding the BBCode definitions.
	 *
	 * This is all the documentation you'll find!
	 *
	 * 'tagName' => array( // The tag name must start with a letter and can consist only of letters and numbers.
	 * 		'replace' => 'The open tag is replaced with this. "{attribute}" - Will be replaced with an existing attribute.',
	 * 		// Optional
	 * 		'replace_func' => 'function_name', // Open tag is replaced with the the value that this function returns. replace will not be used. The function will get the arguments given to the tag and the tag definition. It is your responsibility to validate the arguments.
	 * 		'close' => 'The close tag is replaced by this. If set to bool(false) the tag won't need a closing tag.',
	 * 		// Optional
	 * 		'close_shadow' => true, // If set, no closing tag will be needed, but the value close will be added as soon as the parent tag is closed or a tag which is not allowed in the tag is encountered.
	 * 		// Optional
	 * 		'close_func' => 'function_name', // Close tag is replaced with the the value that this function returns. close will not be used. If close is set to bool this might not function as expected.
	 * 		'attributes' => array(
	 * 			'attributeName' => array(
	 * 				'replace' => 'Attribute replacement. Use string defined in self::$attr_value_replace as a replacement for the attributes value',
	 * 				'required' => true, // Optional. The attribute must be set and not empty for the tag to be parsed.
	 * 			),
	 * 			// ...
	 * 		),
	 * 		'children' => array(
	 * 			true, // true allows all tags to be a child of this tag except for the other tags in the array. false allows only the tags in the array.
	 * 			'tag2' => true,
	 * 			// ...
	 * 		),
	 * 		// Optional
	 *		'children_func' => 'function_name', // Decide which children to allow based on the attributes that are present.
	 * 		'parents' => array(true), // Same as 'children'.
	 * 		// Optional
	 * 		'content_func' => 'function_name', // Applies function to the contents of the tag and replaces it with the output. Used only when the tag does not allow children. It must return the replacement string and accept the input string. This is not like HTML...
	 * 		// Optional
	 * 		'attribute_check' => 'function_name', // Validates attributes (if you want to change attributes pass the attributes array by reference and return true)
	 * 	),
	 * 	'tag2' => array(
	 * // ...
	 *
	 * NOTE: Use "_" as the name of the attribute assigned to the tag itself. (eg. form the tag [tag="value"] "_" will hold "value")
	 * NOTE: Use "__" for the content of a tag without children. (eg. for [u]something[/u] "__" will hold "something") This is not like HTML...
	 * NOTE: The following special tags exist: "__url" (child), "__smiley" (child) and "__global" (parent). They are to be used in the child/parent allowed/disallowed lists.
	 * @var array
	 */
	protected  $tags = array();
	
	/**
	 * The smilies which are to be "parsed".
	 * 
	 * Smilies are treated the same way as BBCodes (though BBcodes have precedence).
	 * Use "__smiley" to allow/disallow them in tags. Smileys can only be children.
	 * 
	 * 'smiley' => 'replacement'
	 *
	 * @var array
	 */
	protected $smilies = array();
	
	/**
	 * Callback to be applied to all text nodes (in second_pass).
	 *
	 * @var mixed
	 */
	protected $text_callback = null;

	/**
	 * Used by first_pass and second_pass
	 *
	 * @var array
	 */
	private $stack = array();

	/**
	 * Regex to match BBCode tags.
	 *
	 * @var string
	 */
	private $tag_regex = '~\[(/?)(\*|[a-z][a-z0-9]*)(?:=(\'[^\']*\'|&quot;(?:.(?!&quot;))*.?&quot;|[^ \]]*))?((?: [a-z]+(?:\s?=\s?(?:\'[^\']*\'|"[^"]*"|[^ \]]*))?)*)\]~i';

	/**
	 * Regex to match attribute&value pairs.
	 *
	 * @var string
	 */
	private $attribute_regex = '~([a-z]+)(?:\s?=\s?((?:\'[^\']*?\'|&quot;(?:.(?!&quot;))*.?&quot;)))?~i';

	/**
	 * Delimiter's ASCII code.
	 *
	 * @var int
	 */
	private $delimiter = 0;

	/**
	 * This string will be replaced by the attribute value.
	 *
	 * @var string
	 */
	private $attr_value_replace = '%s';

	/**
	 * First pass result.
	 *
	 * @var array
	 */
	private $parsed = array();
	private $parse_pos = 1;
	
	/**
	 * Parse flags
	 *
	 * @var int
	 */
	protected $flags;

	/**
	 * Local URL prefix
	 *
	 * @var string
	 */
	protected $local_url;

	/**
	 * Total number of smilies
	 *
	 * @var string
	 */
	private $num_smilies;

	/**
	 * Total number of URLs
	 *
	 * @var string
	 */
	protected $num_urls;

	/**
	 * Disabled tags
	 *
	 * @var array
	 */
	private $disabled = array();

	/**
	 * Disabled tags present in the current message
	 *
	 * @var array
	 */
	private $disabled_present = array();
	
	/**
	 * Types
	 */
	const TYPE_TAG				= 1;
	const TYPE_TAG_SIMPLE		= 2;
	const TYPE_CTAG				= 3;
	const TYPE_ABSTRACT_SMILEY	= 4;
	const TYPE_ABSTRACT_URL		= 5;
	const TYPE_ABSTRACT_WWW		= 6;
	const TYPE_ABSTRACT_EMAIL	= 7;
	const TYPE_ABSTRACT_LOCAL	= 8;
	
	/**
	 * Feature flags
	 */
	const PARSE_BBCODE	= 1;
	const PARSE_URLS	= 2;
	const PARSE_SMILIES	= 4;

	/**
	 * Tag Backreferences.
	 *
	 */
	const MATCH_CLOSING_TAG	= 1;
	const MATCH_TAG_NAME	= 2;
	const MATCH_SHORT_ARG	= 3;
	const MATCH_ARGS		= 4;
	
	/**
	 * Argument backreferences
	 * 
	 */
	const MATCH_ARG_NAME	= 1;
	const MATCH_ARG_VALUE	= 2;

	/**
	 * Constructor.
	 *
	 */
	public function __construct()
	{
		$this->delimiter = chr($this->delimiter);
		$this->flags = self::PARSE_BBCODE | self::PARSE_URLS | self::PARSE_SMILIES;
		$this->local_url = generate_board_url();
	}

	/**
	 * Returns a string ready for storage and/or second_pass
	 *
	 * @param string $string
	 * @return string
	 */
	public function first_pass($string)
	{
		$this->stack = array();
		$this->parsed = array();
		$this->num_urls = $this->num_smilies = 0;
		$this->parse_pos = 1;
		$this->disabled = array();
		$this->disabled_present = array();
		$this->message_length = 0;

		// Remove the delimiter from the string.
		$string = str_replace($this->delimiter, '', $string);
		
		$smilies = implode('|', array_map(array($this, 'regex_quote'), array_keys($this->smilies)));
		
		// Make a regex out of the following items:
		$regex_parts = array(
			'tag_first_pass'    => $this->tag_regex,
			// local_first_pass should go before url_first_pass
			'local_first_pass'  => '#' . preg_quote($this->local_url, '#') . '/(' . get_preg_expression('relative_url_inline') . ')#i',
			'url_first_pass'    => '#' . get_preg_expression('url_inline') . '#i',
			'www_first_pass'    => '#' . get_preg_expression('www_url_inline') . '#i',
			'email_first_pass'  => '/' . get_preg_expression('email') . '/i',
			'smiley_first_pass' => '~' . $smilies . '~i',
		);

		$parsed = '';

		do
		{
			$start = array();
			$length = array();
			foreach ($regex_parts as $regex_func=>$regex_part)
			{
				$temp = preg_replace($regex_part, $this->delimiter, $string, 1);
				$position = strpos($temp, $this->delimiter);
				if ($position !== false)
				{
					$start[$regex_func] = $position;
					$length[$regex_func] = strlen($string) - strlen($temp) + 1;
					if ($position === 0)
					{
						break;
					}
				}
			}

			if (!empty($start))
			{
				// asort() isn't a stable sort so it isn't used
				$sorted = $start;
				sort($sorted);
				$regex_func = array_search($sorted[0], $start);
				if (!isset($this->parsed[$this->parse_pos - 1]))
				{
					$this->parsed[$this->parse_pos - 1] = '';
				}
				$this->parsed[$this->parse_pos - 1].= substr($string, 0, $start[$regex_func]);
				$parsed = strpos(preg_replace_callback($regex_parts[$regex_func], array($this, $regex_func), $string, 1), $this->delimiter) !== false;
				if (!$parsed)
				{
					$this->parsed[$this->parse_pos - 1].= substr($string, $start[$regex_func], $length[$regex_func]);
				}
				$string = substr($string, $start[$regex_func] + $length[$regex_func]);
			}
		}
		while (!empty($start));

		$this->parsed[$this->parse_pos - 1].= $string;

		$string = $this->parsed;
		$this->parsed = array();
		$this->parse_pos = 1;
		$this->disabled = array();

		return serialize($string);
	}

	/**
	 * Returns the number of smilies
	 *
	 * @return integer
	 */
	public function num_smilies()
	{
		return $this->num_smilies;
	}

	/**
	 * Returns the number of URLs
	 *
	 * @return integer
	 */
	public function num_urls()
	{
		return $this->num_urls;
	}

	/**
	 * Disable select BBcodes
	 *
	 * @param string $tag
	 */
	public function disable_bbcode($tag)
	{
		$this->disabled[] = $tag;
	}

	/**
	 * Returns the disabled BBcodes that were present in the message
	 *
	 * @return array
	 */
	public function disable_present()
	{
		return $this->disabled_present;
	}

	/**
	 * Opposite function to first_pass.
	 * Changes the output of first_pass back to BBCode.
	 *
	 * @param string $string
	 * @return string
	 * @todo make sure this works after the change of first_pass data storage.
	 */
	public function first_pass_decompile($string)
	{
		$string = unserialize($string);
		for ($i = 1, $n = sizeof($string); $i < $n; $i += 2)
		{
			$string[$i] = $this->decompile_tag($string[$i]);
		}
		return implode('', $string);
	}

	/**
	 * Removes first_pass data. This removes all BBCode tags. To reverse the effect of first_pass use first_pass_decompile
	 *
	 * @param string $string
	 * @return string
	 */
	public function remove_first_pass_data($string)
	{
		$decompiled = array();
		$compiled = unserialize($string);
		for ($i = 0, $n = sizeof($compiled); $i < $n; $n += 2)
		{
			$decompiled[] = $compiled[$i];
		}
		return implode('', $decompiled);
	}

	/**
	 * The function takes the result of first_pass and returnes the string fully parsed.
	 *
	 * @param string $string
	 * @return string
	 */
	public function second_pass($string)
	{
		$this->stack = array();

		$string = unserialize($string);
		
		if (!is_null($this->text_callback))
		{
			for ($i = 0, $n = sizeof($string); $i < $n; $i += 2)
			{
				$string[$i] = call_user_func($this->text_callback, $string[$i]);
			}
		}
		
		for ($i = 1, $n = sizeof($string); $i < $n; $i += 2)
		{

			$tag_data		= $string[$i];
			$type			= &$tag_data[0];
			$tag			= $tag_data[1];
			$tag_definition	= &$this->tags[$tag];

			if ($this->flags & self::PARSE_BBCODE && ($type == self::TYPE_TAG || $type == self::TYPE_TAG_SIMPLE))
			{
				// These apply to opening tags and tags without closing tags.

				// Is the tag still allowed as a child?
				// This is still needed!
				if (sizeof($this->stack) && isset($this->tags[$this->stack[0]['name']]['close_shadow']) && !is_bool($this->tags[$this->stack[0]['name']]['close']) && !$this->child_allowed($tag))
				{
					// The previous string won't be edited anymore.
					$string[$i - 1] .= $this->tags[$this->stack[0]['name']]['close'];
					array_shift($this->stack);
				}
				
				// Add tag to stack only if it needs a closing tag.
				if ($tag_definition['close'] !== false || !isset($tag_definition['close_shadow']))
				{
					array_unshift($this->stack, array('name' => $tag, 'attributes' => array()));
				}
			}
			
			switch ($type)
			{
				case self::TYPE_ABSTRACT_URL:

					$short_url = (strlen($tag_data[1]) > 55) ? substr($tag_data[1], 0, 39) . ' ... ' . substr($tag_data[1], -10) : $tag_data[1];

					if ($this->flags & self::PARSE_URLS && $this->child_allowed('__url'))
					{
						$string[$i] = '<a href="' . $tag_data[1] . '">' . $short_url . '</a>';
					}
					else
					{
						$string[$i] = $tag_data[1];
					}
				
				break;

				case self::TYPE_ABSTRACT_EMAIL:

					$short_url = (strlen($tag_data[1]) > 55) ? substr($tag_data[1], 0, 39) . ' ... ' . substr($tag_data[1], -10) : $tag_data[1];

					if ($this->flags & self::PARSE_URLS && $this->child_allowed('__url'))
					{
						$string[$i] = '<a href="mailto:' . $tag_data[1] . '">' . $short_url . '</a>';
					}
					else
					{
						$string[$i] = $tag_data[1];
					}
					
				break;

				case self::TYPE_ABSTRACT_WWW:

					$short_url = (strlen($tag_data[1]) > 55) ? substr($tag_data[1], 0, 39) . ' ... ' . substr($tag_data[1], -10) : $tag_data[1];

					if ($this->flags & self::PARSE_URLS && $this->child_allowed('__url'))
					{
						$string[$i] = '<a href="http://' . $tag_data[1] . '">' . $short_url . '</a>';
					}
					else
					{
						$string[$i] = $tag_data[1];
					}
					
				break;

				case self::TYPE_ABSTRACT_LOCAL:

					if ($this->flags & self::PARSE_URLS && $this->child_allowed('__url'))
					{
						$relative_url	= preg_replace('/[&?]sid=[0-9a-f]{32}$/', '', preg_replace('/([&?])sid=[0-9a-f]{32}&/', '$1', $tag_data[1]));
						$url			= $this->local_url . '/' . $relative_url;

						$string[$i] = !$relative_url ?
							'<a href="' . $this->local_url . '/' . $tag_data[1] . '">' . $this->local_url . '/' . $tag_data[1] . '</a>' : 
							'<a href="' . $url . '">' . $relative_url . '</a>';
					
					}
					else
					{
						$string[$i] = $tag_data[1];
					}
					
				break;
					
				case self::TYPE_ABSTRACT_SMILEY:

					if ($this->flags & self::PARSE_SMILIES && $this->child_allowed('__smiley'))
					{
						$string[$i] = $this->smilies[$tag_data[1]];
					}
					else
					{
						$string[$i] = $tag_data[1];
					}
					
				break;
				
				case self::TYPE_CTAG:
					
					if (($this->flags & self::PARSE_BBCODE) == 0)
					{
						$string[$i] = $this->decompile_tag($string[$i]);
						break;
					}
	
					// It must be the last one as tag nesting was checked in the first pass.
					// An exception to this rule was created with adding the new type of tag without closing tag.
					if (isset($this->tags[$this->stack[0]['name']]['close_shadow']))
					{
						if (!is_bool($this->tags[$this->stack[0]['name']]['close']))
						{
							// the previous string won't be edited anymore.
							$string[$i - 1] .= $this->tags[$this->stack[0]['name']]['close'];
						}
						else if (isset($tag_definition['close_func']))
						{
							$string[$i - 1] .= call_user_func($tag_definition['close_func'], $this->stack[0]['attributes']);
						}
						array_shift($this->stack);
					}

					if ($tag != $this->stack[0]['name'])
					{
						$string[$i] = $this->decompile_tag($string[$i]);
					}
					else if (isset($tag_definition['close_shadow']))
					{
						$string[$i] = '';
					}
					else if ($tag_definition['close'] !== false || !isset($tag_definition['close_shadow']))
					{
						if (isset($tag_definition['close_func']))
						{
							$string[$i] = call_user_func($tag_definition['close_func'], $this->stack[0]['attributes']);
						}
						else
						{
							$string[$i] = $tag_definition['close'];
						}
						array_shift($this->stack);
					}
					else
					{
						$string[$i] = '';
					}
					
				break;
					
				case self::TYPE_TAG_SIMPLE:
					
					if (($this->flags & self::PARSE_BBCODE) == 0)
					{
						$string[$i] = $this->decompile_tag($string[$i]);
						break;
					}
					
					if ($tag_definition['children'][0] == false && sizeof($tag_definition['children']) == 1)
					{
						if (isset($tag_definition['attributes']['__']))
						{
							$this->stack[0]['attributes'] = array('__' => $string[$i + 1]);
							if (isset($tag_definition['replace_func']))
							{
								$string[$i] = call_user_func($tag_definition['replace_func'], array('__' => $string[$i + 1]), $tag_definition);
							}
							else
							{
								$string[$i] = str_replace('{__}', $string[$i + 1], $tag_definition['replace']);
							}
						}
						else if (isset($tag_definition['replace_func']))
						{
							$string[$i] = call_user_func($tag_definition['replace_func'], array(), $tag_definition);
						}
						else
						{
							$string[$i] = $tag_definition['replace'];
						}

						if (isset($this->tags[$tag]['content_func']))
						{
							$string[$i + 1] = call_user_func($tag_definition['content_func'], $string[$i + 1]);
						}
					}
					else
					{
						if (isset($tag_definition['replace_func']))
						{
							$string[$i] = call_user_func($tag_definition['replace_func'], array(), $tag_definition);
						}
						else
						{
							$string[$i] = $tag_definition['replace'];
						}
					}
	
					if (sizeof($tag_definition['attributes']) > 0)
					{
						// The tag has defined attributes but doesn't use any. The attribute replacements must be removed. I don't want this regex here.
						$string[$i] = preg_replace('/{[^}]*}/', '', $string[$i]);
					}
						
				break;
				
				case self::TYPE_TAG:
			
					if (($this->flags & self::PARSE_BBCODE) == 0)
					{
						$string[$i] = $this->decompile_tag($string[$i]);
						break;
					}

					// These apply to tags with attributes.
					if (!isset($tag_data[2]))
					{
						$tag_data[2] = array('__' => $string[$i + 1]);
					}
					$this->stack[0]['attributes'] = $tag_data[2];
		
					// New code for the feature I've always wanted to implement :)
					if (isset($tag_definition['attributes']['__']) && $tag_definition['children'][0] == false && sizeof($tag_definition['children']) == 1)
					{
						$attributes = array('{__}');
						$replacements = array($string[$i + 1]);
						// End new code.
					}
					else
					{
						$attributes = array();
						$replacements = array();
					}
		
					// Handle the (opening) tag with a custom function
					if (isset($tag_definition['replace_func']))
					{
						
						$string[$i] = call_user_func($tag_definition['replace_func'], $tag_data[2], $tag_definition);
		
						if (isset($tag_definition['content_func']) && $tag_definition['children'][0] === false && sizeof($tag_definition['children']) == 1)
						{
							$string[$i + 1] = call_user_func($tag_definition['content_func'], $string[$i + 1]);
						}
						break;
					}
		
					foreach ($tag_definition['attributes'] as $attribute => $value)
					{
						$attributes[] = '{' . $attribute . '}';
						if (!isset($tag_data[2][$attribute]))
						{
							if (isset($value['required']))
							{
								$string[$i] = $this->decompile_tag($tag_data);
								break 2;
							}
							$replacements[] = '';
							continue;
						}
		
						$replacements[] = str_replace($this->attr_value_replace, $tag_data[2][$attribute], $tag_definition['attributes'][$attribute]['replace']);
					}
	
		
					$string[$i] = str_replace($attributes, $replacements, $this->tags[$tag]['replace']);
		
					// It has to be twice... this should not be used if required attributes are missing.
					if (isset($tag_definition['content_func']) && $tag_definition['children'][0] === false && sizeof($tag_definition['children']) == 1)
					{
						$string[$i + 1] = call_user_func($tag_definition['content_func'], $string[$i + 1]);
					}
					
				break;
			}
		}

		return implode($string);
	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function tag_first_pass($matches)
	{
		if (!isset($this->tags[$matches[self::MATCH_TAG_NAME]]))
		{
			// Tag with the given name not defined.
			return $matches[0];
		}

		// If tag is an opening tag.
		if (strlen($matches[self::MATCH_CLOSING_TAG]) == 0)
		{
			if (sizeof($this->stack))
			{
				if ($this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
				{
					// Tag does not allow children.
					return $matches[0];
				}
				// Tag parent not allowed for this tag. Omit here.
				else if (!$this->parent_allowed($matches[self::MATCH_TAG_NAME], $this->stack[0]))
				{
					if (isset($this->tags[$this->stack[0]]['close_shadow']))
					{
						array_shift($this->stack);
					}
					else
					{
						return $matches[0];
					}
				}
			}
			// Is tag allowed in global scope?
			else if (!$this->parent_allowed($matches[self::MATCH_TAG_NAME], '__global'))
			{
				return $matches[0];
			}
		
			$tag_attributes = &$this->tags[$matches[self::MATCH_TAG_NAME]]['attributes'];
		
			if (strlen($matches[self::MATCH_SHORT_ARG]) != 0 && isset($tag_attributes['_']))
			{
				// Validate short attribute.
				$value = preg_replace('#^(&quot;|\')(.*)\1$#', '$2', $matches[self::MATCH_SHORT_ARG]);

				// Add short attribute.
				if (isset($value))
				{
					$attributes = array('_' => $value);
				}
			}
			else if (strlen($matches[self::MATCH_ARGS]) == 0 || (sizeof($tag_attributes)) == 0)
			{
				// Check all attributes, which were not used, if they are required.
				if ($this->has_required($matches[self::MATCH_TAG_NAME], array_keys($tag_attributes)))
				{
					// Not all required attributes were used.
					return $matches[0];
				}
				else
				{
					$tag = array(-1 => $matches[0], self::TYPE_TAG_SIMPLE, $matches[self::MATCH_TAG_NAME]);
					if (isset($attributes))
					{
						$child_attribute = isset($this->tags[$this->stack[0]]['attributes']['__']) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1;
						if (isset($this->tags[$matches[self::MATCH_TAG_NAME]]['attribute_check']) && !$child_attribute && !call_user_func($this->tags[$matches[self::MATCH_TAG_NAME]]['attribute_check'], $attributes))
						{
							return $matches[0];
						}

						$tag[] = $attributes;
					}
					return $this->add_tag($tag);
				}
			}
			else
			{
				$attributes = array();
			}
		
			// Analyzer...
			$matched_attrs = array();
		
			preg_match_all($this->attribute_regex, $matches[self::MATCH_ARGS], $matched_attrs, PREG_SET_ORDER);
		
			foreach($matched_attrs as $i => $value)
			{
				$tag_attribs_matched = &$tag_attributes[$value[self::MATCH_ARG_NAME]];
				if (isset($attributes[$value[self::MATCH_ARG_NAME]]))
				{
					// This prevents adding the same attribute more than once. Childish betatesters are needed.
					continue;
				}
				if (isset($tag_attribs_matched))
				{
					// The attribute exists within the defined tag. Undefined tags are removed.
					$attr_value = preg_replace('#^(&quot;|\')(.*)\1$#', '$2', $value[self::MATCH_ARG_VALUE]);

					if (isset($tag_attribs_matched['required']) && strlen($attr_value) == 0)
					{
						// A required attribute is empty. This is done after the type check as the type check may return an empty value.
						return $matches[0];
					}
					$attributes[$value[self::MATCH_ARG_NAME]] = $attr_value;
				}
			}

			// Even in [url][/url] there's a __ parameter - it's just equal to the empty string
			$attributes['__'] = true;
		
			// Check all attributes, which were not used, if they are required.
			if ($this->has_required($matches[self::MATCH_TAG_NAME], array_values(array_diff(array_keys($tag_attributes), array_keys($attributes)))))
			{
				// Not all required attributes were used.
				return $matches[0];
			}

			unset($attributes['__']);

			if (sizeof($attributes))
			{
				$child_attribute = isset($this->tags[$this->stack[0]]['attributes']['__']) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1;
				if (isset($this->tags[$matches[self::MATCH_TAG_NAME]]['attribute_check']) && !$child_attribute && !call_user_func($this->tags[$matches[self::MATCH_TAG_NAME]]['attribute_check'], $attributes))
				{
					return $matches[0];
				}

				return $this->add_tag(array(-1 => $matches[0], self::TYPE_TAG, $matches[self::MATCH_TAG_NAME], $attributes));
			}

			return $this->add_tag(array(-1 => $matches[0], self::TYPE_TAG_SIMPLE, $matches[self::MATCH_TAG_NAME]));
		}
		// If tag is a closing tag.
				

		$valid = array_search($matches[self::MATCH_TAG_NAME], $this->stack);

		if ($valid === false)
		{
			// Closing tag without open tag.
			return $matches[0];
		}
		else if ($valid != 0)
		{
			if ($this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
			{
				// Tag does not allow children.
				// Do not handle other closing tags here as they are invalid in tags which do not allow children.
				return $matches[0];
			}
			// Now we have to close all tags that were opened before this closing tag.
			// We know that this tag does not close the last opened tag.
			$to_close = array_splice($this->stack, 0, $valid + 1);
			return $this->close_tags($to_close);
		}
		else if (!isset($this->tags[$matches[self::MATCH_TAG_NAME]]['close_shadow']))
		{
			$child_attribute = isset($this->tags[$this->stack[0]]['attributes']['__']) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1;
			if (isset($this->tags[$matches[self::MATCH_TAG_NAME]]['attribute_check']) && $child_attribute)
			{
				$tag = $this->parsed[$this->parse_pos - 2];
				$attributes = isset($tag[2]) ? array_merge($tag[2], array('__' => $this->parsed[$this->parse_pos - 1])) : array('__' => $this->parsed[$this->parse_pos - 1]);
				if (!call_user_func($this->tags[$this->stack[0]]['attribute_check'], $attributes))
				{
					array_shift($this->stack);
					$this->parsed[$this->parse_pos - 3] .= $this->decompile_tag($tag);
					$string = array_pop($this->parsed) . $matches[0];
					array_pop($this->parsed);
					$bbcode = clone $this;
					$string = unserialize($bbcode->first_pass($string));
					if (empty($string))
					{
						return '';
					}
					$this->parsed[$this->parse_pos - 3] .= array_shift($string);
					array_pop($string);
					$this->parsed = array_merge($this->parsed, $string);
					$this->parse_pos -= sizeof($string) + 2;
					return $this->delimiter;
				}	
			}

			// A unset() was elicting many notices here.
			array_shift($this->stack);
			$this->parsed[$this->parse_pos] = array(-1 => $matches[0], self::TYPE_CTAG, $matches[self::MATCH_TAG_NAME]);
			$this->parse_pos += 2;
			return $this->delimiter;
		}
		else
		{
			return $matches[0];
		}

	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function smiley_first_pass($matches)
	{
		// Parent tag does not allow children
		if (sizeof($this->stack) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
		{
			return $matches[0];
		}

		$this->num_smilies++;

		$this->parsed[$this->parse_pos] = array(self::TYPE_ABSTRACT_SMILEY, $matches[0]);
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function url_first_pass($matches)
	{
		// Parent tag does not allow children
		if (sizeof($this->stack) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
		{
			return $matches[0];
		}

		if (!preg_match('#[\n\t (.]$#', $this->parsed[$this->parse_pos - 1]) && !($this->parse_pos == 1 && empty($this->parsed[0])))
		{
			return $matches[0];
		}

		$this->num_urls++;

		$this->parsed[$this->parse_pos] = array(self::TYPE_ABSTRACT_URL, $matches[0]);
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function www_first_pass($matches)
	{
		// Parent tag does not allow children
		if (sizeof($this->stack) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
		{
			return $matches[0];
		}

		if (!preg_match('#[\n\t (]$#', $this->parsed[$this->parse_pos - 1]) && !($this->parse_pos == 1 && empty($this->parsed[0])))
		{
			return $matches[0];
		}

		$this->num_urls++;

		$this->parsed[$this->parse_pos] = array(self::TYPE_ABSTRACT_WWW, $matches[0]);
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function email_first_pass($matches)
	{
		// Parent tag does not allow children
		if (sizeof($this->stack) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
		{
			return $matches[0];
		}

		if (!preg_match('#[\n\t (]$#', $this->parsed[$this->parse_pos - 1]) && !($this->parse_pos == 1 && empty($this->parsed[0])))
		{
			return $matches[0];
		}

		$this->parsed[$this->parse_pos] = array(self::TYPE_ABSTRACT_EMAIL, $matches[0]);
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Callback for preg_replace_callback in first_pass.
	 *
	 * @param array $matches
	 * @return string
	 */
	private function local_first_pass($matches)
	{
		// Parent tag does not allow children
		if (sizeof($this->stack) && $this->tags[$this->stack[0]]['children'][0] == false && sizeof($this->tags[$this->stack[0]]['children']) == 1)
		{
			return $matches[0];
		}

		if (!preg_match('#[\n\t (.]$#', $this->parsed[$this->parse_pos - 1]) && !($this->parse_pos == 1 && empty($this->parsed[0])))
		{
			return $matches[0];
		}

		$this->num_urls++;

		$this->parsed[$this->parse_pos] = array(self::TYPE_ABSTRACT_LOCAL, $matches[1], $matches[0]);
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Adds a tag to $this->parsed and $this->stack
	 *
	 * @param array $tag
	 * @return string
	 */
	private function add_tag($tag)
	{
		if (in_array($tag[1], $this->disabled))
		{
			$this->disabled_present[] = $tag[1];
			return $this->decompile_tag($tag);
		}

		if ($this->tags[$tag[1]]['close'] !== false || !isset($this->tags[$tag[1]]['close_shadow']))
		{
			// Do not add tags to stack that do not need closing tags.
			array_unshift($this->stack, $tag[1]);
		}

		if (isset($this->tags[$tag[1]]['children_func']))
		{
			$this->tags[$tag[1]]['children'] = call_user_func($this->tags[$tag[1]]['children_func'], isset($tag[2]) ? $tag[2] : array());
		}

		$this->parsed[$this->parse_pos] = $tag;
		$this->parse_pos += 2;
		return $this->delimiter;
	}

	/**
	 * Returns closing tags for all tags in the $tags array (in reverse order).
	 *
	 * @param array $tags
	 * @return string
	 */
	private function close_tags($tags)
	{
		$ret = '';
		foreach($tags as $tag)
		{
			// @todo: Is this needed?
			if (!isset($this->tags[$tag]['close_shadow']) && $tag != 'children')
			{
				$this->parsed[$this->parse_pos] = array(self::TYPE_CTAG, $tag);
				$this->parse_pos += 2;
				$ret .= $this->delimiter;
			}
		}
		return $ret;
	}

	/**
	 * Returns the tag to the form it had before the first_pass
	 *
	 * @param array $tag
	 * @return string
	 */
	private function decompile_tag(array $tag)
	{
		switch ($tag[0])
		{
			case self::TYPE_ABSTRACT_SMILEY:
			case self::TYPE_ABSTRACT_URL:
			case self::TYPE_ABSTRACT_WWW:
			case self::TYPE_ABSTRACT_EMAIL:
				return $tag[1];
			case self::TYPE_ABSTRACT_LOCAL:
				return $this->local_url . '/' . $tag[1];
			default:
				return $tag[-1];
		}
	}

	/**
	 * Checks if $tag can be a child of the tag in stack index $index
	 *
	 * @param string $tag
	 * @param int $index = 0
	 * @return bool
	 */
	private function child_allowed($tag, $index = 0)
	{
		if (!isset($this->stack[$index]))
		{
			return true;
		}
		// I assume this trick is usefull starting form two.
		$children = &$this->tags[$this->stack[$index]['name']]['children'];
		if (isset($children[$tag]) xor $children[0])
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Checks if the $tag can be a child of $parent
	 *
	 * @param string $tag
	 * @param string $parent
	 * @return bool
	 */
	private function parent_allowed($tag, $parent)
	{
		$parents = &$this->tags[$tag]['parents'];
		if (isset($parents[$parent]) xor $parents[0])
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Checks if any of $tag's attributes in $attributes are required.
	 *
	 * @param string $tag
	 * @param string $attributes
	 * @return bool
	 */
	private function has_required($tag, $attributes)
	{
		for ($i = 0, $n = sizeof($attributes); $i < $n; ++$i)
		{
			if (isset($this->tags[$tag]['attributes'][$attributes[$i]]['required']))
			{
				return true;
			}
		}

		return false;
	}
	
	private function regex_quote($var)
	{
		return preg_quote($var, '~');
	}
	
	public function set_flags($flags)
	{
		$this->flags = (int) $flags;
	}
}
