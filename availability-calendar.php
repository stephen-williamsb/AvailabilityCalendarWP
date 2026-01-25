<?php
/**
 * Plugin Name: Availability Calendar (Google Calendar -> FullCalendar)
 * Description: Displays a Google Calendar as an availability calendar where busy days are greyed out. Uses FullCalendar and reads a public Google Calendar. Includes a settings page to enter API key and Calendar ID.
 * Version: 1.4-fix
 * Author: Stephen Williams, Jackson Drew
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Activation: set sensible defaults
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_on_activation' ) ) {
    function av_on_activation() {
        add_option( 'av_api_key', '' );
        add_option( 'av_calendar_id', '' );
        add_option( 'av_selector', '#availability-calendar' );
        add_option( 'av_refresh_minutes', 5 );
    }
}
register_activation_hook( __FILE__, 'av_on_activation' );

/* ------------------------------------------------------------------
 * Plugin action link to Settings on plugins list
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_plugin_action_links' ) ) {
    function av_plugin_action_links( $links ) {
        $settings_link = '<a href="options-general.php?page=av-availability-calendar">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'av_plugin_action_links' );

/* ------------------------------------------------------------------
 * Enqueue FullCalendar assets and localize config for front-end JS
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_enqueue_fullcalendar_assets' ) ) {
    function av_enqueue_fullcalendar_assets() {
        // FullCalendar CSS
        wp_enqueue_style(
            'av-fullcalendar-css',
            'https://unpkg.com/fullcalendar@5.11.3/main.min.css',
            array(),
            '5.11.3'
        );

        // FullCalendar core script (registered then enqueued)
        if ( ! wp_script_is( 'av-fullcalendar-core', 'registered' ) ) {
            wp_register_script(
                'av-fullcalendar-core',
                'https://unpkg.com/fullcalendar@5.11.3/main.min.js',
                array(),
                '5.11.3',
                true
            );
        }

        // FullCalendar Google plugin (depends on core)
        if ( ! wp_script_is( 'av-fullcalendar-google', 'registered' ) ) {
            wp_register_script(
                'av-fullcalendar-google',
                'https://unpkg.com/@fullcalendar/google-calendar@5.11.3/main.global.min.js',
                array( 'av-fullcalendar-core' ),
                '5.11.3',
                true
            );
        }

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
}
add_action( 'wp_enqueue_scripts', 'av_enqueue_fullcalendar_assets' );

/* ------------------------------------------------------------------
 * Shortcode output
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_availability_calendar_shortcode' ) ) {
    function av_availability_calendar_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 'availability-calendar',
        ), $atts, 'availability_calendar' );

        $id = esc_attr( $atts['id'] );

        // A simple container the JS will turn into a FullCalendar instance.
        return '<div id="' . $id . '" class="av-availability-calendar" style="max-width:1100px;margin:0 auto;"></div>';
    }
}
add_shortcode( 'availability_calendar', 'av_availability_calendar_shortcode' );

/* ------------------------------------------------------------------
 * Inline JS initializer (internally modular)
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_add_fullcalendar_init_script' ) ) {
    function av_add_fullcalendar_init_script() {
        $init_js = <<<'JS'
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof FullCalendar === 'undefined' || !window.availabilityCalendarConfig) return;

    var container = document.querySelector(availabilityCalendarConfig.selector);
    if (!container) return;

    function extractDateOnly(value) {
      if (!value) return null;
      if (typeof value === 'string') {
        var tIndex = value.indexOf('T');
        if (tIndex !== -1) return value.split('T')[0];
        return value;
      }
      if (typeof value === 'object') {
        if (value.date) return value.date;
        if (value.dateTime) {
          if (typeof value.dateTime === 'string') {
            var idx = value.dateTime.indexOf('T');
            if (idx !== -1) return value.dateTime.split('T')[0];
            return value.dateTime;
          }
          if (value.dateTime instanceof Date) {
            return value.dateTime.toISOString().split('T')[0];
          }
        }
        if (value instanceof Date) {
          return value.toISOString().split('T')[0];
        }
        try {
          if (typeof value.toISOString === 'function') {
            return value.toISOString().split('T')[0];
          }
        } catch (e) {}
      }
      return null;
    }

    function buildEventDataTransform() {
      return function (rawEvent) {
        var ev = Object.assign({}, rawEvent);
        ev.title = ev.title || ev.summary || ev.name || 'Unavailable';
        var startDate = extractDateOnly(rawEvent.startStr || rawEvent.start || ev.start);
        var endDate   = extractDateOnly(rawEvent.endStr   || rawEvent.end   || ev.end);
        if (!startDate) return ev;
        if (!endDate) {
          var tmp = new Date(startDate + 'T00:00:00');
          tmp.setDate(tmp.getDate() + 1);
          endDate = tmp.toISOString().split('T')[0];
        }
        ev.start = startDate;
        ev.end = endDate;
        ev.allDay = true;
        ev.display = 'background';
        ev.classNames = (ev.classNames || []).concat(['av-bg-event']);
        ev.title = 'Unavailable';
        return ev;
      };
    }

    function initCalendar() {
      var calendar = new FullCalendar.Calendar(container, {
        initialView: 'dayGridMonth',
        height: 'auto',
        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
        googleCalendarApiKey: availabilityCalendarConfig.apiKey,
        events: { googleCalendarId: availabilityCalendarConfig.calendarId },
        eventDataTransform: buildEventDataTransform(),
        dayMaxEventRows: 3,
        navLinks: true,
        selectable: false,
        editable: false,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false }
      });

      calendar.render();

      var refreshMinutes = parseInt(availabilityCalendarConfig.refreshMinutes, 10) || 5;
      if (refreshMinutes > 0) {
        setInterval(function () {
          calendar.refetchEvents();
        }, refreshMinutes * 60 * 1000);
      }
    }

    initCalendar();
  });
})();
JS;

        wp_add_inline_script( 'av-fullcalendar-core', $init_js );
    }
}
add_action( 'wp_enqueue_scripts', 'av_add_fullcalendar_init_script', 20 );

/* ------------------------------------------------------------------
 * Admin menu registration
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_admin_menu' ) ) {
    function av_admin_menu() {
        add_options_page(
            'Availability Calendar Settings',
            'Availability Calendar',
            'manage_options',
            'av-availability-calendar',
            'av_settings_page'
        );

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
}
add_action( 'admin_menu', 'av_admin_menu' );

/* ------------------------------------------------------------------
 * Settings registration
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_register_settings' ) ) {
    function av_register_settings() {
        register_setting( 'av_settings_group', 'av_api_key' );
        register_setting( 'av_settings_group', 'av_calendar_id' );
        register_setting( 'av_settings_group', 'av_selector' );
        register_setting( 'av_settings_group', 'av_refresh_minutes' );
    }
}
add_action( 'admin_init', 'av_register_settings' );

/* ------------------------------------------------------------------
 * Helper: process settings form submission
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_handle_settings_save' ) ) {
    function av_handle_settings_save() {
        $api_key     = isset( $_POST['av_api_key'] ) ? sanitize_text_field( $_POST['av_api_key'] ) : '';
        $calendar_id = isset( $_POST['av_calendar_id'] ) ? sanitize_text_field( $_POST['av_calendar_id'] ) : '';
        $selector    = isset( $_POST['av_selector'] ) ? sanitize_text_field( $_POST['av_selector'] ) : '#availability-calendar';
        $refresh     = isset( $_POST['av_refresh_minutes'] ) ? intval( $_POST['av_refresh_minutes'] ) : 5;

        update_option( 'av_api_key', $api_key );
        update_option( 'av_calendar_id', $calendar_id );
        update_option( 'av_selector', $selector );
        update_option( 'av_refresh_minutes', $refresh );
    }
}

/* ------------------------------------------------------------------
 * Settings page render + handling
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_settings_page' ) ) {
    function av_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>You do not have permission to view this page.</p></div>';
            return;
        }

        if ( isset( $_POST['av_settings_submit'] ) ) {
            check_admin_referer( 'av_settings_save', 'av_settings_nonce' );
            av_handle_settings_save();
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $api_key     = esc_attr( get_option( 'av_api_key', '' ) );
        $calendar_id = esc_attr( get_option( 'av_calendar_id', '' ) );
        $selector    = esc_attr( get_option( 'av_selector', '#availability-calendar' ) );
        $refresh     = intval( get_option( 'av_refresh_minutes', 5 ) );
        ?>
        <div class="wrap">
          <h1>Availability Calendar Settings</h1>
          <form method="post" action="">
            <?php
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
}

/* ------------------------------------------------------------------
 * Inline CSS for calendar container and the background "CLOSED" event
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'av_add_styles' ) ) {
    function av_add_styles() {
        $css = '
        /* Outer container background */
        .av-availability-calendar { background: #f8f8f8; padding: 14px; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.8); }

        /* Force uniform OffWhite for the month grid (override FullCalendar striping) */
        .fc .fc-daygrid-body .fc-row,
        .fc .fc-daygrid-day,
        .fc .fc-daygrid-day-frame,
        .fc .fc-daygrid-day-top,
        .fc .fc-daygrid-day-bg {
          background: #f8f8f8 !important;
          background-image: none !important;
        }

        /* Make the day number (date) text black */
        .fc .fc-daygrid-day-number,
        .fc .fc-daygrid-day-top {
          color: #000 !important;
        }

        /* Ensure any text inside day cells is black */
        .fc .fc-daygrid-day-frame,
        .fc .fc-daygrid-day-frame * {
          color: #000 !important;
        }

        /* Force event title text black where visible */
        .fc .fc-daygrid-event .fc-event-title,
        .fc .fc-event-title,
        .fc .fc-event-title-container {
          color: #000 !important;
        }

        /* CLOSED background event styling (unchanged) */
        .av-bg-event { background-color: rgb(202, 202, 202) !important; opacity: 1 !important; }

        /* Prevent blocked overlays from showing in other-month padding cells */
        .fc .fc-daygrid-day.fc-daygrid-day-other .av-bg-event,
        .fc .fc-daygrid-day.fc-day-other .av-bg-event,
        .fc .fc-daygrid-day-other .av-bg-event {
          display: none !important;
        }

        

        /* IMPORTANT: remove FullCalendars internal vertical scroller so no right-hand scrollbar appears */
        .fc .fc-scroller {
          overflow-y: visible !important;
          max-height: none !important;
        }

        /* Also make sure the outer container doesnt show a scrollbar */
        .av-availability-calendar { overflow: visible !important; }
        ';
        wp_add_inline_style( 'av-fullcalendar-css', $css );
    }
}
add_action( 'wp_enqueue_scripts', 'av_add_styles', 30 );


/* End of plugin file - no closing PHP tag to avoid accidental whitespace output */
