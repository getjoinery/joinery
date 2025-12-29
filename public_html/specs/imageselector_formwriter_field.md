# Specification: Image Selector FormWriter Field

**Status:** DRAFT
**Priority:** Medium
**Estimated Effort:** 3-4 hours
**Date Created:** 2025-12-29

---

## 1. Overview

Create a new `imageselector` FormWriter field type that provides a visual image picker for selecting from uploaded images in the files system. The field displays a modal with a lazy-loaded grid of image thumbnails and returns the selected image's URL.

**Primary Use Case:** Component configuration fields that need image URLs (e.g., hero backgrounds, feature images, gallery items).

---

## 2. Project Goals

### Primary Objectives
1. Create `imageselector` method in FormWriterV2Base (HTML5 compatible)
2. Lazy load images to prevent page freezing with large image libraries
3. Return image URL (not file ID) as the field value
4. Provide search/filter capability
5. Show preview of currently selected image
6. Support use within repeater fields

### Success Metrics
- Field works in admin_component_edit for image configuration
- Page doesn't freeze when opening selector with 100+ images
- Selected image URL correctly saved and restored on edit
- Works inside repeater fields
- PHP syntax validation passes
- No external dependencies (pure HTML5/CSS/vanilla JS)

---

## 3. Technical Design

### 3.1 User Interface

**Field Display (Collapsed State):**
```
┌─────────────────────────────────────────────────────┐
│ [Label]                                             │
│ ┌─────────────┐  ┌──────────────────┐              │
│ │             │  │ Select Image     │              │
│ │  [Preview]  │  └──────────────────┘              │
│ │   100x100   │  Current: /uploads/hero.jpg        │
│ │             │  [Clear]                           │
│ └─────────────┘                                    │
│ Help text here                                      │
└─────────────────────────────────────────────────────┘
```

**Modal (Expanded State):**
```
┌─────────────────────────────────────────────────────┐
│ Select Image                                    [X] │
├─────────────────────────────────────────────────────┤
│ [Search images...                              ] 🔍 │
├─────────────────────────────────────────────────────┤
│ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐ │
│ │       │ │       │ │       │ │       │ │       │ │
│ │ img1  │ │ img2  │ │ img3  │ │ img4  │ │ img5  │ │
│ │       │ │       │ │       │ │       │ │       │ │
│ └───────┘ └───────┘ └───────┘ └───────┘ └───────┘ │
│ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐ │
│ │       │ │       │ │       │ │       │ │       │ │
│ │ img6  │ │ img7  │ │ img8  │ │ img9  │ │ img10 │ │
│ │       │ │       │ │       │ │       │ │       │ │
│ └───────┘ └───────┘ └───────┘ └───────┘ └───────┘ │
│                                                     │
│              [Load More Images...]                  │
│                   or infinite scroll                │
├─────────────────────────────────────────────────────┤
│                              [Cancel] [Select]      │
└─────────────────────────────────────────────────────┘
```

### 3.2 Data Flow

```
1. User clicks "Select Image" button
2. Modal opens, AJAX request fetches first batch (20 images)
3. Images display with loading="lazy" attribute
4. User scrolls → Intersection Observer triggers next batch load
5. User clicks image → image highlighted as selected
6. User clicks "Select" → modal closes, URL stored in hidden input
7. Preview updates to show selected image
8. Form submission sends URL value
```

### 3.3 Component Architecture

**Files to Create:**
1. `/ajax/image_list_ajax.php` - Paginated image list endpoint

**Files to Modify:**
1. `/includes/FormWriterV2Base.php` - Add `imageselector()` method (complete implementation)
2. `/adm/admin_component_edit.php` - Use new field type for image fields

**Design Philosophy:**
- Single complete implementation in FormWriterV2Base
- No abstract method - works out of the box for all themes
- Styling customizable via options (no theme override required)
- Themes CAN override the entire method if they need completely different markup

### 3.4 AJAX Endpoint Specification

**Endpoint:** `/ajax/image_list_ajax`

**Request Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `q` | string | '' | Search term (matches filename, title) |
| `offset` | int | 0 | Pagination offset |
| `limit` | int | 20 | Images per page |

**Response Format:**
```json
{
  "images": [
    {
      "id": 123,
      "url": "/uploads/image1.jpg",
      "thumbnail": "/uploads/thumbnail/image1.jpg",
      "title": "Image Title",
      "filename": "image1.jpg"
    }
  ],
  "total": 150,
  "hasMore": true
}
```

