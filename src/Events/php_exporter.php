<?php
/**
 *
 * EPV :: The phpBB Forum Extension Pre Validator.
 *
 * @copyright (c) 2014 phpBB Limited <https://www.phpbb.com>
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace Phpbb\Epv\Events;

use Phpbb\Epv\Output\Output;
use Phpbb\Epv\Output\OutputInterface;

/**
 * Class php_exporter
 * Crawls through a list of files and grabs all php-events
 */
class php_exporter
{
	/** @var string */
	protected $current_file;

	/** @var string */
	protected $current_event;

	/** @var int */
	protected $current_event_line;

	/** @var array */
	protected $events;

	/** @var array */
	protected $file_lines;

	/** @var \Phpbb\Epv\Output\OutputInterface */
	protected $output;

	/** @var string  */
	protected $rundir;

	/** @var string */
	protected $current_clean_file;

	/**
	 * @param \Phpbb\Epv\Output\OutputInterface $output
	 * @param string                            $rundir
	 */
	public function __construct(OutputInterface $output, $rundir)
	{
		$this->output             = $output;
		$this->events             = $this->file_lines = array();
		$this->current_file       = $this->current_event = '';
		$this->current_event_line = 0;
		$this->rundir             = $rundir;
	}

	/**
	 * Get the list of all events
	 *
	 * @return array        Array with events: name => details
	 */
	public function get_events()
	{
		return $this->events;
	}

	/**
	 * Set current event data
	 *
	 * @param string $name Name of the current event (used for error messages)
	 * @param int    $line Line where the current event is placed in
	 *
	 * @return null
	 */
	public function set_current_event($name, $line)
	{
		$this->current_event      = $name;
		$this->current_event_line = $line;
	}

	/**
	 * Set the content of this file
	 *
	 * @param array $content Array with the lines of the file
	 *
	 * @return null
	 */
	public function set_content($content)
	{
		$this->file_lines = $content;
	}

	/**
	 * @param string $file
	 *
	 * @return int Number of events found in this file
	 * @throws \LogicException
	 */
	public function crawl_php_file($file)
	{
		$this->current_file = $file;
		$this->current_clean_file = str_replace($this->rundir, '', $file);
		$this->file_lines   = array();
		$content            = file_get_contents($this->current_file);
		$num_events_found   = 0;

		if (strpos($content, "dispatcher->trigger_event('") || strpos($content, "dispatcher->dispatch('"))
		{
			$this->set_content(explode("\n", $content));
			for ($i = 0, $num_lines = count($this->file_lines); $i < $num_lines; $i++)
			{
				$event_line          = false;
				$found_trigger_event = strpos($this->file_lines[$i], "dispatcher->trigger_event('");
				$arguments           = array();
				if ($found_trigger_event !== false)
				{
					$event_line = $i;
					$this->set_current_event($this->get_event_name($event_line, false), $event_line);

					// Find variables of the event if it has them
					if (strpos($this->file_lines[$event_line], 'compact('))
					{
						$arguments = $this->get_vars_from_array();
						$doc_vars  = $this->get_vars_from_docblock();
						$this->validate_vars_docblock_array($arguments, $doc_vars);
					}
				}
				else
				{
					$found_dispatch = strpos($this->file_lines[$i], "dispatcher->dispatch('");
					if ($found_dispatch !== false)
					{
						$event_line = $i;
						$this->set_current_event($this->get_event_name($event_line, true), $event_line);
					}
				}

				if ($event_line)
				{
					// Validate @event
					$event_line_num = $this->find_event();
					$this->validate_event($this->current_event, $this->file_lines[$event_line_num]);

					// Validate @since
					$since_line_num = $this->find_since();
					$since          = $this->validate_since($this->file_lines[$since_line_num]);

					// Find event description line
					$description_line_num = $this->find_description();
					$description          = substr(trim($this->file_lines[$description_line_num]), strlen('* '));

					if (isset($this->events[$this->current_event]))
					{
						throw new \LogicException("The event '{$this->current_event}' from file "
							. "'{$this->current_clean_file}:{$event_line_num}' already exists in file "
							. "'{$this->events[$this->current_event]['file']}'", 10);
					}

					sort($arguments);
					$this->events[$this->current_event] = array(
						'event'       => $this->current_event,
						'file'        => $this->current_file,
						'arguments'   => $arguments,
						'since'       => $since,
						'description' => $description,
					);
					$num_events_found++;
				}
			}
		}

		return $num_events_found;
	}

	/**
	 * Find the name of the event inside the dispatch() line
	 *
	 * @param int  $event_line
	 * @param bool $is_dispatch Do we look for dispatch() or trigger_event() ?
	 *
	 * @return string    Name of the event
	 * @throws \LogicException
	 */
	public function get_event_name($event_line, $is_dispatch)
	{
		$event_text_line = $this->file_lines[$event_line];
		$event_text_line = ltrim($event_text_line, " \t");

		$event = $is_dispatch ? 'dispatch' : 'trigger_event';
		$regex = '/->(?:' . $event . ')\(([\'"])%s\1/';

		$match = array();
		preg_match(sprintf($regex, $this->preg_match_event_name()), $event_text_line, $match);
		if (!isset($match[2]))
		{
			$match = array();
			preg_match(sprintf($regex, $this->preg_match_event_name_uppercase()), $event_text_line, $match);

			if (isset($match[2]))
			{
				$this->output->addMessage(Output::ERROR, sprintf('Event names should be all lowercase in %s for event %s', $this->current_clean_file, $match[2]));
			}
			else
			{
				throw new \LogicException("Can not find event name in line '{$event_text_line}' "
					. "in file '{$this->current_clean_file}:{$event_line}'", 1);
			}
		}

		return $match[2];
	}

