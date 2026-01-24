=== Availability Calendar ===
Contributors: Stephen Williams
Tags: calendar, availability, google-calendar, fullcalendar
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0
License: GPLv2 or later

A simple plugin that reads a public Google Calendar and renders it with FullCalendar as an availability calendar where busy days are shown as greyed-out background events.

== Installation ==
1. Upload the plugin folder to the `/wp-content/plugins/` directory, or upload the zip file via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → Availability Calendar. Enter your Google API key and the Calendar ID (make the calendar public or ensure API access).
4. Place the shortcode [availability_calendar] on a page.

== Notes ==
- This plugin expects the Google Calendar to be public (read-only). If you cannot make it public, you'll need a server-side proxy using a service account; contact a developer for that setup.
- FullCalendar assets are loaded from the unpkg CDN.
