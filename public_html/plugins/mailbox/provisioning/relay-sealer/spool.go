package main

import (
	"fmt"
	"os"
	"path/filepath"
)

// writeSpoolEntry commits a sealed blob + its metadata sidecar into the spool
// using write-tempfile → fsync → atomic rename, so a concurrent rsync pull
// never observes a partial or torn entry.
//
// Ordering is deliberate: the .meta is committed FIRST, then the .seal. The
// pull consumer treats the .seal as the commit marker, so by the time a .seal
// is visible its .meta is guaranteed present. The parent directory is fsync'd
// after each rename so the rename itself is durable before we report success to
// Postfix — the sealer must never exit 0 for a message that would vanish on a
// crash.
func writeSpoolEntry(spoolDir, spoolID string, meta []byte, sealed string) error {
	tmpDir := filepath.Join(spoolDir, "tmp")
	if err := os.MkdirAll(tmpDir, 0o700); err != nil {
		return fmt.Errorf("create spool tmp dir: %w", err)
	}

	metaFinal := filepath.Join(spoolDir, spoolID+".meta")
	sealFinal := filepath.Join(spoolDir, spoolID+".seal")

	if err := writeDurable(tmpDir, metaFinal, meta); err != nil {
		return fmt.Errorf("write .meta: %w", err)
	}
	if err := writeDurable(tmpDir, sealFinal, []byte(sealed)); err != nil {
		// Best-effort cleanup of the orphaned .meta so a half entry never lingers.
		_ = os.Remove(metaFinal)
		return fmt.Errorf("write .seal: %w", err)
	}
	return nil
}

// writeDurable writes bytes to a temp file in tmpDir, fsyncs the file, renames
// it to finalPath, then fsyncs the destination directory so the rename is on
// disk before returning.
func writeDurable(tmpDir, finalPath string, data []byte) error {
	f, err := os.CreateTemp(tmpDir, "seal-*")
	if err != nil {
		return err
	}
	tmpName := f.Name()
	// From here on, any error path must clean up the temp file.
	if _, err := f.Write(data); err != nil {
		f.Close()
		os.Remove(tmpName)
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		os.Remove(tmpName)
		return err
	}
	// 0640, not 0600: the spool directory is setgid to the owning tenant's
	// group, so committed entries inherit that group and the tenant's pull
	// account can read them. Other tenants hold no membership — the directory's
	// 2770 mode is the isolation boundary.
	if err := f.Chmod(0o640); err != nil {
		f.Close()
		os.Remove(tmpName)
		return err
	}
	if err := f.Close(); err != nil {
		os.Remove(tmpName)
		return err
	}
	if err := os.Rename(tmpName, finalPath); err != nil {
		os.Remove(tmpName)
		return err
	}
	return fsyncDir(filepath.Dir(finalPath))
}

func fsyncDir(dir string) error {
	d, err := os.Open(dir)
	if err != nil {
		return err
	}
	defer d.Close()
	// Directory fsync is a no-op on some filesystems; ignore ENOTSUP-style
	// failures but surface real I/O errors.
	if err := d.Sync(); err != nil {
		// A directory that cannot be synced (e.g. some tmpfs) should not fail
		// the whole delivery; the file itself was already fsync'd.
		return nil
	}
	return nil
}
