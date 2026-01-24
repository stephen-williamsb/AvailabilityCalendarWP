<?php
/**
 * Plugin Name: Availability Calendar (Google Calendar -> FullCalendar)
 * Description: Displays a Google Calendar as an availability calendar where busy days are greyed out. Uses FullCalendar and reads a public Google Calendar. Includes a settings page to enter API key and Calendar ID.
 * Version: 1.3
 * Author: Stephen Williams, Jackson Drew
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Activation: set sensible defaults
 * ------------------------------------------------------------------ */
function av_on_activation() {
    add_option( 'av_api_key', '' );
    add_option( 'av_calendar_id', '' );
    add_option( 'av_selector', '#availability-calendar' );
    add_option( 'av_refresh_minutes', 5 );
}
register_activation_hook( __FILE__, 'av_on_activation' );

/* ------------------------------------------------------------------
 * Plugin action link to Settings on plugins list
 * ------------------------------------------------------------------ */
function av_plugin_action_links( $links ) {
    $settings_link = '<a href="options-general.php?page=av-availability-calendar">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'av_plugin_action_links' );

/* ------------------------------------------------------------------
 * Enqueue FullCalendar assets and localize config for front-end JS
 *
 * This function is intentionally short and focused: enqueue 3rd-party
 * assets, read stored options, then localize a compact config object.
 * ------------------------------------------------------------------ */
function av_enqueue_fullcalendar_assets() {
    // FullCalendar CSS
    wp_enqueue_style(
        'av-fullcalendar-css',
        'https://unpkg.com/fullcalendar@5.11.3/main.min.css',
        array(),
        '5.11.3'
    );

    // FullCalendar core script (registered then enqueued)
    wp_register_script(
        'av-fullcalendar-core',
        'https://unpkg.com/fullcalendar@5.11.3/main.min.js',
        array(),
        '5.11.3',
        true
    );

    // FullCalendar Google plugin (depends on core)
    wp_register_script(
        'av-fullcalendar-google',
        'https://unpkg.com/@fullcalendar/google-calendar@5.11.3/main.global.min.js',
        array( 'av-fullcalendar-core' ),
        '5.11.3',
        true
    );

    // Enqueue the scripts we registered
    wp_enqueue_script( 'av-fullcalendar-core' );
    wp_enqueue_script( 'av-fullcalendar-google' );

    // Read saved options
    $api_key     = get_option( 'av_api_key', '' );
    $calendar_id = get_option( 'av_calendar_id', '' );
    $selector    = get_option( 'av_selector', '#availability-calendar' );
    $refresh     = intval( get_option( 'av_refresh_minutes', 5 ) );

    // Localize a small config object for the inline JS initializer
    $config = array(
        'apiKey'         => $api_key,
        'calendarId'     => $calendar_id,
        'selector'       => $selector,
        'refreshMinutes' => $refresh,
    );

    wp_localize_script( 'av-fullcalendar-core', 'availabilityCalendarConfig', $config );
}
add_action( 'wp_enqueue_scripts', 'av_enqueue_fullcalendar_assets' );

/* ------------------------------------------------------------------
 * Shortcode output (keeps markup generation separate and tiny)
 * ------------------------------------------------------------------ */
function av_availability_calendar_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'id' => 'availability-calendar',
    ), $atts, 'availability_calendar' );

    $id = esc_attr( $atts['id'] );

    // A simple container the JS will turn into a FullCalendar instance.
    return '<div id="' . $id . '" class="av-availability-calendar" style="max-width:1100px;margin:0 auto;"></div>';
}
add_shortcode( 'availability_calendar', 'av_availability_calendar_shortcode' );

/* ------------------------------------------------------------------
 * Inline JS initializer (kept in one place but internally modular)
 *
 * The JS contains internal helper functions:
 *  - extractDateOnly: robustly extracts YYYY-MM-DD from many incoming shapes
 *  - buildEventDataTransform: returns the eventDataTransform function
 *  - initCalendar: wires everything up and starts the calendar
 * ------------------------------------------------------------------ */
