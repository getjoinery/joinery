// Greeter: a small JavaScript sandboxed plugin. It answers a form on its admin
// page, counts the members through the site's API, and notes each signal in
// its call log.

function on_ui() {
  const call = readCall();
  if (call.input.handler !== "greet") {
    return uiAnswer("This form is not one the greeter knows.", null, false);
  }
  const name = String(call.input.values.name || "").trim();
  if (!name) {
    return uiAnswer("", { name: "Say who to greet." });
  }
  let members = "some";
  try {
    const listed = api("GET", "/api/v1/Users?numperpage=1");
    members = listed.body.num_results;
  } catch (e) {
    log("warn", "could not count members: " + e.message);
  }
  const greeting = (call.settings.greeting || "Hello") + ", " + name + "! This site has " + members + " members.";
  log("info", "greeted " + name);
  return uiAnswer(greeting);
}

function on_signal() {
  const call = readCall();
  log("info", "told about " + call.input.signal);
  return answer({ message: "noted " + call.input.signal });
}

module.exports = { on_ui, on_signal };
