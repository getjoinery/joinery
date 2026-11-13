/*
 * FormWriter's field scripts (includes/FormWriterV2Base.php), loaded once per
 * page by the first field that needs them. No field writes script of its own:
 * each carries what it needs as attributes, or as a JSON block beside it,
 * which the browser never runs (specs/strict_script_policy.md WP3).
 *
 *   - validation: <script type="application/json" data-jy-validate> per form,
 *     {form, rules, messages, errorSummary, ...}, started with JoineryValidator
 *     (assets/js/joinery-validate.js, loaded by the page);
 *   - visibility_rules: <script type="application/json" data-jy-visibility>
 *     per trigger, {type: select|checkbox|radio, id, group, rules};
 *   - time inputs: [data-time-hour] groups fill their hidden field;
 *   - repeaters: .repeater add and remove buttons;
 *   - colour pickers: .colorpicker-wrapper;
 *   - AJAX search selects: select[data-jy-ajax-select="endpoint"].
 *
 * @version 1.0
 */

// ── Validation ─────────────────────────────────────────────────────────────
(function() {
    "use strict";

    function readBlock(el) {
        try { return JSON.parse(el.textContent); } catch (e) { return null; }
    }

    // A form with data-ajax="true" posts by fetch and shows what comes back.
    function ajaxSubmit(form) {
        form.addEventListener("submit", function(e) {
            e.preventDefault();

            var formData = new FormData(form);

            fetch(form.action, {
                method: form.method,
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var callback = form.dataset.callback;
                    if (callback && window[callback]) {
                        window[callback](data);
                    } else {
                        alert(data.message || "Saved successfully");
                    }
                } else if (data.errors) {
                    Object.keys(data.errors).forEach(function(fieldName) {
                        var field = form.querySelector("[name='" + fieldName + "']");
                        if (field) {
                            var errorDiv = field.parentElement.querySelector(".error-message");
                            if (!errorDiv) {
                                errorDiv = document.createElement("div");
                                errorDiv.className = "error-message text-danger";
                                field.parentElement.appendChild(errorDiv);
                            }
                            errorDiv.textContent = data.errors[fieldName].join(", ");
                        }
                    });
                } else if (data.error) {
                    alert(data.error);
                }
            })
            .catch(error => {
                console.error("Form submission error:", error);
                alert("An error occurred. Please try again.");
            });
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('script[type="application/json"][data-jy-validate]').forEach(function(el) {
            var cfg = readBlock(el);
            if (!cfg) return;
            var form = document.getElementById(cfg.form);
            if (!form) return;
            if (cfg.debug) {
                console.log("=== FormWriterV2 DEBUG ===");
                console.log("Form ID:", cfg.form);
                if (cfg.modelFields) {
                    console.log("🔍 Automatic Model Validation Detected:");
                    Object.keys(cfg.modelFields).forEach(function(name) {
                        console.log("  ✓ " + name + " → Model: " + cfg.modelFields[name].model, cfg.modelFields[name].rules);
                    });
                }
                console.log("✓ Validation rules:", cfg.rules);
            }
            var options = {
                rules: cfg.rules || {},
                messages: cfg.messages || {},
                errorSummary: !!cfg.errorSummary,
                debug: !!cfg.debug
            };
            if (cfg.changedOnly) options.changedOnly = cfg.changedOnly;
            if (cfg.errorSummaryTitle) options.errorSummaryTitle = cfg.errorSummaryTitle;
            var validator = new JoineryValidator(form, options);
            // Exposed so page JS can take over submission (set
            // validator.submitHandler): the validator otherwise re-submits the
            // form natively when valid, which navigates over any page-level
            // submit interception.
            form.joineryValidator = validator;
            if (cfg.ajax) ajaxSubmit(form);
        });
    });
})();