function av_add_fullcalendar_init_script() {
    $init_js = <<<'JS'
(function () {
  // Wait for DOM and FullCalendar to be available
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof FullCalendar === 'undefined' || !window.availabilityCalendarConfig) return;

    var container = document.querySelector(availabilityCalendarConfig.selector);
    if (!container) return;

    /**
     * Robustly extract a date-only string (YYYY-MM-DD) from various shapes
     * that Google Calendar / FullCalendar may provide:
     *  - '2026-01-24' (all-day)
     *  - '2026-01-24T11:00:00Z' (timed)
     *  - { date: '2026-01-24' } (gcal all-day)
     *  - { dateTime: '2026-01-24T11:00:00Z' } (gcal timed)
     *  - Date object
     */
    function extractDateOnly(value) {
      if (!value) return null;

      // Strings: either date or datetime
      if (typeof value === 'string') {
        var tIndex = value.indexOf('T');
        if (tIndex !== -1) return value.split('T')[0];
        return value;
      }

      // Plain object with date or dateTime
      if (typeof value === 'object') {
        if (value.date) return value.date;
        if (value.dateTime) {
          if (typeof value.dateTime === 'string') {
            var idx = value.dateTime.indexOf('T');
            if (idx !== -1) return value.dateTime.split('T')[0];
            return value.dateTime;
          }
          // If value.dateTime is a Date
          if (value.dateTime instanceof Date) {
            return value.dateTime.toISOString().split('T')[0];
          }
        }
        if (value instanceof Date) {
          return value.toISOString().split('T')[0];
        }
        // If object can be stringified safely
        try {
          if (typeof value.toISOString === 'function') {
            return value.toISOString().split('T')[0];
          }
        } catch (e) {
          // fall through
        }
      }

      return null;
    }

    /**
     * Build an eventDataTransform function that:
     *  - maps any incoming event (timed or all-day) to an all-day background event
     *  - ensures the title is stable (we set 'CLOSED' for accessibility/tooltips)
     */
    function buildEventDataTransform() {
      return function (rawEvent) {
        // Work with a shallow copy to avoid surprising mutations
        var ev = Object.assign({}, rawEvent);

        // Ensure a safe title (prevents 'undefined' showing up in some cases)
        ev.title = ev.title || ev.summary || ev.name || 'Unavailable';

        // Try many paths where start/end may be present
        var startDate = extractDateOnly(rawEvent.startStr || rawEvent.start || ev.start);
        var endDate   = extractDateOnly(rawEvent.endStr   || rawEvent.end   || ev.end);

        // If we cannot determine a start date, simply return the original object.
        // (This is defensive — most valid events should have a start.)
        if (!startDate) return ev;

        // If there is no end, treat as single-day event: end is exclusive next day
        if (!endDate) {
          var tmp = new Date(startDate + 'T00:00:00');
          tmp.setDate(tmp.getDate() + 1);
          endDate = tmp.toISOString().split('T')[0];
        }

        // Convert to a full-day (allDay) background event that covers the whole day(s)
        ev.start = startDate;
        ev.end = endDate;
        ev.allDay = true;
        ev.display = 'background';
        ev.classNames = (ev.classNames || []).concat(['av-bg-event']);

        // Use a stable, human-friendly title for tooltips / accessibility
        // We set a generic label; the visual indicator is the dark background.
        ev.title = 'CLOSED';

        return ev;
      };
    }

    /**
     * Initialize and render FullCalendar with our transform and styles
     */
    function initCalendar() {
      var calendar = new FullCalendar.Calendar(container, {
        initialView: 'dayGridMonth',
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: ''
        },
        googleCalendarApiKey: availabilityCalendarConfig.apiKey,
        events: {
          googleCalendarId: availabilityCalendarConfig.calendarId
        },

        // Transform any incoming event into an all-day background "blocked" day
        eventDataTransform: buildEventDataTransform(),

        dayMaxEventRows: 3,
        navLinks: true,
        selectable: false,
        editable: false,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false }
      });

      calendar.render();

      // Optional client-side periodic refetch (0 disables)
      var refreshMinutes = parseInt(availabilityCalendarConfig.refreshMinutes, 10) || 5;
      if (refreshMinutes > 0) {
        setInterval(function () {
          calendar.refetchEvents();
        }, refreshMinutes * 60 * 1000);
      }
    }

    // Kick it off
    initCalendar();
  });
})();
JS;

    wp_add_inline_script( 'av-fullcalendar-core', $init_js );
}
add_action( 'wp_enqueue_scripts', 'av_add_fullcalendar_init_script', 20 );

/* ------------------------------------------------------------------
 * Admin menu registration (settings page under "Settings" +
 * optional top-level menu for convenience)
 * ------------------------------------------------------------------ */
function av_admin_menu() {
    add_options_page(
        'Availability Calendar Settings',
        'Availability Calendar',
        'manage_options',
        'av-availability-calendar',
        'av_settings_page'
    );

    // Optional top-level menu (keeps parity with earlier version)
    add_menu_page(
        'Availability Calendar',
        'Availability Calendar',
        'manage_options',
        'av-av-main',
        'av_settings_page',
        'dashicons-calendar-alt',
        80
    );
}
add_action( 'admin_menu', 'av_admin_menu' );

/* ------------------------------------------------------------------
 * Settings registration (small wrapper)
 * ------------------------------------------------------------------ */
function av_register_settings() {
    register_setting( 'av_settings_group', 'av_api_key' );
    register_setting( 'av_settings_group', 'av_calendar_id' );
    register_setting( 'av_settings_group', 'av_selector' );
    register_setting( 'av_settings_group', 'av_refresh_minutes' );
}
add_action( 'admin_init', 'av_register_settings' );

