<?php
/**
 * Plugin Name: Availability Calendar (Google Calendar -> FullCalendar)
 * Description: Displays a Google Calendar as an availability calendar where busy days are greyed out. Uses FullCalendar and reads a public Google Calendar. Includes a settings page to enter API key and Calendar ID.
 * Version: 1.1
 * Author: Stephen Williams
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register default options on activation.
 */
function av_on_activation() {
    add_option('av_api_key', '');
    add_option('av_calendar_id', '');
    add_option('av_selector', '#availability-calendar');
    add_option('av_refresh_minutes', 5);
}
register_activation_hook(__FILE__, 'av_on_activation');

/**
 * Add a "Settings" link on the Plugins page for easy access.
 */
function av_plugin_action_links($links) {
    $settings_link = '<a href="options-general.php?page=av-availability-calendar">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'av_plugin_action_links');

/**
 * Enqueue FullCalendar assets and localize config.
 */
function av_enqueue_fullcalendar_assets() {
    // FullCalendar CSS
    wp_enqueue_style(
        'av-fullcalendar-css',
        'https://unpkg.com/fullcalendar@5.11.3/main.min.css',
        array(),
        '5.11.3'
    );

    // FullCalendar core + google plugin
    wp_register_script(
        'av-fullcalendar-core',
        'https://unpkg.com/fullcalendar@5.11.3/main.min.js',
        array(),
        '5.11.3',
        true
    );

    wp_register_script(
        'av-fullcalendar-google',
        'https://unpkg.com/@fullcalendar/google-calendar@5.11.3/main.global.min.js',
        array('av-fullcalendar-core'),
        '5.11.3',
        true
    );

    wp_enqueue_script('av-fullcalendar-core');
    wp_enqueue_script('av-fullcalendar-google');

    $api_key = get_option('av_api_key', '');
    $calendar_id = get_option('av_calendar_id', '');
    $selector = get_option('av_selector', '#availability-calendar');
    $refresh = intval(get_option('av_refresh_minutes', 5));

    $config = array(
        'apiKey'     => $api_key,
        'calendarId' => $calendar_id,
        'selector'   => $selector,
        'refreshMinutes' => $refresh
    );
    wp_localize_script('av-fullcalendar-core', 'availabilityCalendarConfig', $config);
}
add_action('wp_enqueue_scripts', 'av_enqueue_fullcalendar_assets');


/**
 * Shortcode to output calendar container.
 */
function av_availability_calendar_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id' => 'availability-calendar',
    ), $atts, 'availability_calendar');

    $id = esc_attr($atts['id']);
    $html = '<div id="'. $id .'" class="av-availability-calendar" style="max-width:1100px;margin:0 auto;"></div>';
    return $html;
}
add_shortcode('availability_calendar', 'av_availability_calendar_shortcode');


/**
 * Inline initialization script - transforms Google events into all-day background events
 */
function av_add_fullcalendar_init_script() {
    $init_js = <<<'JS'
(function(){
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof FullCalendar === 'undefined' || !window.availabilityCalendarConfig) return;
    var el = document.querySelector(availabilityCalendarConfig.selector);
    if (!el) {
      return;
    }

    var calendar = new FullCalendar.Calendar(el, {
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

      /* Transform every incoming event so it becomes an ALL-DAY background event that blocks whole day cells */
      eventDataTransform: function(rawEvent) {
        var ev = Object.assign({}, rawEvent);

        function toDateOnly(iso) {
          if (!iso) return null;
          return iso.split('T')[0];
        }

        var startDate = toDateOnly(ev.startStr) || toDateOnly(ev.start) || null;
        var endDate = toDateOnly(ev.endStr) || toDateOnly(ev.end) || null;

        if (!startDate) return ev;

        if (!endDate) {
          var d = new Date(startDate + 'T00:00:00');
          d.setDate(d.getDate() + 1);
          endDate = d.toISOString().split('T')[0];
        }

        ev.start = startDate;
        ev.end = endDate;
        ev.allDay = true;
        ev.display = 'background';
        ev.classNames = (ev.classNames || []).concat(['av-bg-event']);
        return ev;
      },

      dayMaxEventRows: 3,
      navLinks: true,
      selectable: false,
      editable: false,
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false }
    });

    calendar.render();

    var refreshMinutes = parseInt(availabilityCalendarConfig.refreshMinutes) || 5;
    if (refreshMinutes > 0) {
      setInterval(function(){
        calendar.refetchEvents();
      }, refreshMinutes * 60 * 1000);
    }
  });
})();
JS;

    wp_add_inline_script('av-fullcalendar-core', $init_js);
}
add_action('wp_enqueue_scripts', 'av_add_fullcalendar_init_script', 20);


/**
 * Admin menu and settings page
 */
function av_admin_menu() {
    // Add settings page under Settings
    add_options_page(
        'Availability Calendar Settings',
        'Availability Calendar',
        'manage_options',
        'av-availability-calendar',
        'av_settings_page'
    );

    // Also add top-level menu for easier access if desired (comment out if you prefer only under Settings)
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
add_action('admin_menu', 'av_admin_menu');


/**
 * Register settings (for Settings API compatibility)
 */
function av_register_settings() {
    register_setting('av_settings_group', 'av_api_key');
    register_setting('av_settings_group', 'av_calendar_id');
    register_setting('av_settings_group', 'av_selector');
    register_setting('av_settings_group', 'av_refresh_minutes');
}
add_action('admin_init', 'av_register_settings');


function av_settings_page() {
    if (!current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>You do not have permission to view this page.</p></div>';
        return;
    }

    // Save handled by register_setting and the form below, but keep backwards compatible save handling
    if (isset($_POST['av_settings_submit'])) {
        check_admin_referer('av_settings_save', 'av_settings_nonce');

        $api_key = isset($_POST['av_api_key']) ? sanitize_text_field($_POST['av_api_key']) : '';
        $calendar_id = isset($_POST['av_calendar_id']) ? sanitize_text_field($_POST['av_calendar_id']) : '';
        $selector = isset($_POST['av_selector']) ? sanitize_text_field($_POST['av_selector']) : '#availability-calendar';
        $refresh = isset($_POST['av_refresh_minutes']) ? intval($_POST['av_refresh_minutes']) : 5;

        update_option('av_api_key', $api_key);
        update_option('av_calendar_id', $calendar_id);
        update_option('av_selector', $selector);
        update_option('av_refresh_minutes', $refresh);

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $api_key = esc_attr(get_option('av_api_key', ''));
    $calendar_id = esc_attr(get_option('av_calendar_id', ''));
    $selector = esc_attr(get_option('av_selector', '#availability-calendar'));
    $refresh = intval(get_option('av_refresh_minutes', 5));
    ?>
    <div class="wrap">
      <h1>Availability Calendar Settings</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('av_settings_group');
          do_settings_sections('av_settings_group');
          wp_nonce_field('av_settings_save', 'av_settings_nonce');
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

/**
 * Add minimal styling via inline CSS for the calendar container and background events.
 */
function av_add_styles() {
    $css = '
    .av-availability-calendar { background: #fff; padding: 14px; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
    .av-bg-event { background-color: rgba(96, 100, 152, 0.18) !important; }
    .fc .fc-daygrid-day-frame { min-height: 80px; }
    ';
    wp_add_inline_style('av-fullcalendar-css', $css);
}
add_action('wp_enqueue_scripts', 'av_add_styles', 30);
