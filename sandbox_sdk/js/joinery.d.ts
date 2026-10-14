// The three doors the plugin host gives a Joinery sandboxed plugin. build.sh
// adds a "main" block naming the exports your plugin's module.exports lists
// (on_ui, on_signal, on_schedule): extism-js needs exactly those.
declare module "extism:host" {
  interface user {
    joinery_api(request: PTR): PTR;
    joinery_http(request: PTR): PTR;
    joinery_log(request: PTR): PTR;
  }
}
