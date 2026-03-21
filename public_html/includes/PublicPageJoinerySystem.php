<?php
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));

/**
 * PublicPageJoinerySystem
 *
 * Joinery System admin theme. Vanilla HTML5+CSS, no Bootstrap/FontAwesome/jQuery.
 * Eliminates Bootstrap, FontAwesome, jQuery, Simplebar, and Popper.
 * Uses style.css (~33KB) + script.js (~4KB) instead of ~2.2MB of vendor assets.
 *
 * Extends PublicPageBase directly — no Bootstrap/Falcon dependency.
 */
class PublicPageJoinerySystem extends PublicPageBase {

    // Tracks whether renderToolbar should close the card-header right-side container
    protected $_box_has_toolbar = false;
    // Tracks whether current box is a table (affects card-body padding)
    protected $_is_table_box = false;

    // =====================================================================
    // Box open — close card-header properly for non-table boxes with altlinks
    // =====================================================================
    function begin_box($options = NULL) {
        if (!is_array($options)) $options = array();
        $this->renderBoxOpen($options);
        $this->dropdown_or_buttons($options);
        // For non-table boxes with altlinks, renderToolbar won't be called,
        // so we close the flex container and card-header here.
        if ($this->_box_has_toolbar && !$this->_is_table_box) {
            echo '</div>'; // close right-side flex container
            echo '</div>'; // close card-header
            echo '<div class="card-body">'; // open card-body
        }
    }

    // =====================================================================
    // Inline SVG icon map  (FontAwesome name → inline SVG)
    // =====================================================================
    protected static function getIconSvg($icon_name) {
        $icons = [
            'user'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
            'users'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M21 20c0-3-1.8-4.4-4-5"/></svg>',
            'calendar'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
            'calendar-alt'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
            'cog'            => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'wrench'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            'tools'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            'list'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
            'list-alt'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>',
            'table'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>',
            'shopping-cart'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
            'shopping-bag'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
            'home'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/></svg>',
            'envelope'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            'file'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>',
            'file-alt'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>',
            'image'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            'images'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            'chart-bar'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
            'chart-line'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'tag'            => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            'tags'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            'lock'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            'dollar-sign'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'star'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'bell'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            'puzzle-piece'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/></svg>',
            'ticket-alt'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5v2M15 11v2M15 17v2M5 5h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7a2 2 0 0 1 2-2z"/></svg>',
            'graduation-cap' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
            'building'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="10" height="15"/><rect x="12" y="2" width="10" height="20"/><rect x="5" y="11" width="4" height="4"/><rect x="15" y="6" width="4" height="4"/><rect x="15" y="14" width="4" height="4"/></svg>',
            'th-large'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="9" height="9" rx="1"/><rect x="13" y="2" width="9" height="9" rx="1"/><rect x="2" y="13" width="9" height="9" rx="1"/><rect x="13" y="13" width="9" height="9" rx="1"/></svg>',
            'layer-group'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
            'store'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'tasks'          => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><polyline points="3 6 4 7 6 5"/><polyline points="3 12 4 13 6 11"/><polyline points="3 18 4 19 6 17"/></svg>',
            'clipboard-list' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>',
            'question-circle'=> '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 1 1 5.83 1c-.26 1.2-1.5 2-2.92 2v1"/><circle cx="12" cy="17" r="1"/></svg>',
            'database'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
            'sign-out-alt'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
            'map-marker-alt' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
            'box'            => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
            'credit-card'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            'key'            => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
            'plug'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M5 12H2a10 10 0 0 0 20 0h-3"/><rect x="7" y="2" width="3" height="8"/><rect x="14" y="2" width="3" height="8"/></svg>',
            'poll'           => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
            'dashboard'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        ];
        return isset($icons[$icon_name]) ? $icons[$icon_name] : $icons['list'];
    }