### 3.5 FormWriter Method Signature

```php
/**
 * Create an image selector field with modal picker
 *
 * Complete implementation in base class - works for all themes out of the box.
 * Themes can override this method entirely if completely different markup is needed.
 *
 * @param string $name Field name
 * @param string $label Field label
 * @param array $options Field options:
 *
 *   Core Options:
 *   - value: Current image URL
 *   - help: Help text
 *   - required: Boolean
 *   - placeholder: Placeholder text for search (default: 'Search images...')
 *   - thumbnail_size: 'thumbnail'|'lthumbnail'|'small' (default: 'thumbnail')
 *   - ajax_endpoint: Custom endpoint URL (default: '/ajax/image_list_ajax')
 *   - page_size: Images per AJAX load (default: 20)
 *
 *   Styling Options (all optional - sensible defaults provided):
 *   - button_class: CSS class for select button (default: 'btn btn-outline-secondary')
 *   - button_text: Button label (default: 'Select Image')
 *   - modal_class: Additional CSS class for modal container
 *   - grid_columns: Number of columns in image grid (default: 5)
 *   - thumbnail_width: Thumbnail display width in px (default: 100)
 *   - preview_width: Preview image width in px (default: 100)
 *   - primary_color: Selection highlight color (default: '#0d6efd')
 *   - border_radius: Border radius for thumbnails (default: '4px')
 */
public function imageselector($name, $label = '', $options = [])
```

**Basic Usage:**
```php
$formwriter->imageselector('background_image', 'Background Image', [
    'help' => 'Select a background image for this section',
    'value' => $current_config['background_image'] ?? ''
]);
```

**Custom Styling Example:**
```php
$formwriter->imageselector('hero_image', 'Hero Image', [
    'value' => $current_config['hero_image'] ?? '',
    'button_class' => 'btn btn-primary btn-sm',
    'button_text' => 'Choose Hero Image',
    'grid_columns' => 4,
    'thumbnail_width' => 120,
    'primary_color' => '#d80650',
    'border_radius' => '8px'
]);
```

### 3.6 Component Schema Integration

Update component type schemas to use new field type:

```json
{
  "name": "background_image",
  "label": "Background Image",
  "type": "imageselector",
  "help": "Select background image"
}
```

---

## 4. Implementation Details

### 4.1 Lazy Loading Strategy

**Initial Load:**
- Fetch first 20 images on modal open
- Use `loading="lazy"` on all `<img>` elements
- Display skeleton placeholders while loading

**Infinite Scroll:**
- Intersection Observer watches sentinel element at bottom
- When sentinel enters viewport, fetch next batch
- Append new images to grid
- Update sentinel position
- Stop when `hasMore: false`

**Search Debouncing:**
- 300ms debounce on search input
- Clear grid and reset offset on new search
- Show loading indicator during search

### 4.2 HTML5 Compatibility

All functionality uses standard HTML5/CSS3/ES6:
- Native `<dialog>` element for modal (with polyfill pattern for older browsers)
- CSS Grid for image layout
- Intersection Observer API for lazy loading
- Fetch API for AJAX
- No jQuery or external libraries

**Browser Support:**
- Chrome 80+
- Firefox 75+
- Safari 13+
- Edge 80+

### 4.3 Accessibility

- Modal traps focus when open
- Escape key closes modal
- Arrow keys navigate image grid
- Enter selects focused image
- Screen reader announcements for selection
- Alt text on all images

### 4.4 CSS Styling

Inline CSS in FormWriter output with option-driven values:

```css
/* CSS uses inline style variables set from PHP options */
.imageselector-{$id} {
  --is-primary-color: {$primary_color};      /* from options['primary_color'] */
  --is-thumbnail-width: {$thumbnail_width}px; /* from options['thumbnail_width'] */
  --is-border-radius: {$border_radius};       /* from options['border_radius'] */
  --is-grid-columns: {$grid_columns};         /* from options['grid_columns'] */
}

.imageselector-modal {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}
.imageselector-modal-content {
  background: #fff;
  border-radius: var(--is-border-radius);
  max-width: 800px;
  max-height: 80vh;
  width: 90%;
  display: flex;
  flex-direction: column;
}
.imageselector-grid {
  display: grid;
  grid-template-columns: repeat(var(--is-grid-columns), 1fr);
  gap: 10px;
  padding: 15px;
  overflow-y: auto;
}
.imageselector-item {
  aspect-ratio: 1;
  cursor: pointer;
  border: 2px solid transparent;
  border-radius: var(--is-border-radius);
  overflow: hidden;
}
.imageselector-item:hover,
.imageselector-item.selected {
  border-color: var(--is-primary-color);
}
.imageselector-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.imageselector-preview img {
  max-width: var(--is-thumbnail-width);
  max-height: var(--is-thumbnail-width);
  border-radius: var(--is-border-radius);
}
```