	/**
	 * Returns a regex match for the event name
	 *
	 * @return string
	 */
	protected function preg_match_event_name()
	{
		return '([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)';
	}

	protected function preg_match_event_name_uppercase()
	{
		return '([a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+)';
	}


	/**
	 * Find the $vars array
	 *
	 * @param    bool   $throw_multiline      Throw an exception when there are too
	 *                                        many arguments in one line.
	 *
 	 * @return array        List of variables
	 * @throws \LogicException
	 */
	public function get_vars_from_array($throw_multiline = true)
	{
		$line = ltrim($this->file_lines[$this->current_event_line - 1], " \t");
		if ($line === ');' || $line === '];')
		{
			$vars_array = $this->get_vars_from_multi_line_array();
		}
		else
		{
			$vars_array = $this->get_vars_from_single_line_array($line, $throw_multiline);
		}

		foreach ($vars_array as $var)
		{
			if (!preg_match('#^([a-zA-Z_][a-zA-Z0-9_]*)$#', $var))
			{
				throw new \LogicException("Found invalid var '{$var}' in array for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 3);
			}
		}

		sort($vars_array);

		return $vars_array;
	}

	/**
	 * Find the variables in a single line array
	 *
	 * @param    string $line
	 * @param    bool   $throw_multiline      Throw an exception when there are too
	 *                                        many arguments in one line.
	 *
	 * @return array        List of variables
	 * @throws \LogicException
	 */
	public function get_vars_from_single_line_array($line, $throw_multiline = true)
	{
		$match = array();
		preg_match('#^\$vars = (?:(\[)|array\()\'([a-z0-9_\' ,]+)\'(?(1)\]|\));$#i', $line, $match);

		if (isset($match[2]))
		{
			$vars_array = explode("', '", $match[2]);
			if ($throw_multiline && count($vars_array) > 6)
			{
				throw new \LogicException('Should use multiple lines for $vars definition '
					. "for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 2);
			}

			return $vars_array;
		}
		else
		{
			throw new \LogicException("Can not find '\$vars = array();'-line for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'. Are you using UNIX style linefeed?", 1);
		}
	}

	/**
	 * Find the variables in a multi line array
	 *
	 * @return array        List of variables
	 * @throws \LogicException
	 */
	public function get_vars_from_multi_line_array()
	{
		$current_vars_line = 2;
		$var_lines         = array();
		while (!in_array(ltrim($this->file_lines[$this->current_event_line - $current_vars_line], "\t"), ['$vars = array(', '$vars = [']))
		{
			$var_lines[] = substr(trim($this->file_lines[$this->current_event_line - $current_vars_line]), 0, -1);

			$current_vars_line++;
			if ($current_vars_line > $this->current_event_line)
			{
				// Reached the start of the file
				throw new \LogicException("Can not find end of \$vars array for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'.", 2);
			}
		}

		return $this->get_vars_from_single_line_array('$vars = array(' . implode(", ", $var_lines) . ');', false);
	}

	/**
	 * Find the $vars array
	 *
	 * @return array        List of variables
	 * @throws \LogicException
	 */
	public function get_vars_from_docblock()
	{
		$doc_vars          = array();
		$current_doc_line  = 1;
		$found_comment_end = false;
		while (ltrim($this->file_lines[$this->current_event_line - $current_doc_line], " \t") !== '/**')
		{
			if (ltrim($this->file_lines[$this->current_event_line - $current_doc_line], " \t") === '*/')
			{
				$found_comment_end = true;
			}

			if ($found_comment_end)
			{
				$var_line = trim($this->file_lines[$this->current_event_line - $current_doc_line]);
				$var_line = preg_replace('!\s+!', ' ', $var_line);
				if (strpos($var_line, '* @var ') === 0)
				{
					$doc_line = explode(' ', $var_line, 5);
					if (count($doc_line) !== 5)
					{
						throw new \LogicException("Found invalid line '{$this->file_lines[$this->current_event_line - $current_doc_line]}' "
							. "for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 1);
					}
					$doc_vars[] = $doc_line[3];
				}
			}

			$current_doc_line++;
			if ($current_doc_line > $this->current_event_line)
			{
				// Reached the start of the file
				throw new \LogicException("Can not find end of docblock for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 2);
			}
		}

		if (empty($doc_vars))
		{
			// Reached the start of the file
			throw new \LogicException("Can not find @var lines for event '{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 3);
		}

		foreach ($doc_vars as $var)
		{
			if (!preg_match('#^([a-zA-Z_][a-zA-Z0-9_]*)$#', $var))
			{
				throw new \LogicException("Found invalid @var '{$var}' in docblock for event "
					. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 4);
			}
		}

		sort($doc_vars);

		return $doc_vars;
	}

	/**
	 * Find the "@since" Information line
	 *
	 * @return int Absolute line number
	 * @throws \LogicException
	 */
	public function find_since()
	{
		return $this->find_tag('since', array('event', 'var'));
	}

	/**
	 * Find the "@event" Information line
	 *
	 * @return int Absolute line number
	 */
	public function find_event()
	{
		return $this->find_tag('event', array());
	}

	/**
	 * Find a "@*" Information line
	 *
	 * @param string $find_tag            Name of the tag we are trying to find
	 * @param array  $disallowed_tags     List of tags that must not appear between
	 *                                    the tag and the actual event
	 *
	 * @return int Absolute line number
	 * @throws \LogicException
	 */
	public function find_tag($find_tag, $disallowed_tags)
	{
		$find_tag_line     = 0;
		$found_comment_end = false;
		while (strpos(ltrim($this->file_lines[$this->current_event_line - $find_tag_line], " \t"), '* @' . $find_tag . ' ') !== 0)
		{
			if ($found_comment_end && ltrim($this->file_lines[$this->current_event_line - $find_tag_line], " \t") === '/**')
			{
				// Reached the start of this doc block
				throw new \LogicException("Can not find '@{$find_tag}' information for event "
					. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 1);
			}

			foreach ($disallowed_tags as $disallowed_tag)
			{
				if ($found_comment_end && strpos(ltrim($this->file_lines[$this->current_event_line - $find_tag_line], " \t"), '* @' . $disallowed_tag) === 0)
				{
					// Found @var after the @since
					throw new \LogicException("Found '@{$disallowed_tag}' information after '@{$find_tag}' for event "
						. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 3);
				}
			}

			if (ltrim($this->file_lines[$this->current_event_line - $find_tag_line], " \t") === '*/')
			{
				$found_comment_end = true;
			}

			$find_tag_line++;
			if ($find_tag_line >= $this->current_event_line)
			{
				// Reached the start of the file
				throw new \LogicException("Can not find '@{$find_tag}' information for event "
					. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 2);
			}
		}

		return $this->current_event_line - $find_tag_line;
	}

	/**
	 * Find a "@*" Information line
	 *
	 * @return int Absolute line number
	 * @throws \LogicException
	 */
	public function find_description()
	{
		$find_desc_line = 0;
		while (ltrim($this->file_lines[$this->current_event_line - $find_desc_line], " \t") !== '/**')
		{
			$find_desc_line++;
			if ($find_desc_line > $this->current_event_line)
			{
				// Reached the start of the file
				throw new \LogicException("Can not find a description for event "
					. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 1);
			}
		}

		$find_desc_line = $this->current_event_line - $find_desc_line + 1;

		$desc = trim($this->file_lines[$find_desc_line]);
		if (strpos($desc, '* @') === 0 || $desc[0] !== '*' || substr($desc, 1) == '')
		{
			// First line of the doc block is a @-line, empty or only contains "*"
			throw new \LogicException("Can not find a description for event "
				. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 2);
		}

		return $find_desc_line;
	}

	/**
	 * Validate "@since" Information
	 *
	 * @param string $line
	 *
	 * @return string
	 * @throws \LogicException
	 */
	public function validate_since($line)
	{
		$match = array();
		preg_match('#^\* @since (\d+(\.\d+)+(?:-(?:a|b|RC|pl)\d+)?)$#', ltrim($line, " \t"), $match);
		if (!isset($match[1]))
		{
			throw new \LogicException("Invalid '@since' information for event "
				. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'");
		}

		return $match[1];
	}

	/**
	 * Validate "@event" Information
	 *
	 * @param string $event_name
	 * @param string $line
	 *
	 * @return string
	 * @throws \LogicException
	 */
	public function validate_event($event_name, $line)
	{
		$event = substr(ltrim($line, " \t"), strlen('* @event '));

		if ($event !== trim($event))
		{
			throw new \LogicException("Invalid '@event' information for event "
				. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 1);
		}

		if ($event !== $event_name)
		{
			throw new \LogicException("Event name does not match '@event' tag for event "
				. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'", 2);
		}

		return $event;
	}

	/**
	 * Validates that two arrays contain the same strings
	 *
	 * @param array $vars_array    Variables found in the array line
	 * @param array $vars_docblock Variables found in the doc block
	 *
	 * @return null
	 * @throws \LogicException
	 */
	public function validate_vars_docblock_array($vars_array, $vars_docblock)
	{
		$vars_array        = array_unique($vars_array);
		$vars_docblock     = array_unique($vars_docblock);
		$sizeof_vars_array = count($vars_array);

		if ($sizeof_vars_array !== count($vars_docblock) || $sizeof_vars_array !== count(array_intersect($vars_array, $vars_docblock)))
		{
			throw new \LogicException("\$vars array does not match the list of '@var' tags for event "
				. "'{$this->current_event}' in file '{$this->current_clean_file}:{$this->current_event_line}'");
		}
	}
}
