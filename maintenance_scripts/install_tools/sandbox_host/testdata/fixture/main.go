// A sandboxed plugin module for the host's tests. Each export reads the call's
// input ({"input": …, "settings": …}) and does what input.handler (or the
// signal or schedule name) says, so one module exercises every door and limit.
package main

import (
	cryptorand "crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"strconv"
	"strings"

	"github.com/extism/go-pdk"
)

//go:wasmimport extism:host/user joinery_api
func joineryAPI(uint64) uint64

//go:wasmimport extism:host/user joinery_http
func joineryHTTP(uint64) uint64

//go:wasmimport extism:host/user joinery_log
func joineryLog(uint64) uint64

type call struct {
	Input    map[string]any    `json:"input"`
	Settings map[string]string `json:"settings"`
}

func door(f func(uint64) uint64, request any) map[string]any {
	mem, _ := pdk.AllocateJSON(request)
	out := pdk.FindMemory(f(mem.Offset()))
	var answer map[string]any
	_ = json.Unmarshal(out.ReadBytes(), &answer)
	return answer
}

var sink [][]byte

func run(what string, c call) int32 {
	switch {
	case what == "echo" || what == "digest" || strings.Contains(what, "."):
		_ = pdk.OutputJSON(map[string]any{"message": "echo", "got": c.Input, "settings": c.Settings})
	case what == "api":
		answer := door(joineryAPI, map[string]any{"method": "GET", "path": c.Input["values"].(map[string]any)["path"]})
		message := "The API answered"
		if status, ok := answer["status"].(float64); ok {
			message += " " + strconv.Itoa(int(status))
		} else if e, ok := answer["error"].(string); ok {
			message = "The API door said: " + e
		}
		_ = pdk.OutputJSON(map[string]any{"message": message, "answer": answer})
	case what == "http":
		_ = pdk.OutputJSON(door(joineryHTTP, map[string]any{"method": "POST", "url": c.Input["values"].(map[string]any)["url"], "body": "hi"}))
	case what == "random":
		b := make([]byte, 16)
		_, _ = cryptorand.Read(b)
		_ = pdk.OutputJSON(map[string]any{"message": hex.EncodeToString(b)})
	case what == "log":
		door(joineryLog, map[string]any{"level": "info", "message": "hello from the module"})
		_ = pdk.OutputJSON(map[string]any{"message": "logged"})
	case what == "spin":
		for {
		}
	case what == "alloc":
		for i := 0; i < 1000; i++ {
			sink = append(sink, make([]byte, 1<<20))
		}
		_ = pdk.OutputJSON(map[string]any{"message": "allocated"})
	case what == "fail":
		pdk.SetError(errors.New("the module refused"))
		return 1
	case what == "noisy":
		pdk.OutputString(strings.Repeat("x", 2<<20))
	}
	return 0
}

func handler(c call) string {
	if h, ok := c.Input["handler"].(string); ok {
		return h
	}
	if s, ok := c.Input["signal"].(string); ok {
		return s
	}
	if s, ok := c.Input["schedule"].(string); ok {
		return s
	}
	return ""
}

func read() call {
	var c call
	_ = pdk.InputJSON(&c)
	return c
}

//go:wasmexport on_ui
func onUI() int32 { c := read(); return run(handler(c), c) }

//go:wasmexport on_signal
func onSignal() int32 { c := read(); return run(handler(c), c) }

//go:wasmexport on_schedule
func onSchedule() int32 { c := read(); return run(handler(c), c) }

func main() {}