// ── visibility_rules ───────────────────────────────────────────────────────
// One trigger's rules map its current key (a select's value, a checkbox's
// 'checked'/'unchecked', a radio group's chosen value) to fields to show and
// hide; 'default' covers any other key. A target is its {id}_container if
// there is one, else the element with that id.
(function() {
    "use strict";

    function target(id) {
        return document.getElementById(id + "_container") || document.getElementById(id);
    }

    function apply(rules) {
        (rules.show || []).forEach(function(id) {
            var el = target(id);
            if (!el) return;
            el.style.display = "";
            el.classList.remove("fw-field-hidden");
            setTimeout(function() { el.classList.add("fw-field-visible"); }, 10);
        });
        (rules.hide || []).forEach(function(id) {
            var el = target(id);
            if (!el) return;
            el.classList.remove("fw-field-visible");
            el.classList.add("fw-field-hidden");
            setTimeout(function() { el.style.display = "none"; }, 300);
        });
    }

    function wire(cfg) {
        var rulesFor = function(key) { return cfg.rules[key] || cfg.rules["default"] || {}; };
        if (cfg.type === "radio") {
            var radios = document.querySelectorAll('input[name="' + CSS.escape(cfg.group) + '"]');
            if (!radios.length) return;
            var update = function() {
                var checked = document.querySelector('input[name="' + CSS.escape(cfg.group) + '"]:checked');
                apply(rulesFor(checked ? checked.value : ""));
            };
            update();
            radios.forEach(function(r) { r.addEventListener("change", update); });
            return;
        }
        var el = document.getElementById(cfg.id);
        if (!el) return;
        var read = cfg.type === "checkbox"
            ? function() { return el.checked ? "checked" : "unchecked"; }
            : function() { return el.value; };
        var run = function() { apply(rulesFor(read())); };
        run();
        el.addEventListener("change", run);
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('script[type="application/json"][data-jy-visibility]').forEach(function(el) {
            var cfg;
            try { cfg = JSON.parse(el.textContent); } catch (e) { return; }
            if (cfg && cfg.rules) wire(cfg);
        });
    });
})();

// ── Time inputs ────────────────────────────────────────────────────────────
(function() {
    function updateTimeInput(hourId, minuteId, ampmId, hiddenId) {
        var hourEl = document.getElementById(hourId);
        var minuteEl = document.getElementById(minuteId);
        var ampmEl = document.getElementById(ampmId);
        var hiddenEl = document.getElementById(hiddenId);
        if (!hourEl || !minuteEl || !ampmEl || !hiddenEl) return;

        var hour = hourEl.value;
        var minute = minuteEl.value;
        var ampm = ampmEl.value;

        if (hour === "" || minute === "") {
            hiddenEl.value = "";
            return;
        }

        var h = parseInt(hour, 10);
        if (ampm === "PM" && h !== 12) h += 12;
        if (ampm === "AM" && h === 12) h = 0;

        hiddenEl.value = String(h).padStart(2, "0") + ":" + String(minute).padStart(2, "0");
    }

    function wireTimeInput(el) {
        var hourId = el.getAttribute("data-time-hour");
        var minuteId = el.getAttribute("data-time-minute");
        var ampmId = el.getAttribute("data-time-ampm");
        var hiddenId = el.getAttribute("data-time-hidden");
        var ids = [hourId, minuteId, ampmId];
        var update = function() { updateTimeInput(hourId, minuteId, ampmId, hiddenId); };
        ids.forEach(function(id) {
            var node = document.getElementById(id);
            if (!node) return;
            node.addEventListener("change", update);
            node.addEventListener("input", update);
        });
        // Normalize the hidden input on page load so server sees a clean value
        // even if the user submits without changing any of the parts.
        update();
    }

    document.addEventListener("DOMContentLoaded", function() {
        var timeInputs = document.querySelectorAll("[data-time-hour]");
        timeInputs.forEach(wireTimeInput);
    });
})();

