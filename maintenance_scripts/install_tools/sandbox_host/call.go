package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	extism "github.com/extism/go-sdk"
	"github.com/tetratelabs/wazero"
)

// The request core sends for one call (core's SandboxHost::envelope()).
type envelope struct {
	CallID        string          `json:"call_id"`
	Plugin        string          `json:"plugin"`
	Module        string          `json:"module"`
	Export        string          `json:"export"`
	Input         json.RawMessage `json:"input"`
	Limits        limits          `json:"limits"`
	Settings      json.RawMessage `json:"settings"`
	OutboundHosts []string        `json:"outbound_hosts"`
	API           *apiCredential  `json:"api"`
}

type limits struct {
	MemoryMB  int `json:"memory_mb"`
	TimeoutMS int `json:"timeout_ms"`
}

// The plugin's credential for door 1, and where the site is. The module never
// sees it: the host adds it to each API call the module makes.
type apiCredential struct {
	BaseURL   string `json:"base_url"`
	Hostname  string `json:"hostname"`
	PublicKey string `json:"public_key"`
	SecretKey string `json:"secret_key"`
}

// The answer for one call.
type answer struct {
	OK         bool            `json:"ok"`
	Output     json.RawMessage `json:"output"`
	Error      string          `json:"error,omitempty"`
	TimedOut   bool            `json:"timed_out"`
	DurationMS int64           `json:"duration_ms"`
	Calls      []doorCall      `json:"calls"`
}

// One thing the module asked a door for, and what became of it.
type doorCall struct {
	Door   string `json:"door"`
	Target string `json:"target"`
	Status string `json:"status"`
	Detail string `json:"detail"`
}

func refusal(message string) answer {
	return answer{OK: false, Output: json.RawMessage("null"), Error: message, Calls: []doorCall{}}
}

const (
	maxCallsLogged   = 200
	maxOutputBytes   = 512 << 10
	defaultMemoryMB  = 16
	maxMemoryMB      = 64
	defaultTimeoutMS = 5000
	maxTimeoutMS     = 10000
	minTimeoutMS     = 100
	compiledCacheMax = 16
	// The largest module the host loads (SandboxModule::MAX_BYTES in core).
	maxModuleBytes = 32 << 20
)

var (
	pluginNamePattern = regexp.MustCompile(`^[a-z][a-z0-9]*(_[a-z0-9]+)*$`)
	hostnamePattern   = regexp.MustCompile(`^(?i)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$`)
	exports           = map[string]bool{"on_ui": true, "on_signal": true, "on_schedule": true}
)

type host struct {
	pluginsDir string
	slots      chan struct{}
	running    int64

	mu       sync.Mutex
	compiled map[string]*cachedModule
	order    []string // least recently used first
	digests  map[string]string
}

type cachedModule struct {
	plugin *extism.CompiledPlugin
}

func newHost(pluginsDir string, maxCalls int) *host {
	if maxCalls < 1 {
		maxCalls = 1
	}
	return &host{
		pluginsDir: pluginsDir,
		slots:      make(chan struct{}, maxCalls),
		compiled:   map[string]*cachedModule{},
		digests:    map[string]string{},
	}
}

func (h *host) close() {
	h.mu.Lock()
	defer h.mu.Unlock()
	for _, m := range h.compiled {
		_ = m.plugin.Close(context.Background())
	}
	h.compiled = map[string]*cachedModule{}
}

// call runs one envelope, start to finish, and always answers.
func (h *host) call(parent context.Context, env envelope) answer {
	started := time.Now()
	module, problem := h.check(&env)
	if problem != "" {
		return refusal(problem)
	}

	select {
	case h.slots <- struct{}{}:
		defer func() { <-h.slots }()
	default:
		return refusal("The plugin host is busy with other calls.")
	}
	atomic.AddInt64(&h.running, 1)
	defer atomic.AddInt64(&h.running, -1)

	state := &callState{env: env}
	ans := h.run(parent, env, module, state)
	ans.DurationMS = time.Since(started).Milliseconds()
	ans.Calls = state.recorded()
	status := "ok"
	if !ans.OK {
		status = "error"
		if ans.TimedOut {
			status = "timeout"
		}
	}
	log.Printf("call %s plugin=%s export=%s status=%s ms=%d doors=%d", env.CallID, env.Plugin, env.Export, status, ans.DurationMS, len(ans.Calls))
	return ans
}

