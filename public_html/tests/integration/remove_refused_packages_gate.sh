#!/bin/bash
# @joinery-test
# name: remove_refused_packages
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/_remove_refused_packages.sh, maintenance_scripts/install_tools/_install_declared_dependencies.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# _remove_refused_packages.sh takes off a machine the PHP extensions the
# deployed code refuses (RefusedDependencies: ImageMagick), at every root
# moment (specs/imagemagick_package_removal.md WP2). This gate pins its
# contract with stub dpkg-query and apt-get: with nothing installed apt is
# never called; an installed php*-imagick is simulated first and then removed,
# that package and no other; a removal that would take anything else with it
# is left alone and said; a package dpkg only remembers is not a package to
# remove; a held package is left; a warning is said once and again only when it
# changes; only the resolver's own recognisable lines are acted on, and only
# when it exits 0; an extension file still enabled is said; and it never exits
# non-zero.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/_remove_refused_packages.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
has() { case "$2" in *"$3"*) echo "  PASS: $1"; passed=$((passed+1)) ;; *) echo "  FAIL: $1 (not found: '$3' in '$2')"; failed=$((failed+1)) ;; esac; }

# Stubs. dpkg-query answers from $T/installed ("package want ok status" lines)
# for the pattern it is given; apt-get records every call and answers a
# simulation from $T/plan.
mkdir -p "$T/bin" "$T/etc"
cat > "$T/bin/dpkg-query" <<STUB
#!/bin/bash
pattern="\${@: -1}"
[ -f "$T/installed" ] || exit 1
while read -r pkg want ok status; do
    case "\$pkg" in \$pattern) echo "\$pkg \$want \$ok \$status" ;; esac
done < "$T/installed"
exit 0
STUB
cat > "$T/bin/apt-get" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
case " \$* " in
    *" -s "*) [ -f "$T/plan" ] && cat "$T/plan"; exit 0 ;;
esac
[ -n "\${STUB_APT_FAILS:-}" ] && { echo "E: Could not get lock"; exit 100; }
exit 0
STUB
chmod 755 "$T/bin/dpkg-query" "$T/bin/apt-get"

# Each run starts with no warning remembered, unless KEEP_STATE is set.
run() {
    : > "$T/calls"
    [ -n "${KEEP_STATE:-}" ] || rm -f "$T/state"
    PATH="$T/bin:$PATH" JOINERY_REFUSED_TEST=1 JOINERY_REFUSED_PHP_ETC="$T/etc" JOINERY_REFUSED_STATE="$T/state" bash "$SCRIPT" "${TREE:-$ROOT/public_html}" 2>&1
    echo "rc=$?"
}
calls() { grep -c . "$T/calls" 2>/dev/null || true; }

echo "== nothing to do costs no apt call =="
rm -f "$T/installed"
out="$(run)"
chk 'with no refused package installed it exits 0' "$(echo "$out" | tail -1)" 'rc=0'
chk 'and never calls apt' "$(calls)" '0'
chk 'and says nothing' "$(echo "$out" | grep -c 'refused-packages:')" '0'

echo "== an installed extension is simulated, then removed, alone =="
printf 'php8.5-imagick install ok installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0-3ubuntu1]\n' > "$T/plan"
out="$(run)"
has 'it says what it removed' "$out" 'removed php8.5-imagick'
chk 'two apt calls: the simulation and the removal' "$(calls)" '2'
has 'the first is a simulation' "$(sed -n 1p "$T/calls")" '-s remove --purge php8.5-imagick'
has 'the second removes that one package, waiting for the lock' "$(sed -n 2p "$T/calls")" 'DPkg::Lock::Timeout=300 remove --purge -y php8.5-imagick'
chk 'no call names autoremove' "$(grep -c autoremove "$T/calls")" '0'
chk 'and it exits 0' "$(echo "$out" | tail -1)" 'rc=0'

echo "== an older PHP's package of it goes too, and nothing else is looked at =="
printf 'php8.5-imagick install ok installed\nphp8.3-imagick install ok installed\nphp-imagick install ok installed\nphp8.5-gd install ok installed\nlibmagickwand-7.q16-10 install ok installed\n' > "$T/installed"
printf 'Purg php8.3-imagick [3.7.0]\n' > "$T/plan"
out="$(run)"
chk 'three packages are removed' "$(grep -c 'remove --purge -y' "$T/calls")" '3'
chk 'gd is never named' "$(grep -c 'php8.5-gd' "$T/calls")" '0'
chk 'nor a library' "$(grep -c 'libmagick' "$T/calls")" '0'

echo "== a removal that would take something else with it is left alone =="
printf 'php8.5-imagick install ok installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0]\nRemv some-site-package [1.0]\n' > "$T/plan"
out="$(run)"
has 'it says what else would go' "$out" 'would also remove: some-site-package'
chk 'and only the simulation ran' "$(calls)" '1'
chk 'exit 0' "$(echo "$out" | tail -1)" 'rc=0'

echo "== a simulation that answers nothing removes nothing =="
: > "$T/plan"
out="$(run)"
has 'it says it could not work it out' "$out" 'could not work out'
chk 'and only the simulation ran' "$(calls)" '1'

echo "== a package dpkg only remembers is not installed =="
printf 'php8.5-imagick deinstall ok config-files\nphp8.3-imagick unknown ok not-installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0]\n' > "$T/plan"
out="$(run)"
chk 'apt is not called for it' "$(calls)" '0'