**Neutral Default Colors:**
- Primary/selection: `#0d6efd` (standard blue, Bootstrap-compatible)
- Background: `#fff`
- Border: `#dee2e6`
- Text: `inherit` (uses page default)

These defaults work well in any theme but can all be overridden via options.

---

## 5. Implementation Steps

### Phase 1: AJAX Endpoint
1. Create `/ajax/image_list_ajax.php`
2. Implement pagination with MultiFile
3. Filter for images only (`picture` => true)
4. Support search by filename/title
5. Return JSON with URLs and thumbnails

### Phase 2: FormWriter Base Method (Single Complete Implementation)
1. Add `imageselector()` method to FormWriterV2Base
2. Include complete implementation:
   - Field registration
   - Inline modal HTML structure
   - Inline CSS with option-based customization
   - Inline JavaScript for:
     - Modal open/close
     - AJAX image loading
     - Infinite scroll with Intersection Observer
     - Search with debounce
     - Selection handling
     - Preview update
3. All styling values pulled from options with sensible defaults
4. No abstract method - no changes needed to theme FormWriters

### Phase 3: Integration
1. Update admin_component_edit.php to use `imageselector` type
2. Test with existing component configurations
3. Test within repeater fields

### Phase 4: Documentation
1. Update FormWriter documentation
2. Add usage examples
3. Update theme integration docs (note: no theme changes required)

---

## 6. Testing Plan

### Unit Tests
- [ ] AJAX endpoint returns correct JSON structure
- [ ] Pagination works correctly
- [ ] Search filters results
- [ ] Empty search returns all images

### Integration Tests
- [ ] Field renders correctly in form
- [ ] Modal opens on button click
- [ ] Images load lazily (check network tab)
- [ ] Infinite scroll loads more images
- [ ] Selection updates hidden input
- [ ] Preview shows selected image
- [ ] Form submission includes URL value
- [ ] Value restored on edit

### Performance Tests
- [ ] Modal opens quickly with 100+ images in database
- [ ] No page freeze during image loading
- [ ] Memory usage stable during infinite scroll

### Browser Tests
- [ ] Chrome
- [ ] Firefox
- [ ] Safari
- [ ] Mobile Safari/Chrome

---

## 7. Security Considerations

1. **Permission Check:** AJAX endpoint requires admin permission (level 5+)
2. **Input Sanitization:** Search term sanitized before database query
3. **Output Escaping:** All URLs and text escaped in JSON and HTML output
4. **CSRF:** Form submission protected by existing FormWriter CSRF token
5. **Path Traversal:** URLs returned from database, not constructed from user input

---

## 8. Future Enhancements (Out of Scope)

1. **Upload Integration:** Add "Upload New" button in modal
2. **Image Cropping:** Crop/resize selected image
3. **Multiple Selection:** Select multiple images for gallery fields
4. **Folder Organization:** Browse by folder/category
5. **Drag & Drop Reorder:** For multiple selection mode
6. **External URLs:** Option to enter external image URL manually

---

## 9. Dependencies

**Existing Components Used:**
- `MultiFile` class for image queries
- `File::get_url()` for URL generation
- FormWriterV2Base infrastructure
- AdminPage for modal styling context

**No New Dependencies Required**

---

## 10. Rollback Plan

If issues arise:
1. Remove `imageselector` method from FormWriter classes
2. Revert admin_component_edit.php to use `textinput` for image fields
3. Delete `/ajax/image_list_ajax.php`

No database changes required, so rollback is straightforward.

---

## 11. Success Criteria

- [ ] Image selector field type available in FormWriter
- [ ] Works in admin_component_edit for image configuration fields
- [ ] Lazy loading prevents page freezing
- [ ] Search/filter functionality works
- [ ] Selected URL correctly saved and displayed
- [ ] Works inside repeater fields
- [ ] No external JS/CSS dependencies
- [ ] PHP syntax validation passes
- [ ] Cross-browser compatibility verified

---

## Appendix: Example Implementation Sketch