    /**
     * Get SVG icon for admin menu items (app launcher grid in topbar)
     */
    protected function get_admin_icon_svg($icon_name) {
        $icons = [
            'home'            => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"/></svg>',
            'user'            => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
            'dashboard'       => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 4h18v4H3zM3 10h18v10H3z"/></svg>',
            'wrench'          => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.7 1.7 0 0 0 .33 1.82l.05.05a2 2 0 1 1-2.82 2.83l-.06-.06a1.7 1.7 0 0 0-1.83-.33 1.7 1.7 0 0 0-1 1.51v.09a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.51 1.7 1.7 0 0 0-1.83.33l-.06.06a2 2 0 1 1-2.82-2.83l.05-.05a1.7 1.7 0 0 0 .33-1.82 1.7 1.7 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.51-1 1.7 1.7 0 0 0-.33-1.82l-.05-.05a2 2 0 1 1 2.82-2.83l.06.06a1.7 1.7 0 0 0 1.83.33h.09A1.7 1.7 0 0 0 9 3.09V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.51h.09a1.7 1.7 0 0 0 1.83-.33l.06-.06a2 2 0 1 1 2.82 2.83l-.05.05a1.7 1.7 0 0 0-.33 1.82 1.7 1.7 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z"/></svg>',
            'tools'           => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3h6v6H3zM15 3h6v6h-6zM15 15h6v6h-6zM3 15h6v6H3z"/></svg>',
            'question-circle' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 1 1 5.83 1c-.26 1.2-1.5 2-2.92 2v1"/><circle cx="12" cy="17" r="1"/></svg>',
        ];
        return $icons[$icon_name] ?? $icons['dashboard'];
    }