echo "== a removal apt refuses is said and is not fatal =="
printf 'php8.5-imagick install ok installed\n' > "$T/installed"
out="$(STUB_APT_FAILS=1 run)"
has 'it says so, with apt'"'"'s last line' "$out" 'could not remove php8.5-imagick: E: Could not get lock'
chk 'exit 0' "$(echo "$out" | tail -1)" 'rc=0'

echo "== an extension file still enabled is said, for the web PHP and the command line =="
mkdir -p "$T/etc/8.5/fpm/conf.d" "$T/etc/8.5/cli/conf.d"
: > "$T/etc/8.5/fpm/conf.d/20-imagick.ini"
rm -f "$T/installed"
out="$(run)"
has 'the web PHP file is named' "$out" 'imagick is still enabled'
has 'by its path' "$out" '8.5/fpm/conf.d/20-imagick.ini'
rm -f "$T/etc/8.5/fpm/conf.d/20-imagick.ini"
out="$(run)"
chk 'and with none left nothing is said' "$(echo "$out" | grep -c 'still enabled')" '0'

echo "== a held package is left, and said =="
printf 'php8.5-imagick hold ok installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0]\n' > "$T/plan"
out="$(run)"
has 'it says the package is held' "$out" 'php8.5-imagick is held'
chk 'and apt is never called for it' "$(calls)" '0'

echo "== a warning is said once, and again only when it changes =="
printf 'php8.5-imagick install ok installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0]\nRemv some-site-package [1.0]\n' > "$T/plan"
out="$(run)"
chk 'the first run says it' "$(echo "$out" | grep -c 'would also remove')" '1'
out="$(KEEP_STATE=1 run)"
chk 'the next run, nothing changed, says nothing' "$(echo "$out" | grep -c 'refused-packages:')" '0'
printf 'Purg php8.5-imagick [3.8.0]\nRemv another-package [2.0]\n' > "$T/plan"
out="$(KEEP_STATE=1 run)"
has 'a changed warning is said' "$out" 'would also remove: another-package'
rm -f "$T/installed"
out="$(KEEP_STATE=1 run)"
chk 'and with nothing wrong the memory of it goes' "$([ -e "$T/state" ] && echo kept || echo gone)" 'gone'

echo "== only the resolver's own recognisable lines are acted on =="
# A resolver that also prints stray words (a PHP notice on stdout would): a bare
# word that happens to be an extension name must not become a package to remove.
mkdir -p "$T/tree/utils"
printf '<?php\necho "gd\\nzip\\nDeprecated: something in curl\\nrefuse-extension imagick\\nrefuse-extension ../x\\n refuse-extension xml\\n";\n' > "$T/tree/utils/list_dependencies.php"
printf 'php8.5-imagick install ok installed\nphp8.5-gd install ok installed\nphp8.5-zip install ok installed\nphp8.5-xml install ok installed\nphp8.5-curl install ok installed\n' > "$T/installed"
printf 'Purg php8.5-imagick [3.8.0]\n' > "$T/plan"
out="$(TREE="$T/tree" run)"
chk 'one package is removed' "$(grep -c 'remove --purge -y' "$T/calls")" '1'
has 'and it is imagick' "$(grep 'remove --purge -y' "$T/calls")" 'php8.5-imagick'
chk 'gd, zip, xml and curl are never named to apt' "$(grep -c -E 'php8.5-(gd|zip|xml|curl)' "$T/calls")" '0'
# A resolver that fails is not believed, whatever it printed first.
printf '<?php\necho "refuse-extension gd\\n"; exit(1);\n' > "$T/tree/utils/list_dependencies.php"
out="$(TREE="$T/tree" run)"
chk 'a resolver that exits non-zero removes nothing' "$(calls)" '0'

echo "== never fatal on a bad call =="
out="$(PATH="$T/bin:$PATH" JOINERY_REFUSED_TEST=1 bash "$SCRIPT" 2>&1; echo "rc=$?")"
chk 'no argument exits 0' "$(echo "$out" | tail -1)" 'rc=0'
out="$(PATH="$T/bin:$PATH" JOINERY_REFUSED_TEST=1 bash "$SCRIPT" /nonexistent 2>&1; echo "rc=$?")"
chk 'a path that is not there exits 0' "$(echo "$out" | tail -1)" 'rc=0'

echo "== it runs at every root moment =="
has 'the dependency installer runs it first' "$(grep -n '_remove_refused_packages.sh\|^SPECS=' "$ROOT/maintenance_scripts/install_tools/_install_declared_dependencies.sh" | grep -v '^[0-9]*:#' | head -1)" '_remove_refused_packages.sh'
# TOOLS_DIR, the site's copy: the converger's timer runs a root-owned copy of the
# runner from /usr/local/sbin, where SCRIPT_DIR holds none of the site's scripts.
has 'the converger tick runs it, from the site'\''s tools' "$(grep -c 'bash "${TOOLS_DIR}/_remove_refused_packages.sh"' "$ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh")" '1'
chk 'the resolver names what is refused, one recognisable line each' "$(php "$ROOT/public_html/utils/list_dependencies.php" --refused | tr '\n' ',')" 'refuse-extension gmagick,refuse-extension imagick,'

echo
echo "PASSED: $passed   FAILED: $failed   ($((passed+failed)) checks)"
[ "$failed" = 0 ]
