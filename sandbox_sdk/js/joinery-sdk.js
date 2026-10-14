// Write a Joinery sandboxed plugin in JavaScript (docs/sandboxed_plugins.md).
//
// build.sh puts this file in front of your plugin's own and compiles the two
// with extism-js, so these are there for your code to use:
//
//   readCall()                      the call: { input, settings }
//   answer(value)                   your export's answer (any JSON value)
//   uiAnswer(message, errors, ok)   a form's answer: { message, errors, status }
//   api(method, path, body)         door 1: the site's API as this plugin
//   http(method, url, headers, body) door 5: HTTPS to an approved host
//   log(level, message)             a line in the plugin's call log
//
// Each export returns 0, after answer(); a door's refusal or failure throws an
// Error naming why.

const __joineryDoors = Host.getFunctions();

function __joineryDoor(name, request) {
  const mem = Memory.fromString(JSON.stringify(request));
  const offset = __joineryDoors[name](mem.offset);
  const reply = JSON.parse(Memory.find(offset).readString());
  if (reply && typeof reply.error === "string") {
    throw new Error(reply.error);
  }
  return reply;
}

function readCall() {
  const call = JSON.parse(Host.inputString());
  if (!call.settings || Array.isArray(call.settings)) {
    call.settings = {};
  }
  return call;
}

function answer(value) {
  Host.outputString(JSON.stringify(value));
  return 0;
}

function uiAnswer(message, errors, ok) {
  const value = { message: String(message || "") };
  if (errors && Object.keys(errors).length) {
    value.errors = errors;
  }
  if (ok === false || value.errors) {
    value.status = "error";
  }
  return answer(value);
}

function api(method, path, body) {
  return __joineryDoor("joinery_api", { method: method, path: path, body: body === undefined ? null : body });
}

function http(method, url, headers, body) {
  return __joineryDoor("joinery_http", { method: method, url: url, headers: headers || {}, body: body || "" });
}

function log(level, message) {
  __joineryDoor("joinery_log", { level: level, message: String(message) });
}
