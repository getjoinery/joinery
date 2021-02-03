<?php

class AcessLogParsersException extends Exception {};

abstract class AbstractLogParser implements Iterator { 

	// RAW will return each line in the log as a string,
	// PARSED will return each line as an array of matched items per
	// the $regex variable as processed by rewrite_matches()
	const RAW = 1;
	const PARSED = 2;
		
	protected $filenames = array();
	protected $line_number = 0;
	protected $current_line = NULL;
	protected $current_file_index = 0;
	protected $current_file = NULL;

	protected $mode = self::PARSED;

	public function __construct() {
		$this->current_file = fopen($this->filenames[0], 'r');
		$this->next(); // next() will initialize everything for us
	}

	public function current() { 
		return $this->current_line;
	}

	public function key() { 
		return $this->line_number;
	}

	public function rewind() { 
		$this->line_number = 0;
		if ($this->current_file_index == 0) { 
			fseek($this->current_file, 0);
		} else { 
			fclose($this->files($this->current_file_index));
			fopen($this->filenames[0], 'r');
		}
	}

	public function next() { 
		if (feof($this->current_file) && $this->current_file_index < count($this->filenames)) { 
			fclose($this->current_file);
			$this->current_file = fopen($this->filenames[$this->current_file_index++], 'r');
		} 

		if ($this->mode === self::RAW) { 
			$this->current_line = fgets($this->current_file);
		} else { 
			$this->parse_line(fgets($this->current_file));
		}

		$this->line_number++;
		return $this->current_line;
	}

	public function valid() { 
		return (!feof($this->current_file) || ($this->current_file_index < count($this->filenames)));
	}

	abstract protected function parse_line($line);
}

class AccessLogParser extends AbstractLogParser { 

	static public $regex = '/^(\d+\.\d+\.\d+\.\d+) ([^ ]*) ([^ ]*) \[([^\]]*)\] "([^"]*)" (\d+) ([^ ]*) "([^"]*)" "([^"]*)"$/';
	static $base_filename = '/etc/httpd/logs/access_log';

	public function __construct($weeks_back=1) { 
		if (is_numeric($weeks_back)) { 
			$this->filenames[] = self::$base_filename;
			for ($i=1; $i < $weeks_back + 1; $i++) { 
				$this->filenames[] = self::$base_filename . '.' . $i;
			}
		} elseif (is_array($weeks_back)) { 
			// argument can also just be an array of all the logs to parse
			$this->filenames = $weeks_back;
		} else { 
			throw new AccessLogParserException('Unknown paramater passed to constructor');
		}
		parent::__construct();
	}

	protected function parse_line($line) { 
		// Convert the nested array returned by preg_match_all to a simple
		// one-dimensional array that's a little nicer for users of this class.
		preg_match_all(self::$regex, $line, $matches, PREG_PATTERN_ORDER);
		$this->current_line = array(
			'date' => ($matches[4] ? new DateTime($matches[4][0]) : NULL), 
			'command' => @$matches[5][0],
			'status' => @$matches[6][0],
			'user_id' => @$matches[7][0],
			'url' => @$matches[8][0],
			'user_agent' => @$matches[9][0],
		);
	}
}

?>
