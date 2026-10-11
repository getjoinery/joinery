package main

import (
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync/atomic"
)

// SiteConfig is one site's file, sites/<site>.json in the config directory.
type SiteConfig struct {
	Site     string `json:"site"`
	Hostname string `json:"hostname"`
	// MailHostname is the name mail programs connect to and the certificate
	// names: the deployment's mail host name (its MX target), which reaches
	// this server even when the web name sits behind a CDN that carries web
	// ports only. Empty means the web name. Upstream calls always use
	// Hostname.
	MailHostname string `json:"mail_hostname"`
	// Upstream is the site's loopback base URL, e.g. "https://127.0.0.1:443"
	// or "http://127.0.0.1:8081". Requests carry Host: <hostname>, and an
	// https upstream is verified against <hostname> (SNI = hostname).
	Upstream string `json:"upstream"`
	// UpstreamInsecureTLS skips certificate verification of the upstream.
	// Only for an upstream whose certificate cannot name the hostname.
	UpstreamInsecureTLS bool   `json:"upstream_insecure_tls"`
	CertFile            string `json:"cert_file"`
	KeyFile             string `json:"key_file"`
}

// Site is a loaded, enabled site.
type Site struct {
	SiteConfig
	cert     *tls.Certificate
	upstream *Upstream
}

type siteSet struct {
	byHost map[string]*Site
	list   []*Site
}

// Registry holds the enabled sites. Reload swaps the whole set atomically, so
// a connection in progress keeps the Site it started with.
type Registry struct {
	dir string
	cur atomic.Pointer[siteSet]
}

func NewRegistry(dir string) *Registry {
	r := &Registry{dir: dir}
	r.cur.Store(&siteSet{byHost: map[string]*Site{}})
	return r
}

var siteNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)

// Reload reads sites/*.json, keeps those with a sites/<site>.enabled marker,
// and loads each one's certificate. A site that fails to load is skipped and
// reported; the rest still serve. An unreadable directory keeps the old set.
func (r *Registry) Reload() error {
	sitesDir := filepath.Join(r.dir, "sites")
	entries, err := os.ReadDir(sitesDir)
	if err != nil {
		return fmt.Errorf("reading %s: %w", sitesDir, err)
	}
	set := &siteSet{byHost: map[string]*Site{}}
	var problems []string
	for _, e := range entries {
		name := e.Name()
		if e.IsDir() || !strings.HasSuffix(name, ".json") {
			continue
		}
		siteName := strings.TrimSuffix(name, ".json")
		if !siteNameRe.MatchString(siteName) {
			problems = append(problems, fmt.Sprintf("%s: not a site name", name))
			continue
		}
		if _, err := os.Stat(filepath.Join(sitesDir, siteName+".enabled")); err != nil {
			continue // not turned on
		}
		site, err := loadSite(filepath.Join(sitesDir, name), siteName)
		if err != nil {
			problems = append(problems, fmt.Sprintf("%s: %v", siteName, err))
			continue
		}
		host := site.ServedName()
		if other, dup := set.byHost[host]; dup {
			problems = append(problems, fmt.Sprintf("%s: hostname %s already served by %s", siteName, host, other.Site))
			continue
		}
		set.byHost[host] = site
		set.list = append(set.list, site)
	}
	sort.Slice(set.list, func(i, j int) bool { return set.list[i].Site < set.list[j].Site })
	r.cur.Store(set)
	for _, p := range problems {
		logf("config: site skipped: %s", p)
	}
	for _, s := range set.list {
		logf("config: serving %s as %s -> %s (%s)", s.Site, s.ServedName(), s.Upstream, s.Hostname)
	}
	return nil
}

func loadSite(path, siteName string) (*Site, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var cfg SiteConfig
	if err := json.Unmarshal(b, &cfg); err != nil {
		return nil, fmt.Errorf("parsing: %w", err)
	}
	if cfg.Site == "" {
		cfg.Site = siteName
	}
	if cfg.Site != siteName {
		return nil, fmt.Errorf("file names site %q but is %s.json", cfg.Site, siteName)
	}
	cfg.Hostname = strings.ToLower(strings.TrimSpace(cfg.Hostname))
	if cfg.Hostname == "" || strings.ContainsAny(cfg.Hostname, "/: ") {
		return nil, errors.New("hostname missing or not a host name")
	}
	cfg.MailHostname = strings.ToLower(strings.TrimSpace(cfg.MailHostname))
	if strings.ContainsAny(cfg.MailHostname, "/: ") {
		return nil, errors.New("mail_hostname is not a host name")
	}
	if cfg.Upstream == "" {
		return nil, errors.New("upstream missing")
	}
	cert, err := loadCert(cfg)
	if err != nil {
		return nil, err
	}
	up, err := NewUpstream(cfg)
	if err != nil {
		return nil, err
	}
	return &Site{SiteConfig: cfg, cert: cert, upstream: up}, nil
}

// loadCert prefers systemd credentials ($CREDENTIALS_DIRECTORY/<site>.crt and
// .key, LoadCredential=) over the paths in the site file.
func loadCert(cfg SiteConfig) (*tls.Certificate, error) {
	certFile, keyFile := cfg.CertFile, cfg.KeyFile
	if dir := os.Getenv("CREDENTIALS_DIRECTORY"); dir != "" {
		c := filepath.Join(dir, cfg.Site+".crt")
		k := filepath.Join(dir, cfg.Site+".key")
		if fileExists(c) && fileExists(k) {
			certFile, keyFile = c, k
		}
	}
	if certFile == "" || keyFile == "" {
		return nil, errors.New("no certificate: set cert_file and key_file, or pass <site>.crt/.key as credentials")
	}
	cert, err := tls.LoadX509KeyPair(certFile, keyFile)
	if err != nil {
		return nil, fmt.Errorf("certificate: %w", err)
	}
	return &cert, nil
}

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}

// Count is the number of enabled sites.
func (r *Registry) Count() int { return len(r.cur.Load().list) }

// Lookup picks the site for a TLS server name. No name and exactly one site
// is that site; anything else must match a hostname exactly.
func (r *Registry) Lookup(serverName string) *Site {
	set := r.cur.Load()
	serverName = strings.ToLower(strings.TrimSuffix(serverName, "."))
	if serverName == "" {
		if len(set.list) == 1 {
			return set.list[0]
		}
		return nil
	}
	return set.byHost[serverName]
}

// ByName is the enabled site with this name, or nil.
func (r *Registry) ByName(name string) *Site {
	for _, s := range r.cur.Load().list {
		if s.Site == name {
			return s
		}
	}
	return nil
}

// ServedName is the name programs reach the site by: the mail host name,
// or the web name when no mail host name is configured.
func (s *Site) ServedName() string {
	if s.MailHostname != "" {
		return s.MailHostname
	}
	return s.Hostname
}

// DefaultDomain is the name for the SMTP greeting.
func (r *Registry) DefaultDomain() string {
	set := r.cur.Load()
	if len(set.list) == 1 {
		return set.list[0].ServedName()
	}
	if h, err := os.Hostname(); err == nil && h != "" {
		return h
	}
	return "localhost"
}

// TLSConfig picks the certificate by SNI and fails the handshake for a name
// no enabled site serves. Implicit TLS only, TLS 1.2 and up.
func (r *Registry) TLSConfig() *tls.Config {
	return &tls.Config{
		MinVersion: tls.VersionTLS12,
		GetCertificate: func(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
			site := r.Lookup(hello.ServerName)
			if site == nil {
				return nil, fmt.Errorf("no site serves %q", hello.ServerName)
			}
			return site.cert, nil
		},
	}
}
