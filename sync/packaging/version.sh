# Sourced by the macOS and Linux packaging scripts. Sets:
#
#   JD_VERSION          the workspace version from sync/Cargo.toml, as written:
#                       MAJOR.MINOR.PATCH, optionally -alpha.N / -beta.N / -rc.N
#   JD_VERSION_NUMERIC  MAJOR.MINOR.PATCH alone, for the fields that take only
#                       numbers (CFBundleShortVersionString, CFBundleVersion)
#
# The workspace version is the only place a version is written. Every crate
# inherits it, the binaries print it (`--version`) and send it to the server's
# upgrade gate as client-version, and every installer is stamped with it here.
# It is read through `cargo metadata` rather than by parsing Cargo.toml, so the
# answer is the one cargo itself compiled into the binaries.

jd_read_version() {
	local sync_dir="$1"
	cargo metadata --no-deps --format-version 1 --manifest-path "$sync_dir/Cargo.toml" |
		python3 -c 'import json, sys
packages = json.load(sys.stdin)["packages"]
print(next(p["version"] for p in packages if p["name"] == "jd-daemon"))'
}

JD_VERSION="$(jd_read_version "${JD_SYNC_DIR:?JD_SYNC_DIR must name the sync workspace}")"

# The same grammar the jd-proto test `the_version_is_one_the_gate_can_order`
# holds the workspace to: PHP's version_compare() orders these correctly.
if ! [[ "$JD_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)(\.[0-9]+)?)?$ ]]; then
	echo "error: workspace version '$JD_VERSION' is not MAJOR.MINOR.PATCH[-alpha|beta|rc.N]" >&2
	exit 1
fi
JD_VERSION_NUMERIC="${JD_VERSION%%-*}"
export JD_VERSION JD_VERSION_NUMERIC

# A built binary must report the version being stamped on its package. A stale
# target directory, or a binary copied from somewhere else, fails here instead
# of shipping under the wrong number.
jd_check_binary_version() {
	local binary="$1" name="$2"
	local said
	said="$("$binary" --version)"
	if [ "$said" != "$name $JD_VERSION" ]; then
		echo "error: $binary says '$said', expected '$name $JD_VERSION'" >&2
		exit 1
	fi
}