// check holds the envelope to what core may ask for: a module inside the
// plugins directory, one of the three exports, limits within bounds, hosts
// that are names, and a credential for this machine only.
func (h *host) check(env *envelope) (string, string) {
	if !pluginNamePattern.MatchString(env.Plugin) || len(env.Plugin) < 2 || len(env.Plugin) > 20 {
		return "", "The plugin name is not one a plugin can have."
	}
	if !exports[env.Export] {
		return "", "The export is not one a plugin answers."
	}
	module := filepath.Clean(env.Module)
	dir := filepath.Join(h.pluginsDir, env.Plugin) + string(filepath.Separator)
	if !strings.HasPrefix(module, dir) || filepath.Dir(module)+string(filepath.Separator) != dir || filepath.Ext(module) != ".wasm" {
		return "", "The module is not the plugin's own."
	}
	fi, err := os.Lstat(module)
	if err != nil || !fi.Mode().IsRegular() {
		return "", "The module is not there."
	}
	if fi.Size() > maxModuleBytes {
		return "", fmt.Sprintf("The module is larger than %d MB.", maxModuleBytes>>20)
	}

	if env.Limits.MemoryMB <= 0 {
		env.Limits.MemoryMB = defaultMemoryMB
	}
	if env.Limits.MemoryMB > maxMemoryMB {
		env.Limits.MemoryMB = maxMemoryMB
	}
	if env.Limits.TimeoutMS <= 0 {
		env.Limits.TimeoutMS = defaultTimeoutMS
	}
	if env.Limits.TimeoutMS < minTimeoutMS {
		env.Limits.TimeoutMS = minTimeoutMS
	}
	if env.Limits.TimeoutMS > maxTimeoutMS {
		env.Limits.TimeoutMS = maxTimeoutMS
	}

	for i, host := range env.OutboundHosts {
		host = strings.ToLower(strings.TrimSpace(host))
		if !hostnamePattern.MatchString(host) || net.ParseIP(host) != nil {
			return "", "An outbound host is not a host name."
		}
		env.OutboundHosts[i] = host
	}

	if env.API != nil {
		u, err := url.Parse(env.API.BaseURL)
		if err != nil || u.Scheme != "https" || u.Path != "" && u.Path != "/" {
			return "", "The API address is not the site's own."
		}
		ip := net.ParseIP(u.Hostname())
		if ip == nil || !ip.IsLoopback() {
			return "", "The API address is not on this machine."
		}
		name := env.API.Hostname
		if h, p, err := net.SplitHostPort(name); err == nil {
			if _, perr := strconv.Atoi(p); perr != nil {
				return "", "The site's name is not a host name."
			}
			name = h
		}
		if !hostnamePattern.MatchString(name) && name != "localhost" {
			return "", "The site's name is not a host name."
		}
		if env.API.PublicKey == "" || env.API.SecretKey == "" {
			env.API = nil
		}
	}
	if len(env.Input) == 0 {
		env.Input = json.RawMessage("{}")
	}
	if len(env.Settings) == 0 {
		env.Settings = json.RawMessage("{}")
	}
	return module, ""
}

// run makes a fresh instance of the module, calls the export with
// {"input": …, "settings": …}, and reads what it returned.
func (h *host) run(parent context.Context, env envelope, module string, state *callState) answer {
	timeout := time.Duration(env.Limits.TimeoutMS) * time.Millisecond
	ctx, cancel := context.WithTimeout(parent, timeout)
	defer cancel()
	ctx = context.WithValue(ctx, callStateKey{}, state)

	compiled, err := h.compiledFor(ctx, module, env.Limits)
	if err != nil && ctx.Err() != nil {
		return answer{OK: false, Output: json.RawMessage("null"), TimedOut: true, Error: "The plugin did not finish in time."}
	}
	if err != nil {
		return refusal("The module could not be loaded: " + err.Error())
	}
	instance, err := compiled.plugin.Instance(ctx, extism.PluginInstanceConfig{
		// Real time and real randomness: wazero's default random source is a
		// fixed sequence, which would hand every module the same "random"
		// tokens and nonces on every call.
		ModuleConfig: wazero.NewModuleConfig().WithSysWalltime().WithSysNanotime().WithRandSource(rand.Reader),
	})
	if err != nil {
		if ctx.Err() != nil {
			return answer{OK: false, Output: json.RawMessage("null"), TimedOut: true, Error: "The plugin did not finish in time."}
		}
		return refusal("The module could not be started: " + err.Error())
	}
	defer instance.Close(context.Background())
	if !instance.FunctionExists(env.Export) {
		return refusal("The module has no " + env.Export + ".")
	}

	payload, _ := json.Marshal(map[string]json.RawMessage{"input": env.Input, "settings": env.Settings})
	_, output, callErr := instance.CallWithContext(ctx, env.Export, payload)
	if ctx.Err() != nil {
		return answer{OK: false, Output: json.RawMessage("null"), TimedOut: true, Error: "The plugin did not finish in time."}
	}
	if callErr != nil {
		return answer{OK: false, Output: json.RawMessage("null"), Error: oneLine(callErr.Error(), 500)}
	}
	if len(output) > maxOutputBytes {
		return refusal(fmt.Sprintf("The plugin answered with more than %d KB.", maxOutputBytes>>10))
	}
	return answer{OK: true, Output: asJSON(output)}
}

