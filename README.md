# Click Hive Utility

WordPress utility plugin that enhances the admin dashboard with improved functionality and customization options.

## Features

- Custom admin utility area
- Dashboard customizations
- Improved admin interface
- Monthly Hours: contract budgets and hours used, synced nightly from ClickUp
- Client Access: a Client role with an allowlisted admin sidebar and login redirect to the dashboard

## Installation

1. Download the latest release
2. Upload to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Changelog

### 1.2.1
- Monthly Hours accepts raw ClickUp time entries from Make and adds them up by task tag on the site. An entry counts only when its task has exactly one billing tag (seo, ads management, consulting, web development, website changes); "internal" time is never shown, and entries with no billing tag or more than one are reported back to Make as unsorted

### 1.2.0
- Added Monthly Hours (Click Hive > Monthly Hours): a Dashboard > Home widget showing each contract item's monthly budget and the hours used against it, with previous months below
- Click Hive's nightly Make scenario posts the month's ClickUp hours (by task tag) and contract budgets to `/wp-json/click-hive/v1/hours`, authenticated with the site's Dashboard Key
- Hours on a tag that matches a contract item count toward that item; all other hours count toward Available. Hosting shows its budget with no hours
- Off by default on every site: check Enable Monthly Hours on Click Hive > Monthly Hours to turn it on. While off, no widget shows and updates from Make are refused
- The widget stays hidden until the first update arrives

### 1.1.0
- Merged the CHM Client Dashboard plugin in as Click Hive > Client Dashboard, with an on/off switch. Existing dashboard settings carry over; sites that had the separate plugin start with it on. While the separate plugin is still active, Click Hive Utility leaves the dashboard to it and shows a notice to remove it
- Client Dashboard widget is always pinned to the top of Dashboard > Home, above Rank Math and other plugin widgets, including for users with a saved widget order
- Client Access now uses a checklist of every admin page, including subpages. Check a top-level item for the whole section, or check individual subpages (for example Tickets > Attendees and Orders) to give Clients only those
- Unchecked pages are blocked for Clients even by direct link; Clients are sent to the Dashboard or their first allowed page
- Checked pages that require a capability the Client role lacks (such as manage_options) open by granting that capability on that page only
- Debug panel is now a checkbox on Client Access instead of a URL parameter; it notes which page a Client was blocked from, and still appears on plugin screens that hide admin notices
- Warning on the settings page when manage_options is in Extra capabilities, since it makes Clients bypass all limits
- Sites upgrading from 1.0.x keep their old list until Client Access is saved again

### 1.0.10
- Fixed links in dark table header and footer rows rendering black (invisible); they now show white and turn orange on hover

### 1.0.9
- Added Client Access (Click Hive > Client Access): Client role, sidebar allowlist, extra capabilities, login redirect to the dashboard, and a debug view (`?chcm_debug=1`)
- Fixed admin styling save overwriting other plugin settings
- Removed duplicate settings save handler and leftover debug script

### 1.0.6
- Fixed Admin Dashboard Toggle challenge
### 1.0.3 
- Basic Admin Dashboard Changes
- Bug Fixes
### 1.0.0
- Initial release