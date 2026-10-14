<?php
/**
 * SandboxModule — what a sandboxed plugin's WebAssembly module offers and needs,
 * read from its bytes without running it.
 *
 * A module's export section names the calls it answers (on_ui, on_signal,
 * on_schedule) and its import section names everything it expects the host to
 * give it. This reads both, so a plugin that declares a form but cannot answer
 * one, or that imports something the plugin host does not provide, is refused
 * at install and by the author's validator, naming what is wrong, rather than
 * failing at its first call.
 *
 * What the host provides (docs/sandboxed_plugins.md § Writing a module):
 * Extism's runtime functions (extism:host/env), the three doors
 * (extism:host/user joinery_api, joinery_http, joinery_log), and WASI preview 1
 * (wasi_snapshot_preview1) with no files.
 *
 * @version 1.0
 */

class SandboxModuleException extends Exception {}

class SandboxModule {

	/** The exports a module may answer, and what makes each needed. */
	const EXPORTS = array('on_ui', 'on_signal', 'on_schedule');

	/** The door functions the host gives a module. */
	const DOORS = array('joinery_api', 'joinery_http', 'joinery_log');

	/** Import namespaces the host provides in full. */
	const PROVIDED_NAMESPACES = array('extism:host/env', 'wasi_snapshot_preview1');

	/** The largest module a plugin may have; the plugin host refuses a larger one too. */
	const MAX_BYTES = 32 * 1024 * 1024;

	/** @var string[] */
	private $exports = array();

	/** @var array<int,array{module:string,name:string,kind:int}> */
	private $imports = array();

	/**
	 * @throws SandboxModuleException when the bytes are not a WebAssembly module
	 */
	public static function fromFile(string $path): self {
		$size = @filesize($path);
		if ($size === false || $size > self::MAX_BYTES) {
			throw new SandboxModuleException('The module cannot be read, or is larger than ' . (self::MAX_BYTES >> 20) . ' MB.');
		}
		return new self((string)file_get_contents($path));
	}

	/**
	 * @throws SandboxModuleException when the bytes are not a WebAssembly module
	 */
	public function __construct(string $bytes) {
		if (strlen($bytes) < 8 || substr($bytes, 0, 4) !== "\0asm" || substr($bytes, 4, 4) !== "\x01\0\0\0") {
			throw new SandboxModuleException('This is not a WebAssembly module (version 1).');
		}
		$pos = 8;
		$len = strlen($bytes);
		while ($pos < $len) {
			$id = ord($bytes[$pos++]);
			$size = self::uleb($bytes, $pos);
			$end = $pos + $size;
			if ($end > $len) {
				throw new SandboxModuleException('The module is cut short.');
			}
			if ($id === 2) {
				$this->readImports(substr($bytes, $pos, $size));
			} elseif ($id === 7) {
				$this->readExports(substr($bytes, $pos, $size));
			}
			$pos = $end;
		}
	}

	/** @return string[] the functions it exports */
	public function exports(): array {
		return $this->exports;
	}

	/** @return array<int,array{module:string,name:string}> what it imports */
	public function imports(): array {
		return $this->imports;
	}

	/**
	 * Why this module cannot run as the manifest says, in words: an export the
	 * manifest needs and the module lacks, or an import the host does not
	 * provide. Empty when it can.
	 *
	 * @return string[]
	 */
	public function problems(SandboxManifest $m): array {
		$problems = array();
		// A plugin of pages and data alone needs no export at all.
		$needs = array();
		foreach (array_merge($m->adminPages(), $m->profilePages()) as $page) {
			foreach ($page['sections'] as $section) {
				if ($section['type'] === 'form') {
					$needs['on_ui'] = 'its pages have forms';
				}
			}
		}
		if ($m->signals()) {
			$needs['on_signal'] = 'it asks for signals';
		}
		if ($m->schedules()) {
			$needs['on_schedule'] = 'it declares schedules';
		}
		foreach ($needs as $export => $why) {
			if (!in_array($export, $this->exports, true)) {
				$problems[] = "The module does not export $export, which it needs because $why.";
			}
		}
		foreach ($this->imports as $import) {
			if (in_array($import['module'], self::PROVIDED_NAMESPACES, true)) {
				continue;
			}
			if ($import['module'] === 'extism:host/user' && in_array($import['name'], self::DOORS, true)) {
				continue;
			}
			$problems[] = 'The module imports ' . $import['module'] . ' ' . $import['name']
				. ', which the plugin host does not provide.';
		}
		return $problems;
	}

	private function readImports(string $s): void {
		$pos = 0;
		$count = self::uleb($s, $pos);
		for ($i = 0; $i < $count; $i++) {
			$module = self::name($s, $pos);
			$name = self::name($s, $pos);
			$kind = ord($s[$pos++] ?? "\0");
			switch ($kind) {
				case 0: self::uleb($s, $pos); break;                       // function: type index
				case 1: $pos++; self::limits($s, $pos); break;            // table: reftype, limits
				case 2: self::limits($s, $pos); break;                    // memory: limits
				case 3: $pos += 2; break;                                  // global: valtype, mut
				case 4: $pos++; self::uleb($s, $pos); break;              // tag: attribute, type index
				default: throw new SandboxModuleException('The module has an import of a kind this does not know.');
			}
			$this->imports[] = array('module' => $module, 'name' => $name, 'kind' => $kind);
		}
	}

	private function readExports(string $s): void {
		$pos = 0;
		$count = self::uleb($s, $pos);
		for ($i = 0; $i < $count; $i++) {
			$name = self::name($s, $pos);
			$kind = ord($s[$pos++] ?? "\0");
			self::uleb($s, $pos);
			if ($kind === 0) {
				$this->exports[] = $name;
			}
		}
	}

	private static function limits(string $s, int &$pos): void {
		$flags = ord($s[$pos++] ?? "\0");
		self::uleb($s, $pos);
		if ($flags & 1) {
			self::uleb($s, $pos);
		}
	}

	private static function name(string $s, int &$pos): string {
		$n = self::uleb($s, $pos);
		if ($pos + $n > strlen($s)) {
			throw new SandboxModuleException('The module names something past its end.');
		}
		$name = substr($s, $pos, $n);
		$pos += $n;
		return $name;
	}

	/** An unsigned LEB128 integer. */
	private static function uleb(string $s, int &$pos): int {
		$result = 0;
		$shift = 0;
		$len = strlen($s);
		do {
			if ($pos >= $len || $shift > 35) {
				throw new SandboxModuleException('The module is malformed.');
			}
			$byte = ord($s[$pos++]);
			$result |= ($byte & 0x7f) << $shift;
			$shift += 7;
		} while ($byte & 0x80);
		return $result;
	}
}
