/*
 * FormWriter's image selector (FormWriterV2Base::imageselector()): the modal
 * that browses the site's images and sets a field's URL. Each field is a
 * .imageselector-wrapper carrying data-endpoint, data-pagesize, data-fieldname,
 * data-fieldid and data-placeholder; this file is loaded once per page by the
 * first selector on it.
 *
 * @version 1.0
 */
(function() {
    "use strict";

    // Image Selector Manager
    window.ImageSelectorManager = {
        modal: null,
        activeWrapper: null,
        images: [],
        offset: 0,
        hasMore: true,
        loading: false,
        searchTimeout: null,
        selectedUrl: null,

        init: function() {
            this.createModal();
            this.bindEvents();
        },

        createModal: function() {
            if (document.getElementById("imageselector-modal")) return;

            var modal = document.createElement("div");
            modal.id = "imageselector-modal";
            modal.className = "imageselector-modal";
            modal.innerHTML = '<div class="imageselector-modal-content">' +
                '<div class="imageselector-modal-header">' +
                    '<h5>Select Image</h5>' +
                    '<button type="button" class="imageselector-modal-close">&times;</button>' +
                '</div>' +
                '<div class="imageselector-search">' +
                    '<input type="text" placeholder="Search images..." class="imageselector-search-input">' +
                '</div>' +
                '<div class="imageselector-grid-container">' +
                    '<div class="imageselector-grid"></div>' +
                    '<div class="imageselector-loading" style="display:none;">Loading...</div>' +
                    '<div class="imageselector-empty" style="display:none;">No images found</div>' +
                    '<div class="imageselector-load-more" style="display:none;">' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm">Load More</button>' +
                    '</div>' +
                '</div>' +
                '<div class="imageselector-modal-footer">' +
                    '<button type="button" class="btn btn-secondary imageselector-cancel">Cancel</button>' +
                    '<button type="button" class="btn btn-primary imageselector-confirm" disabled>Select</button>' +
                '</div>' +
            '</div>';
            document.body.appendChild(modal);
            this.modal = modal;

            // Apply CSS variables from active wrapper when opening
            var gridContainer = modal.querySelector(".imageselector-grid-container");
            gridContainer.addEventListener("scroll", this.handleScroll.bind(this));
        },

        bindEvents: function() {
            var self = this;

            // Delegate click events for open buttons
            document.addEventListener("click", function(e) {
                // Open button
                if (e.target.classList.contains("imageselector-open")) {
                    var wrapper = e.target.closest(".imageselector-wrapper");
                    if (wrapper) self.open(wrapper);
                }
                // Clear button
                if (e.target.classList.contains("imageselector-clear")) {
                    var wrapper = e.target.closest(".imageselector-wrapper");
                    if (wrapper) self.clear(wrapper);
                }
            });

            // Modal events (delegated)
            document.addEventListener("click", function(e) {
                // Close button
                if (e.target.classList.contains("imageselector-modal-close")) {
                    self.close();
                }
                // Cancel button
                if (e.target.classList.contains("imageselector-cancel")) {
                    self.close();
                }
                // Confirm button
                if (e.target.classList.contains("imageselector-confirm")) {
                    self.confirm();
                }
                // Image item click
                if (e.target.closest(".imageselector-item")) {
                    self.selectImage(e.target.closest(".imageselector-item"));
                }
                // Load more button
                if (e.target.closest(".imageselector-load-more button")) {
                    self.loadMore();
                }
                // Close on backdrop click
                if (e.target.classList.contains("imageselector-modal")) {
                    self.close();
                }
            });

            // Search input
            document.addEventListener("input", function(e) {
                if (e.target.classList.contains("imageselector-search-input")) {
                    clearTimeout(self.searchTimeout);
                    self.searchTimeout = setTimeout(function() {
                        self.search(e.target.value);
                    }, 300);
                }
            });

            // Escape key closes modal
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape" && self.modal && self.modal.classList.contains("active")) {
                    self.close();
                }
            });
        },

        open: function(wrapper) {
            this.activeWrapper = wrapper;
            this.selectedUrl = null;
            this.offset = 0;
            this.hasMore = true;
            this.images = [];

            // Copy CSS variables from wrapper to modal
            var style = getComputedStyle(wrapper);
            this.modal.style.setProperty("--is-primary-color", style.getPropertyValue("--is-primary-color"));
            this.modal.style.setProperty("--is-grid-columns", style.getPropertyValue("--is-grid-columns"));
            this.modal.style.setProperty("--is-border-radius", style.getPropertyValue("--is-border-radius"));

            // Reset modal state; each field may word its own search prompt.
            var searchInput = this.modal.querySelector(".imageselector-search-input");
            searchInput.value = "";
            searchInput.placeholder = wrapper.dataset.placeholder || "Search images...";
            this.modal.querySelector(".imageselector-grid").innerHTML = "";
            this.modal.querySelector(".imageselector-confirm").disabled = true;

            // Show modal
            this.modal.classList.add("active");
            document.body.style.overflow = "hidden";

            // Load first batch
            this.loadImages();
        },

        close: function() {
            this.modal.classList.remove("active");
            document.body.style.overflow = "";
            this.activeWrapper = null;
        },

        loadImages: function(append) {
            if (this.loading) return;
            this.loading = true;

            var self = this;
            var wrapper = this.activeWrapper;
            var endpoint = wrapper.dataset.endpoint;
            var pageSize = parseInt(wrapper.dataset.pagesize) || 20;
            var searchValue = this.modal.querySelector(".imageselector-search-input").value;

            // API-action mode: an /api/v1/ endpoint speaks the JSON envelope —
            // POST {q, offset, limit} with the browser-session CSRF header and
            // read data.{images, total, hasMore}. Other endpoints keep the
            // legacy GET query-string contract returning a bare payload.
            var isApi = endpoint.indexOf("/api/v1/") === 0;
            var fetchPromise;
            if (isApi) {
                fetchPromise = joineryApi.post(endpoint, { q: searchValue, offset: self.offset, limit: pageSize })
                    .catch(function(err) { return { error: err.message || "Error loading images" }; });
            } else {
                var url = endpoint + "?limit=" + pageSize + "&offset=" + this.offset;
                if (searchValue) {
                    url += "&q=" + encodeURIComponent(searchValue);
                }
                fetchPromise = fetch(url).then(function(response) { return response.json(); });
            }

            // Show loading
            this.modal.querySelector(".imageselector-loading").style.display = "block";
            this.modal.querySelector(".imageselector-empty").style.display = "none";
            this.modal.querySelector(".imageselector-load-more").style.display = "none";

            fetchPromise
                .then(function(data) {
                    self.loading = false;
                    self.modal.querySelector(".imageselector-loading").style.display = "none";

                    if (data.error) {
                        self.modal.querySelector(".imageselector-empty").textContent = data.error;
                        self.modal.querySelector(".imageselector-empty").style.display = "block";
                        return;
                    }

                    self.hasMore = data.hasMore;

                    if (!append) {
                        self.images = data.images;
                        self.modal.querySelector(".imageselector-grid").innerHTML = "";
                    } else {
                        self.images = self.images.concat(data.images);
                    }

                    if (self.images.length === 0) {
                        self.modal.querySelector(".imageselector-empty").style.display = "block";
                        return;
                    }

                    self.renderImages(data.images);

                    if (self.hasMore) {
                        self.modal.querySelector(".imageselector-load-more").style.display = "block";
                    }
                })
                .catch(function(err) {
                    self.loading = false;
                    self.modal.querySelector(".imageselector-loading").style.display = "none";
                    self.modal.querySelector(".imageselector-empty").textContent = "Error loading images";
                    self.modal.querySelector(".imageselector-empty").style.display = "block";
                    console.error("ImageSelector error:", err);
                });
        },

        renderImages: function(images) {
            var grid = this.modal.querySelector(".imageselector-grid");
            var currentValue = this.activeWrapper.querySelector(".imageselector-value").value;

            images.forEach(function(img) {
                var item = document.createElement("div");
                item.className = "imageselector-item";
                item.dataset.url = img.url;
                item.dataset.filename = img.filename;
                item.title = img.title || img.filename;

                // Check if this is the currently selected image
                if (img.url === currentValue) {
                    item.classList.add("selected");
                    this.selectedUrl = img.url;
                    this.modal.querySelector(".imageselector-confirm").disabled = false;
                }

                var imgEl = document.createElement("img");
                imgEl.src = img.thumbnail;
                imgEl.alt = img.title || img.filename;
                imgEl.loading = "lazy";
                imgEl.dataset.fallback = img.url;
                imgEl.dataset.fallbackAttempted = "false";
                // Fallback to standard URL if thumbnail fails, then show placeholder if both fail
                imgEl.onerror = function() {
                    if (this.dataset.fallbackAttempted === "false" && this.dataset.fallback && this.src !== this.dataset.fallback) {
                        // First failure: try the standard URL
                        this.dataset.fallbackAttempted = "true";
                        this.src = this.dataset.fallback;
                    } else {
                        // Both thumbnail and standard URL failed - show placeholder
                        this.style.display = "none";
                        var placeholder = document.createElement("div");
                        placeholder.className = "is-placeholder-icon";
                        placeholder.innerHTML = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"48\" height=\"48\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><circle cx=\"8.5\" cy=\"8.5\" r=\"1.5\"/><polyline points=\"21 15 16 10 5 21\"/></svg><span>Image unavailable</span>";
                        this.parentNode.appendChild(placeholder);
                    }
                };

                item.appendChild(imgEl);
                grid.appendChild(item);
            }, this);
        },

        selectImage: function(item) {
            // Remove selection from all
            this.modal.querySelectorAll(".imageselector-item.selected").forEach(function(el) {
                el.classList.remove("selected");
            });

            // Select this one
            item.classList.add("selected");
            this.selectedUrl = item.dataset.url;

            // Enable confirm button
            this.modal.querySelector(".imageselector-confirm").disabled = false;
        },

        confirm: function() {
            if (!this.selectedUrl || !this.activeWrapper) return;

            var wrapper = this.activeWrapper;
            var valueInput = wrapper.querySelector(".imageselector-value");
            var preview = wrapper.querySelector(".imageselector-preview");
            var clearBtn = wrapper.querySelector(".imageselector-clear");
            var filenameEl = wrapper.querySelector(".imageselector-filename");

            // Update value
            valueInput.value = this.selectedUrl;

            // Update preview
            preview.innerHTML = '<img src="' + this.selectedUrl + '" alt="Selected image">';

            // Show clear button
            clearBtn.style.display = "";

            // Update filename
            var filename = this.selectedUrl.split("/").pop();
            filenameEl.textContent = filename;

            // Trigger change event
            valueInput.dispatchEvent(new Event("change", { bubbles: true }));

            this.close();
        },

        clear: function(wrapper) {
            var valueInput = wrapper.querySelector(".imageselector-value");
            var preview = wrapper.querySelector(".imageselector-preview");
            var clearBtn = wrapper.querySelector(".imageselector-clear");
            var filenameEl = wrapper.querySelector(".imageselector-filename");

            // Clear value
            valueInput.value = "";

            // Reset preview
            preview.innerHTML = '<div class="imageselector-no-preview"><i class="bx bx-image" style="font-size:24px;color:#ccc;"></i></div>';

            // Hide clear button
            clearBtn.style.display = "none";

            // Clear filename
            filenameEl.textContent = "";

            // Trigger change event
            valueInput.dispatchEvent(new Event("change", { bubbles: true }));
        },

        search: function(query) {
            this.offset = 0;
            this.hasMore = true;
            this.loadImages(false);
        },

        loadMore: function() {
            if (!this.hasMore || this.loading) return;
            var pageSize = parseInt(this.activeWrapper.dataset.pagesize) || 20;
            this.offset += pageSize;
            this.loadImages(true);
        },

        handleScroll: function(e) {
            var container = e.target;
            // Auto-load more when near bottom
            if (this.hasMore && !this.loading) {
                if (container.scrollHeight - container.scrollTop - container.clientHeight < 100) {
                    this.loadMore();
                }
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() {
            ImageSelectorManager.init();
        });
    } else {
        ImageSelectorManager.init();
    }
})();
