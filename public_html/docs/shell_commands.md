# Shell commands

PHP runs as the web user. A string an attacker can influence that becomes part
of a shell command is a shell as the web user, and from there anything a root
program trusts the web user to write. So every place PHP starts a program
follows one rule, and a test holds new code to it.

## The rule

Pass the command as an **argument list**, or build the string with **every
value escaped where it is used**. Never hand a shell a string that will be
split or parsed a second time.

```php
// An argument list: no shell is involved, so there is nothing to escape.
$proc = proc_open(['git', '-C', $repo, 'log', '-1', '--format=%H'], $spec, $pipes);

// A string: each value escaped at the point it joins the command.
exec('tar -tf ' . escapeshellarg($archive) . ' 2>&1', $out, $rc);
exec('kill ' . (int)$pid);
exec('rm -f ' . implode(' ', array_map('escapeshellarg', $paths)));
```

Not:

```php
exec("tar -tf $archive");                          // interpolated, unescaped
exec('tar -tf ' . $archive);                       // concatenated, unescaped
$cmd = 'tar -tf ' . $archive; exec($cmd);          // escaped nowhere, built elsewhere
exec('bash -c ' . escapeshellarg('ls ' . $dir));   // escaped once, parsed twice
```

### Escaped is not enough: option arguments

`escapeshellarg('--output=/etc/x')` is one argument, and the program receiving
it reads it as its own option. A value that may begin with `-` is either
validated against what it should be (a plugin name, an account name, a host)
or put after `--` where the program supports it:

```php
exec('rm -f -- ' . escapeshellarg($name));
```

### Strings parsed twice

`sh -c`, `bash -c`, `su -c` and `ssh host COMMAND` each take a string and parse
it again on the other side. Escaping the whole string once protects it from
the outer shell only; every value inside it has to be escaped for the inner
one as it is placed there. Prefer an argument list or a script file with
arguments over a composed `-c` string.

## Programs the web user runs as root

A helper the web user may run through `sudo` (an `/etc/sudoers.d/` rule written
by an installer) starts `#!/bin/bash` and sets its own search path before any
command:

```bash
#!/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin; export PATH
```

`#!/usr/bin/env bash` would find `bash` on the caller's path, and a tool called
by name would be found there too; the helper does not depend on `/etc/sudoers`
to choose either. Each sudoers rule names the helper's verbs, never a bare
command. `tests/unit/sudo_helper_search_path_test.php` finds every root sudoers
rule the tree writes and checks each helper it names.

## The test

`maintenance_scripts/dev_tools/list_shell_exec_sites.php` lists every call to
`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` and
`pcntl_exec` in runtime PHP and classes each one:

| Class | Meaning |
|---|---|
| CONST | the command is a literal |
| ARGV | `proc_open` given an argument list |
| ESCAPED | every variable is inside `escapeshellarg()`, `escapeshellcmd()`, `array_map('escapeshellarg', ...)`, an `(int)`/`(float)` cast or `intval()` |
| VAR | anything else: a command built on another line, an interpolation, an escaped string handed to `sh -c` |

```
cd public_html
php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php            # one row per call
php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php --summary  # counts
```

`tests/unit/shell_call_sites_test.php` holds a count of VAR calls per file,
each of which has been read and traced to where its values come from. A file
whose count rises fails the test. Write the new call as CONST, ARGV or
ESCAPED; if it truly has to be VAR (a command assembled across several lines),
read it the same way, trace every value to its source, and raise the count in
the same change. A count that falls is lowered too, so the room is not reused.