// compiledFor compiles a module once per file version and memory limit, kept
// for the next call. Compiling is the slow part; an instance is cheap.
func (h *host) compiledFor(ctx context.Context, module string, lim limits) (*cachedModule, error) {
	fi, err := os.Stat(module)
	if err != nil {
		return nil, errors.New("unreadable")
	}
	// A file not changed since it was last read (same size and time) is not
	// read and hashed again.
	stat := fmt.Sprintf("%s|%d|%d", module, fi.Size(), fi.ModTime().UnixNano())
	h.mu.Lock()
	digest, known := h.digests[stat]
	h.mu.Unlock()
	var bytes []byte
	if !known {
		if bytes, err = os.ReadFile(module); err != nil || len(bytes) > maxModuleBytes {
			return nil, errors.New("unreadable, or too large")
		}
		sum := sha256.Sum256(bytes)
		digest = hex.EncodeToString(sum[:])
		h.mu.Lock()
		h.digests[stat] = digest
		h.mu.Unlock()
	}
	key := fmt.Sprintf("%s|%d", digest, lim.MemoryMB)

	h.mu.Lock()
	if m, ok := h.compiled[key]; ok {
		h.touch(key)
		h.mu.Unlock()
		return m, nil
	}
	h.mu.Unlock()
	if bytes == nil {
		if bytes, err = os.ReadFile(module); err != nil || len(bytes) > maxModuleBytes {
			return nil, errors.New("unreadable, or too large")
		}
	}

	manifest := extism.Manifest{
		Wasm:   []extism.Wasm{extism.WasmData{Data: bytes}},
		Memory: &extism.ManifestMemory{MaxPages: uint32(lim.MemoryMB) * 16},
		// Extism's own HTTP and file doors stay shut: no allowed hosts, no
		// paths. A module reaches out only through this host's doors.
		AllowedHosts: nil,
		AllowedPaths: nil,
		// A deadline, so wazero stops a module that will not return.
		Timeout: uint64(maxTimeoutMS),
	}
	config := extism.PluginConfig{
		// The interpreter, never the compiler: no generated machine code, so
		// the process keeps MemoryDenyWriteExecute.
		RuntimeConfig: wazero.NewRuntimeConfigInterpreter(),
		EnableWasi:    true,
	}
	// Compiled under the call's deadline: a module that takes too long to
	// compile is the call's to time out, not the host's to wait on.
	compiled, err := extism.NewCompiledPlugin(ctx, manifest, config, doorFunctions())
	if err != nil {
		return nil, errors.New(oneLine(err.Error(), 300))
	}
	m := &cachedModule{plugin: compiled}

	h.mu.Lock()
	defer h.mu.Unlock()
	if existing, ok := h.compiled[key]; ok {
		_ = compiled.Close(context.Background())
		return existing, nil
	}
	h.compiled[key] = m
	h.touch(key)
	for len(h.order) > compiledCacheMax {
		oldest := h.order[0]
		h.order = h.order[1:]
		if old, ok := h.compiled[oldest]; ok {
			delete(h.compiled, oldest)
			// A call may still be running on it; close it once that is done.
			go func(p *extism.CompiledPlugin) {
				time.Sleep(time.Duration(maxTimeoutMS+5000) * time.Millisecond)
				_ = p.Close(context.Background())
			}(old.plugin)
		}
	}
	return m, nil
}

// touch makes key the most recently used (h.mu held).
func (h *host) touch(key string) {
	for i, k := range h.order {
		if k == key {
			h.order = append(h.order[:i], h.order[i+1:]...)
			break
		}
	}
	h.order = append(h.order, key)
}

// asJSON returns the module's output as JSON: as it is when it is JSON, as a
// JSON string otherwise.
func asJSON(output []byte) json.RawMessage {
	if len(output) == 0 {
		return json.RawMessage("null")
	}
	if json.Valid(output) {
		return json.RawMessage(output)
	}
	s, _ := json.Marshal(string(output))
	return json.RawMessage(s)
}

func oneLine(s string, max int) string {
	s = strings.Join(strings.Fields(s), " ")
	if len(s) > max {
		s = s[:max]
	}
	return s
}