    // =====================================================================
    // Table classes
    // =====================================================================
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table'   => 'table mb-0',
            'header'  => '',
        ];
    }

    // =====================================================================
    // Page wrappers (card-based)
    // =====================================================================
    public static function BeginPage($title = '', $options = array()) {
        $output = '';
        if ($title) {
            $output .= '<div class="page-header mb-3">';
            $output .= '<div>';
            $output .= '<h2 class="mb-0">' . htmlspecialchars($title) . '</h2>';
            if (!empty($options['breadcrumbs']) && is_array($options['breadcrumbs'])) {
                $output .= '<ol class="breadcrumb" style="padding:0;background:none;margin-top:0.25rem;">';
                $output .= '<li class="breadcrumb-item"><a href="/admin">Admin</a></li>';
                $output .= '<li class="breadcrumb-separator">/</li>';
                foreach ($options['breadcrumbs'] as $name => $url) {
                    if (empty($url)) {
                        $output .= '<li class="breadcrumb-item active">' . htmlspecialchars($name) . '</li>';
                    } else {
                        $output .= '<li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($name) . '</a></li>';
                        $output .= '<li class="breadcrumb-separator">/</li>';
                    }
                }
                $output .= '</ol>';
            }
            $output .= '</div>';
            $output .= '</div>';
        }
        $output .= '<div class="card mb-3">';
        return $output;
    }

    public static function EndPage($options = array()) {
        return '</div>';
    }

    public static function BeginPageNoCard($options = array()) {
        $output = '<div class="mb-3">';
        if (!empty($options['readable_title']) || !empty($options['breadcrumbs'])) {
            $output .= '<div class="page-header">';
            $output .= '<div>';
            if (!empty($options['readable_title'])) {
                $output .= '<h2 class="mb-2">' . htmlspecialchars($options['readable_title']) . '</h2>';
            }
            if (!empty($options['breadcrumbs']) && is_array($options['breadcrumbs'])) {
                $output .= '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
                $count = count($options['breadcrumbs']);
                $i = 0;
                foreach ($options['breadcrumbs'] as $name => $url) {
                    $i++;
                    if ($i === $count || empty($url)) {
                        $output .= '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($name) . '</li>';
                    } else {
                        $output .= '<li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($name) . '</a></li>';
                    }
                }
                $output .= '</ol></nav>';
            }
            $output .= '</div>';
            if (!empty($options['header_action'])) {
                $output .= '<div>' . $options['header_action'] . '</div>';
            }
            $output .= '</div>';
        }
        $output .= '</div>';
        return $output;
    }

    public static function EndPageNoCard($options = array()) {
        return '';
    }

    // =====================================================================
    // Alerts
    // =====================================================================
    protected static function renderAlert($title, $content, $type) {
        $class_map = ['error' => 'alert-danger', 'warn' => 'alert-warning', 'success' => 'alert-success'];
        $class = isset($class_map[$type]) ? $class_map[$type] : 'alert-info';
        $icon_map = [
            'alert-danger'  => '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            'alert-warning' => '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'alert-success' => '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'alert-info'    => '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        ];
        $icon = isset($icon_map[$class]) ? $icon_map[$class] : $icon_map['alert-info'];
        $output  = '<div class="alert ' . $class . '">';
        $output .= $icon;
        $output .= '<div class="alert-body">';
        if ($title) $output .= '<div class="alert-heading">' . htmlspecialchars($title) . '</div>';
        $output .= '<div>' . $content . '</div>';
        $output .= '</div>';
        $output .= '<button class="alert-close" type="button" aria-label="Close">&times;</button>';
        $output .= '</div>';
        return $output;
    }

    // =====================================================================
    // Tab menu
    // =====================================================================
    protected static function renderTabMenu($tab_menus, $current = NULL) {
        $output = '<ul class="nav-tabs">';
        foreach ($tab_menus as $name => $link) {
            if ($name == $current) {
                $output .= '<li><a class="nav-link active" href="#" aria-current="page">' . htmlspecialchars($name) . '</a></li>';
            } else {
                $output .= '<li><a class="nav-link" href="' . htmlspecialchars($link) . '">' . htmlspecialchars($name) . '</a></li>';
            }
        }
        $output .= '</ul>';
        return $output;
    }

    // =====================================================================
    // Box / card wrappers
    // =====================================================================
    protected function renderBoxOpen($options) {
        $use_card = isset($options['card']) && $options['card'] === true;
        $has_links = !empty($options['altlinks']) && count($options['altlinks']) > 0;
        $has_toolbar = !empty($options['sortoptions']) || !empty($options['filteroptions']) || !empty($options['search_on']);
        $this->_box_has_toolbar = $has_links || $has_toolbar;

        if ($use_card) {
            echo '<div class="card mb-3">';
        }

        // card-header: title on left, right-side container for buttons + sort/search
        echo '<div class="card-header bg-body-tertiary">';
        if (!empty($options['title'])) {
            echo '<h6 class="mb-0">' . htmlspecialchars($options['title']) . '</h6>';
        }
        if ($this->_box_has_toolbar) {
            // Open right-side flex container — closed by renderToolbar
            echo '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-left:auto;">';
        } else {
            // No toolbar/buttons coming — close card-header and open card-body for content
            echo '</div>';
            $card_body_class = $this->_is_table_box ? 'card-body p-0' : 'card-body';
            echo '<div class="' . $card_body_class . '">';
        }
    }

    protected function renderBoxClose($options) {
        $use_card = isset($options['card']) && $options['card'] === true;
        echo '</div>'; // close card-body (opened in renderBoxOpen or renderToolbar)
        if ($use_card) {
            echo '</div>'; // close card
        }
    }

    // =====================================================================
    // Dropdown / button group above tables
    // =====================================================================
    protected function renderDropdown($label, $links) {
        echo '<div class="dropdown d-inline-block">';
        echo '<button class="btn btn-soft-default btn-sm" type="button" data-toggle="dropdown">' . htmlspecialchars($label) . ' <svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5" style="vertical-align:middle;margin-left:2px;"><path d="M1 1l4 4 4-4"/></svg></button>';
        echo '<div class="dropdown-menu">';
        foreach ($links as $link_label => $link_url) {
            echo '<a href="' . htmlspecialchars($link_url) . '" class="dropdown-item">' . htmlspecialchars($link_label) . '</a>';
        }
        echo '</div></div>';
    }

    protected function renderButtonGroup($links) {
        foreach ($links as $label => $link) {
            echo '<a href="' . htmlspecialchars($link) . '" class="btn btn-primary btn-sm">' . htmlspecialchars($label) . '</a>';
        }
    }

    // =====================================================================
    // Toolbar (sort / filter / search above tables)
    // =====================================================================
    protected function renderToolbar($sort_data, $filter_data, $search_on, $pager) {
        if (!$this->_box_has_toolbar) return;
        if ($sort_data) {
            printf('<form method="get" action="%s" style="display:flex;align-items:center;gap:0.375rem;">', $pager->base_url());
            echo $pager->url_vars_as_hidden_input(['sort', 'sdirection']);
            echo '<label style="font-size:0.8125rem;color:var(--muted);">Sort:</label>';
            echo '<select name="' . $pager->prefix() . 'sort" class="form-control form-control-sm" style="width:auto;">';
            foreach ($sort_data as $key => $value) {
                $sel = $pager->get_sort() == $value ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($value) . '"' . $sel . '>' . htmlspecialchars($key) . '</option>';
            }
            echo '</select>';
            echo '<select name="' . $pager->prefix() . 'sdirection" class="form-control form-control-sm" style="width:auto;">';
            foreach (['Descending' => 'DESC', 'Ascending' => 'ASC'] as $key => $value) {
                $sel = $pager->sort_direction() == $value ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($value) . '"' . $sel . '>' . htmlspecialchars($key) . '</option>';
            }
            echo '</select>';
            foreach ($pager->url_vars() as $k => $v) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
            echo '<button type="submit" class="btn btn-sm btn-soft-default">Sort</button></form>';
        }
        if ($filter_data) {
            printf('<form method="get" action="%s" style="display:flex;align-items:center;gap:0.375rem;">', $pager->base_url());
            echo $pager->url_vars_as_hidden_input(['filter']);
            echo '<label style="font-size:0.8125rem;color:var(--muted);">Show:</label>';
            echo '<select name="' . $pager->prefix() . 'filter" class="form-control form-control-sm" style="width:auto;">';
            foreach ($filter_data as $key => $value) {
                $sel = $pager->get_filter() == $value ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($value) . '"' . $sel . '>' . htmlspecialchars($key) . '</option>';
            }
            echo '</select>';
            foreach ($pager->url_vars() as $k => $v) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
            echo '<button type="submit" class="btn btn-sm btn-soft-default">Go</button></form>';
        }
        if ($search_on) {
            $formwriter = $this->getFormWriter('search_form', ['action' => $pager->base_url(), 'method' => 'get']);
            $formwriter->begin_form();
            echo $pager->url_vars_as_hidden_input(['searchterm']);
            echo '<div style="display:flex;align-items:center;gap:0.375rem;">';
            echo '<label style="font-size:0.8125rem;color:var(--muted);">Search:</label>';
            echo '<input name="' . $pager->prefix() . 'searchterm" value="' . htmlspecialchars($pager->search_term()) . '" type="text" class="form-control form-control-sm" style="width:180px;" maxlength="100">';
            foreach ($pager->url_vars() as $k => $v) echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
            echo '<button type="submit" class="btn btn-sm btn-soft-default">Search</button>';
            echo '</div>';
            echo $formwriter->end_form();
        }
        echo '</div>'; // close right-side flex container
        echo '</div>'; // close card-header
        // Open card-body after toolbar (table context uses p-0)
        $card_body_class = $this->_is_table_box ? 'card-body p-0' : 'card-body';
        echo '<div class="' . $card_body_class . '">';
    }

    // =====================================================================
    // Pagination
    // =====================================================================
    protected function renderPagination($data) {
        $pad = $data['in_card'] ? 'padding:0 1rem 1rem;' : '';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;' . $pad . 'margin-top:0.75rem;font-size:0.8125rem;color:var(--muted);">';
        echo '<span>' . $data['num_records'] . ' records &mdash; page ' . $data['current_page'] . ' of ' . $data['total_pages'] . '</span>';
        if ($data['show_controls']) {
            echo '<div class="pagination">';
            if ($data['prev_10_url']) {
                echo '<a href="' . htmlspecialchars($data['prev_10_url']) . '" class="page-link" title="Previous 10">&laquo;</a>';
            } else {
                echo '<span class="page-link disabled">&laquo;</span>';
            }
            foreach ($data['pages'] as $page) {
                if ($page['is_current']) {
                    echo '<span class="page-link active">' . $page['number'] . '</span>';
                } else {
                    echo '<a href="' . htmlspecialchars($page['url']) . '" class="page-link">' . $page['number'] . '</a>';
                }
            }
            if ($data['next_10_url']) {
                echo '<a href="' . htmlspecialchars($data['next_10_url']) . '" class="page-link" title="Next 10">&raquo;</a>';
            } else {
                echo '<span class="page-link disabled">&raquo;</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // =====================================================================
    // Sidebar vertical menu
    // =====================================================================
    public function vertical_menu($menu) {
        ?>
        <nav class="sidebar-nav">
          <ul class="nav-list">
            <?php
            foreach ($menu as $menu_id => $menu_info) {
                if ($menu_info['parent']) continue;
                $icon = self::getIconSvg($menu_info['icon']);

                if ($menu_info['has_subs']) {
                    $is_active = !empty($menu_info['currentmain']);
                    ?>
                    <li class="nav-item">
                      <a href="#" class="nav-link has-children<?php echo $is_active ? ' open' : ''; ?>">
                        <span class="nav-link-icon"><?php echo $icon; ?></span>
                        <span class="nav-link-text"><?php echo htmlspecialchars($menu_info['display']); ?></span>
                      </a>
                      <ul class="sidebar-subnav<?php echo $is_active ? ' open' : ''; ?>">
                        <?php foreach ($menu as $sub_id => $sub_info): ?>
                          <?php if ($sub_info['parent'] == $menu_id): ?>
                          <li>
                            <a href="<?php echo htmlspecialchars($sub_info['defaultpage']); ?>"
                               class="nav-link<?php echo !empty($sub_info['currentsub']) ? ' active' : ''; ?>">
                              <?php echo htmlspecialchars($sub_info['display']); ?>
                            </a>
                          </li>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </ul>
                    </li>
                    <?php
                } else {
                    ?>
                    <li class="nav-item">
                      <a href="<?php echo htmlspecialchars($menu_info['defaultpage']); ?>"
                         class="nav-link<?php echo !empty($menu_info['currentmain']) ? ' active' : ''; ?>">
                        <span class="nav-link-icon"><?php echo $icon; ?></span>
                        <span class="nav-link-text"><?php echo htmlspecialchars($menu_info['display']); ?></span>
                      </a>
                    </li>
                    <?php
                }
            }
            ?>
          </ul>
        </nav>
        <?php
    }

    // =====================================================================
    // Topbar right-side icons (cart, admin nine-dots, user avatar)
    // =====================================================================
    public function top_right_menu() {
        $menu_data = $this->get_menu_data();
        $cart      = $menu_data['cart'];
        $user_menu = $menu_data['user_menu'];

        // --- Cart ---
        if ($cart['has_items']): ?>
        <li class="nav-item" style="position:relative;">
          <a href="<?php echo htmlspecialchars($cart['link']); ?>" class="nav-link" style="position:relative;padding:0.4rem 0.5rem;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <span class="notification-count"><?php echo (int)$cart['item_count']; ?></span>
          </a>
        </li>
        <?php endif;

        // --- Notifications ---
        if ($menu_data['notifications']['enabled']): ?>
        <li class="nav-item" style="position:relative;">
          <?php $this->render_notification_icon($menu_data); ?>
        </li>
        <?php endif; ?>

        <?php // --- Admin nine-dots menu ---
        if ($user_menu['permission_level'] >= 5): ?>
        <li class="nav-item dropdown">
          <button class="nav-link" type="button" data-toggle="dropdown" aria-label="Admin menu" style="padding:0.4rem 0.5rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <circle cx="2" cy="2" r="2" fill="#6C6E71"/><circle cx="2" cy="8" r="2" fill="#6C6E71"/><circle cx="2" cy="14" r="2" fill="#6C6E71"/>
              <circle cx="8" cy="2" r="2" fill="#6C6E71"/><circle cx="8" cy="8" r="2" fill="#6C6E71"/><circle cx="8" cy="14" r="2" fill="#6C6E71"/>
              <circle cx="14" cy="2" r="2" fill="#6C6E71"/><circle cx="14" cy="8" r="2" fill="#6C6E71"/><circle cx="14" cy="14" r="2" fill="#6C6E71"/>
            </svg>
          </button>
          <div class="dropdown-menu" style="min-width:16rem;right:0;left:auto;">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);padding:0.5rem;gap:0.125rem;">
              <?php foreach ($user_menu['items'] as $item):
                if (!in_array($item['label'], ['Home', 'My Profile', 'Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help'])) continue;
                $icon = $this->get_admin_icon_svg($item['icon']);
                ?>
                <a href="<?php echo htmlspecialchars($item['link']); ?>"
                   style="display:flex;flex-direction:column;align-items:center;padding:0.625rem 0.25rem;border-radius:var(--radius);text-decoration:none;color:var(--body-color);font-size:0.6875rem;font-weight:500;text-align:center;transition:background var(--transition);"
                   onmouseover="this.style.background='var(--lighter)'" onmouseout="this.style.background=''">
                  <span style="width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;margin-bottom:0.25rem;"><?php echo $icon; ?></span>
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </li>
        <?php endif;

        // --- User avatar / login ---
        if ($user_menu['is_logged_in']):
            $avatar_url = $user_menu['avatar_url'] ?? '';
            // Build initials fallback from display name
            $display_name = $user_menu['display_name'] ?? '';
            $parts = array_filter(explode(' ', trim($display_name)));
            $initials = '';
            if (count($parts) >= 2) {
                $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
            } elseif (count($parts) === 1) {
                $initials = strtoupper(substr($parts[0], 0, 2));
            }
            if (empty($initials)) $initials = 'U';
            ?>
        <li class="nav-item dropdown">
          <button class="nav-link" type="button" data-toggle="dropdown" style="padding:0.25rem;">
            <?php if ($avatar_url): ?>
            <div class="avatar avatar-xl rounded-circle"><img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="" /></div>
            <?php else: ?>
            <div class="avatar avatar-xl avatar-initials rounded-circle" style="background:#2A7BE4;color:#fff;font-weight:600;font-size:0.7rem;width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;"><?php echo htmlspecialchars($initials); ?></div>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu" style="right:0;left:auto;">
            <?php foreach ($user_menu['items'] as $item):
              if (in_array($item['label'], ['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help'])) continue;
              echo '<a class="dropdown-item" href="' . htmlspecialchars($item['link']) . '">' . htmlspecialchars($item['label']) . '</a>';
            endforeach; ?>
          </div>
        </li>
        <?php else: ?>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($user_menu['login_link']); ?>">Login</a></li>
        <?php if (!empty($user_menu['register_link'])): ?>
        <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($user_menu['register_link']); ?>">Register</a></li>
        <?php endif; ?>
        <?php endif;
    }

    // =====================================================================
    // public_header() — outputs <html><head><body> and opens page layout
    // =====================================================================
    public function public_header($options = array()) {
        $_GLOBALS['page_header_loaded'] = true;
        $settings = Globalvars::get_instance();
        $options  = parent::public_header_common($options);
        ?>
<!DOCTYPE html>
<html lang="en-US">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description" content="<?php echo htmlspecialchars($options['description'] ?? ''); ?>">
  <title><?php echo htmlspecialchars($options['title'] ?? ''); ?></title>
  <?php $this->global_includes_top($options); ?>
  <meta name="theme-color" content="#ffffff">
  <link rel="preconnect" href="https://fonts.gstatic.com">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <?php $_js_css_sys = PathHelper::getThemeFilePath('style.css', 'assets/css', 'system', 'joinery-system'); ?>
  <link rel="stylesheet" href="<?php echo PathHelper::getThemeFilePath('style.css', 'assets/css', 'web', 'joinery-system') . '?v=' . (file_exists($_js_css_sys) ? filemtime($_js_css_sys) : '1'); ?>">
  <?php if ($settings->get_setting('custom_css')): ?>
  <style><?php echo $settings->get_setting('custom_css'); ?></style>
  <?php endif; ?>
</head>
<body class="preload">
        <?php
        if (!empty($options['header_only']) && $options['header_only']) {
            return;
        }
        $has_sidebar = !empty($options['vertical_menu']);
        ?>
  <div class="admin-layout" id="top">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php if ($has_sidebar): ?>
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-brand">
        <a href="/">
          <?php
          $logo_link = $settings->get_setting('logo_link');
          if ($logo_link) {
              echo '<img src="' . htmlspecialchars($logo_link) . '" alt="" width="28">';
          } else { ?>
          <svg width="28" height="28" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0">
            <circle cx="20" cy="20" r="20" fill="#2A7BE4"/>
            <text x="21" y="30" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="900" fill="white">J</text>
          </svg>
          <?php }
          echo '<span>' . htmlspecialchars($settings->get_setting('site_name')) . '</span>';
          ?>
        </a>
      </div>
      <?php $this->vertical_menu($options['vertical_menu']); ?>
    </aside>
    <?php endif; ?>

    <div class="main-content">
      <header class="topbar">
        <button class="topbar-hamburger" type="button" aria-label="Toggle sidebar">
          <span></span><span></span><span></span>
        </button>

        <?php if ($has_sidebar && !empty($options['vertical_menu'])):
          // Build topbar breadcrumb from active menu items
          $bc_section = null; $bc_page = null;
          foreach ($options['vertical_menu'] as $mid => $minfo) {
              if ($minfo['parent']) continue;
              if (!empty($minfo['currentmain']) && $minfo['has_subs']) { $bc_section = $minfo['display']; }
              elseif (!empty($minfo['currentmain']) && !$minfo['has_subs']) { $bc_section = $minfo['display']; }
          }
          foreach ($options['vertical_menu'] as $mid => $minfo) {
              if (!$minfo['parent']) continue;
              if (!empty($minfo['currentsub'])) { $bc_page = $minfo['display']; }
          }
          if ($bc_section || $bc_page):
            $bc_label = $bc_page ?: $bc_section; ?>
          <ol class="breadcrumb" style="margin-left:0.5rem;">
            <li class="breadcrumb-item"><a href="/admin">Admin</a></li>
            <li class="breadcrumb-separator">/</li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($bc_label); ?></li>
          </ol>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!$has_sidebar && (empty($options['hide_horizontal_menu']) || !$options['hide_horizontal_menu'])): ?>
        <nav style="display:flex;align-items:center;gap:0.25rem;margin-left:1rem;">
          <?php
          $menu_data = $this->get_menu_data();
          foreach ($menu_data['main_menu'] as $menu) {
              if ($menu['parent'] !== true) continue;
              if (empty($menu['submenu'])) {
                  echo '<a class="nav-link' . ($menu['is_active'] ? ' active' : '') . '" href="' . htmlspecialchars($menu['link']) . '">' . htmlspecialchars($menu['name']) . '</a>';
              } else {
                  $mid = 'menu-' . strtolower(preg_replace('/\s+/', '-', $menu['name']));
                  echo '<div class="dropdown">';
                  echo '<button class="nav-link" data-toggle="dropdown">' . htmlspecialchars($menu['name']) . '</button>';
                  echo '<div class="dropdown-menu">';
                  foreach ($menu['submenu'] as $sub) {
                      echo '<a class="dropdown-item" href="' . htmlspecialchars($sub['link']) . '">' . htmlspecialchars($sub['name']) . '</a>';
                  }
                  echo '</div></div>';
              }
          }
          ?>
        </nav>
        <?php endif; ?>

        <ul class="topbar-nav">
          <?php $this->top_right_menu(); ?>
        </ul>
      </header><!-- .topbar -->

      <main class="page-body">
        <?php
    }

    // =====================================================================
    // public_footer() — closes page layout, loads JS
    // =====================================================================
    public function public_footer($options = array()) {
        if (!isset($options['header_only']) || !$options['header_only']): ?>
      </main><!-- .page-body -->

      <footer class="page-footer">
        <span>v0.5.0</span>
      </footer>
    </div><!-- .main-content -->
  </div><!-- .admin-layout -->

  <script src="/assets/js/joinery-validate.js"></script>
  <?php $_js_js_sys = PathHelper::getThemeFilePath('script.js', 'assets/js', 'system', 'joinery-system'); ?>
  <script src="<?php echo PathHelper::getThemeFilePath('script.js', 'assets/js', 'web', 'joinery-system') . '?v=' . (file_exists($_js_js_sys) ? filemtime($_js_js_sys) : '1'); ?>"></script>
</body>
</html>
        <?php
        endif;
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();
    }
}
?>