// ── Repeaters ──────────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", function() {
    // Update button disabled states and row numbering
    function updateRepeaterState(repeater) {
        var items = repeater.querySelector(".repeater-items");
        var count = items.querySelectorAll(".repeater-row").length;
        var min = repeater.dataset.min ? parseInt(repeater.dataset.min) : null;
        var max = repeater.dataset.max ? parseInt(repeater.dataset.max) : null;

        // Disable add button at max
        var addBtn = repeater.querySelector(".repeater-add");
        if (addBtn) {
            addBtn.disabled = (max !== null && count >= max);
        }

        // Disable remove buttons at min
        var removeBtns = items.querySelectorAll(".repeater-remove");
        removeBtns.forEach(function(btn) {
            btn.disabled = (min !== null && count <= min);
        });

        // Re-number row labels (if item_label is used)
        var labels = items.querySelectorAll(".repeater-row-number");
        labels.forEach(function(label, i) {
            label.textContent = " " + (i + 1);
        });
    }

    // Add row - with max enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-add")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var max = repeater.dataset.max;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (max && currentCount >= parseInt(max)) {
                return;
            }

            var template = repeater.querySelector(".repeater-template");
            var nextIndex = currentCount;
            var clone = template.content.cloneNode(true);
            var row = clone.querySelector(".repeater-row");
            var html = row.outerHTML.replace(/__INDEX__/g, nextIndex);

            items.insertAdjacentHTML("beforeend", html);
            updateRepeaterState(repeater);
            var added = items.lastElementChild;
            if (added) {
                added.dispatchEvent(new CustomEvent("jy-repeater-row-added", { bubbles: true }));
            }
        }
    });

    // Remove row - with min enforcement
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-remove")) {
            var repeater = e.target.closest(".repeater");
            var items = repeater.querySelector(".repeater-items");
            var min = repeater.dataset.min;
            var currentCount = items.querySelectorAll(".repeater-row").length;

            if (min && currentCount <= parseInt(min)) {
                return;
            }

            e.target.closest(".repeater-row").remove();
            updateRepeaterState(repeater);
        }
    });

    // Initialize button states on page load for repeaters with min/max
    document.querySelectorAll(".repeater[data-min], .repeater[data-max]").forEach(updateRepeaterState);
});

// ── Colour pickers ─────────────────────────────────────────────────────────
(function() {
    "use strict";

    document.addEventListener("click", function(e) {
        // Expand button click
        if (e.target.closest(".colorpicker-expand-btn")) {
            e.preventDefault();
            var wrapper = e.target.closest(".colorpicker-wrapper");
            wrapper.classList.toggle("expanded");
            return;
        }

        // Swatch click
        if (e.target.classList.contains("colorpicker-swatch")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.dataset.color;
            selectColor(wrapper, color);
            wrapper.classList.remove("expanded");
            return;
        }

        // Show more link
        if (e.target.classList.contains("colorpicker-show-more")) {
            e.preventDefault();
            var wrapper = e.target.closest(".colorpicker-wrapper");
            wrapper.querySelectorAll(".colorpicker-hidden").forEach(function(el) {
                el.classList.remove("colorpicker-hidden");
            });
            e.target.style.display = "none";
            return;
        }

        // Click outside - close any open panels
        if (!e.target.closest(".colorpicker-wrapper")) {
            document.querySelectorAll(".colorpicker-wrapper.expanded").forEach(function(w) {
                w.classList.remove("expanded");
            });
        }
    });

    document.addEventListener("input", function(e) {
        // Native color picker change
        if (e.target.classList.contains("colorpicker-native")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.value;
            selectColor(wrapper, color);
        }

        // Hex input change
        if (e.target.classList.contains("colorpicker-hex-input")) {
            var wrapper = e.target.closest(".colorpicker-wrapper");
            var color = e.target.value.trim();
            if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(color)) {
                selectColor(wrapper, color, true);
            }
        }
    });

    function selectColor(wrapper, color, fromInput) {
        var valueInput = wrapper.querySelector(".colorpicker-value");
        var preview = wrapper.querySelector(".colorpicker-preview");
        var hexInput = wrapper.querySelector(".colorpicker-hex-input");
        var nativePicker = wrapper.querySelector(".colorpicker-native");

        // Update hidden value
        valueInput.value = color;

        // Update preview
        preview.style.backgroundColor = color;

        // Update hex input (unless triggered from it)
        if (!fromInput) {
            hexInput.value = color;
        }

        // Update native picker
        if (nativePicker) {
            // Expand 3-char hex for native picker
            var expandedColor = color;
            if (/^#[0-9a-fA-F]{3}$/.test(color)) {
                expandedColor = "#" + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
            }
            nativePicker.value = expandedColor;
        }

        // Update swatch selection
        wrapper.querySelectorAll(".colorpicker-swatch").forEach(function(swatch) {
            var swatchColor = swatch.dataset.color.toLowerCase();
            var selectedColor = color.toLowerCase();
            // Expand for comparison
            if (/^#[0-9a-fA-F]{3}$/.test(selectedColor)) {
                selectedColor = "#" + selectedColor[1] + selectedColor[1] + selectedColor[2] + selectedColor[2] + selectedColor[3] + selectedColor[3];
            }
            swatch.classList.toggle("selected", swatchColor === selectedColor);
        });

        // Trigger change event
        valueInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
})();