### AJAX Endpoint (image_list_ajax.php)
```php
<?php
header('Content-Type: application/json');
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$search = $_GET['q'] ?? '';
$offset = intval($_GET['offset'] ?? 0);
$limit = intval($_GET['limit'] ?? 20);

$options = ['picture' => true, 'deleted' => false];
if ($search) {
    $options['filename_like'] = $search;
}

$files = new MultiFile($options, ['fil_file_id' => 'DESC'], $limit, $offset);
$total = $files->count_all();
$files->load();

$images = [];
foreach ($files as $file) {
    $images[] = [
        'id' => $file->key,
        'url' => $file->get_url('standard'),
        'thumbnail' => $file->get_url('thumbnail'),
        'title' => $file->get('fil_title') ?: $file->get('fil_name'),
        'filename' => $file->get('fil_name')
    ];
}

echo json_encode([
    'images' => $images,
    'total' => $total,
    'hasMore' => ($offset + $limit) < $total
]);
```

### FormWriter Method (complete in Base class)
```php
/**
 * Image selector with modal picker - complete implementation in base class.
 * Works for all themes out of the box. Override in theme FormWriter only if
 * completely different markup structure is needed.
 */
public function imageselector($name, $label = '', $options = []) {
    $this->registerField($name, 'imageselector', $label, $options);

    // Extract options with defaults
    $value = $options['value'] ?? '';
    $help = $options['help'] ?? '';
    $id = $options['id'] ?? $name;
    $ajaxEndpoint = $options['ajax_endpoint'] ?? '/ajax/image_list_ajax';
    $pageSize = $options['page_size'] ?? 20;

    // Styling options with sensible defaults
    $buttonClass = $options['button_class'] ?? 'btn btn-outline-secondary';
    $buttonText = $options['button_text'] ?? 'Select Image';
    $gridColumns = $options['grid_columns'] ?? 5;
    $thumbnailWidth = $options['thumbnail_width'] ?? 100;
    $previewWidth = $options['preview_width'] ?? 100;
    $primaryColor = $options['primary_color'] ?? '#0d6efd';
    $borderRadius = $options['border_radius'] ?? '4px';

    $uniqueId = 'imageselector_' . $id . '_' . uniqid();

    // Output field HTML
    $html = '<div class="mb-3 imageselector-wrapper" id="' . $uniqueId . '"';
    $html .= ' style="--is-primary-color:' . $primaryColor . ';';
    $html .= '--is-grid-columns:' . $gridColumns . ';';
    $html .= '--is-thumbnail-width:' . $thumbnailWidth . 'px;';
    $html .= '--is-border-radius:' . $borderRadius . ';">';

    // Label
    if ($label) {
        $html .= '<label class="form-label">' . htmlspecialchars($label) . '</label>';
    }

    // Hidden input for URL value
    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" ';
    $html .= 'id="' . htmlspecialchars($id) . '" ';
    $html .= 'value="' . htmlspecialchars($value) . '">';

    // Preview and button container
    $html .= '<div class="d-flex align-items-center gap-3">';

    // Preview area
    $html .= '<div class="imageselector-preview">';
    if ($value) {
        $html .= '<img src="' . htmlspecialchars($value) . '" alt="Selected">';
    } else {
        $html .= '<div class="imageselector-no-preview">No image</div>';
    }
    $html .= '</div>';

    // Select button
    $html .= '<button type="button" class="' . htmlspecialchars($buttonClass) . '" ';
    $html .= 'onclick="ImageSelector.open(\'' . $uniqueId . '\')">';
    $html .= htmlspecialchars($buttonText) . '</button>';

    // Clear button (if value exists)
    if ($value) {
        $html .= '<button type="button" class="btn btn-outline-danger btn-sm" ';
        $html .= 'onclick="ImageSelector.clear(\'' . $uniqueId . '\')">Clear</button>';
    }

    $html .= '</div>'; // end button container

    // Help text
    if ($help) {
        $html .= '<div class="form-text">' . htmlspecialchars($help) . '</div>';
    }

    $html .= '</div>'; // end wrapper

    echo $html;

    // Output inline CSS and JS (only once per page)
    $this->outputImageSelectorAssets($ajaxEndpoint, $pageSize);
}

/**
 * Output CSS and JS assets for image selector (called once per page)
 */
protected function outputImageSelectorAssets($ajaxEndpoint, $pageSize) {
    static $assetsLoaded = false;
    if ($assetsLoaded) return;
    $assetsLoaded = true;

    // Inline CSS and JavaScript here...
    // See full implementation in spec
}
```

---

**End of Specification Document**