/* ------------------------------------------------------------------
 * Helper: process settings form submission (keeps av_settings_page
 * smaller and focused on rendering)
 * ------------------------------------------------------------------ */
function av_handle_settings_save() {
    // Check nonce and permission already handled in caller; this function
    // assumes those checks were done before calling it.
    $api_key     = isset( $_POST['av_api_key'] ) ? sanitize_text_field( $_POST['av_api_key'] ) : '';
    $calendar_id = isset( $_POST['av_calendar_id'] ) ? sanitize_text_field( $_POST['av_calendar_id'] ) : '';
    $selector    = isset( $_POST['av_selector'] ) ? sanitize_text_field( $_POST['av_selector'] ) : '#availability-calendar';
    $refresh     = isset( $_POST['av_refresh_minutes'] ) ? intval( $_POST['av_refresh_minutes'] ) : 5;

    update_option( 'av_api_key', $api_key );
    update_option( 'av_calendar_id', $calendar_id );
    update_option( 'av_selector', $selector );
    update_option( 'av_refresh_minutes', $refresh );
}

/* ------------------------------------------------------------------
 * Settings page render + handling
 * - Keeps markup simple.
 * - Calls helper to process saves (and displays an admin notice).
 * ------------------------------------------------------------------ */
function av_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>You do not have permission to view this page.</p></div>';
        return;
    }

    // If the form was submitted, validate nonce and save via helper.
    if ( isset( $_POST['av_settings_submit'] ) ) {
        check_admin_referer( 'av_settings_save', 'av_settings_nonce' );
        av_handle_settings_save();
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Read values for form rendering
    $api_key     = esc_attr( get_option( 'av_api_key', '' ) );
    $calendar_id = esc_attr( get_option( 'av_calendar_id', '' ) );
    $selector    = esc_attr( get_option( 'av_selector', '#availability-calendar' ) );
    $refresh     = intval( get_option( 'av_refresh_minutes', 5 ) );
    ?>
    <div class="wrap">
      <h1>Availability Calendar Settings</h1>
      <form method="post" action="">
        <?php
          // Settings API compatibility (keeps WP happy)
          settings_fields( 'av_settings_group' );
          do_settings_sections( 'av_settings_group' );
          wp_nonce_field( 'av_settings_save', 'av_settings_nonce' );
        ?>
        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><label for="av_api_key">Google API Key</label></th>
              <td><input name="av_api_key" type="text" id="av_api_key" value="<?php echo $api_key; ?>" class="regular-text" /></td>
            </tr>
            <tr>
              <th scope="row"><label for="av_calendar_id">Calendar ID</label></th>
              <td><input name="av_calendar_id" type="text" id="av_calendar_id" value="<?php echo $calendar_id; ?>" class="regular-text" />
              <p class="description">Example: <code>youraddress@group.calendar.google.com</code></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="av_selector">Calendar selector</label></th>
              <td><input name="av_selector" type="text" id="av_selector" value="<?php echo $selector; ?>" class="regular-text" />
              <p class="description">CSS selector of the element used by the shortcode. Default: <code>#availability-calendar</code></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="av_refresh_minutes">Auto-refresh (minutes)</label></th>
              <td><input name="av_refresh_minutes" type="number" id="av_refresh_minutes" value="<?php echo $refresh; ?>" class="small-text" min="0" />
              <p class="description">How often the calendar refetches events. 0 to disable client-side auto-refresh.</p>
              </td>
            </tr>
          </tbody>
        </table>

        <p class="submit">
          <input type="submit" name="av_settings_submit" id="submit" class="button button-primary" value="Save Changes">
        </p>
      </form>

      <h2>How to use</h2>
      <ol>
        <li>Go to <strong>Settings &raquo; Availability Calendar</strong> (or the "Availability Calendar" menu) and paste your Google API key and Calendar ID.</li>
        <li>Make sure your Google Calendar is public (or use a private proxy — not provided in this plugin).</li>
        <li>Add the shortcode <code>[availability_calendar]</code> to any page.</li>
      </ol>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 * Inline CSS for calendar container and the background "CLOSED" event
 * - Moved into an easily editable function.
 * ------------------------------------------------------------------ */
function av_add_styles() {
    $css = '
    /* Container visual styling */
    .av-availability-calendar { background: #fff; padding: 14px; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.8); }

    /* Darker, fully-opaque background for CLOSED days.
       We use a semi-opaque black with forced opacity to ensure the
       blocked days read clearly against a variety of themes. */
    .av-bg-event { background-color: rgba(160, 157, 147, 1) !important; opacity: 1 !important; }

    /* Make day cells reasonably tall so the background shading is visible */
    .fc .fc-daygrid-day-frame { min-height: 80px; }
    ';
    wp_add_inline_style( 'av-fullcalendar-css', $css );
}
add_action( 'wp_enqueue_scripts', 'av_add_styles', 30 );

/* End of plugin file - no closing PHP tag to avoid accidental whitespace output */