// ── AJAX search selects ────────────────────────────────────────────────────
(function() {
  class AjaxSearchSelect {
    constructor(selectEl, ajaxUrl) {
      this.select = selectEl;
      this.ajaxUrl = ajaxUrl;
      this.cache = {};
      this.debounceTimer = null;

      // API-action mode: an /api/v1/ endpoint speaks the JSON envelope — POST
      // {q, ...} with the browser-session CSRF header and read data.items. Any
      // query string on the configured URL (e.g. ?includenone=1) folds into the
      // POST body. Other URLs keep the legacy GET ?q= contract returning a bare
      // [{id, text}] array.
      this.isApi = ajaxUrl.indexOf('/api/v1/') === 0;
      var qpos = ajaxUrl.indexOf('?');
      this.baseUrl = qpos === -1 ? ajaxUrl : ajaxUrl.slice(0, qpos);
      this.extraParams = {};
      if (qpos !== -1) {
        new URLSearchParams(ajaxUrl.slice(qpos + 1)).forEach((v, k) => { this.extraParams[k] = v; });
      }

      const input = document.createElement('input');
      input.type = 'text';
      input.className = selectEl.className;
      input.placeholder = 'Type to search...';

      const list = document.createElement('datalist');
      list.id = selectEl.id + '_list';
      input.setAttribute('list', list.id);

      selectEl.style.display = 'none';
      selectEl.parentNode.insertBefore(input, selectEl);
      selectEl.parentNode.insertBefore(list, selectEl);

      this.input = input;
      this.list = list;
      this.data = [];

      if (selectEl.value) {
        input.value = selectEl.options[selectEl.selectedIndex].text;
      }

      input.addEventListener('input', (e) => this.search(e.target.value));
      input.addEventListener('change', (e) => {
        const inputVal = e.target.value.trim();
        if (!inputVal) {
          selectEl.value = '';
        } else {
          const matching = this.data.find(item => item.text === inputVal);
          if (matching) {
            let option = selectEl.querySelector('option[value="' + matching.id + '"]');
            if (!option) {
              option = document.createElement('option');
              option.value = matching.id;
              option.textContent = matching.text;
              selectEl.innerHTML = '';
              selectEl.appendChild(option);
            }
            selectEl.value = matching.id;
          }
        }
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }

    search(query) {
      clearTimeout(this.debounceTimer);
      if (query.length < 3) {
        this.list.innerHTML = '';
        this.data = [];
        return;
      }

      if (this.cache[query]) {
        this.updateList(this.cache[query]);
        return;
      }

      this.debounceTimer = setTimeout(() => {
        if (this.isApi) {
          joineryApi.post(this.baseUrl, Object.assign({}, this.extraParams, { q: query }))
            .then(data => {
              var items = (data && data.items) ? data.items : [];
              this.cache[query] = items;
              this.updateList(items);
            })
            .catch(() => {});
        } else {
          const separator = this.ajaxUrl.includes('?') ? '&' : '?';
          fetch(this.ajaxUrl + separator + 'q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(data => {
              this.cache[query] = data;
              this.updateList(data);
            });
        }
      }, 250);
    }

    updateList(data) {
      this.data = data;
      this.list.innerHTML = '';
      data.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.text;
        opt.dataset.id = item.id;
        this.list.appendChild(opt);
      });
    }
  }

  // Every select FormWriter marked with its endpoint (dropinput's ajaxendpoint).
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-jy-ajax-select]').forEach((select) => {
      new AjaxSearchSelect(select, select.getAttribute('data-jy-ajax-select'));
    });
  });
})();
