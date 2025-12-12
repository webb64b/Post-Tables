# PDS Post Tables

Display and edit WordPress posts in Excel-like tables with customizable columns and conditional formatting.

## Features

- **Excel-like Interface**: Powered by Tabulator for a familiar spreadsheet experience
- **Inline Editing**: Click any cell to edit - changes save automatically via AJAX
- **Multiple Field Sources**: Works with core post fields, ACF fields, custom meta, and taxonomies
- **Column Configuration**: Drag-and-drop column ordering, custom labels, toggle edit/sort/filter per column
- **Conditional Formatting**: Cell and row-level formatting rules with layered styles
- **Column Defaults**: Set alignment, width, date/number formats, and default colors per column
- **Frontend Formatting Toolbar**: Excel-like formatting from the frontend (for logged-in users with edit permissions)
- **Shortcode Output**: Embed tables anywhere with `[pds_table id="123"]`
- **CSV Export**: One-click export of table data

## Installation

1. Upload the `pds-post-tables` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Post Tables** in the admin menu

## Creating a Table

1. Go to **Post Tables > Add New**
2. Give your table a name
3. Select the **Post Type** to display (Posts, Pages, or any custom post type)
4. Add columns using the **Add Column** button:
   - Select a field from the dropdown (grouped by Post Fields, ACF, Meta, Taxonomies)
   - Set a custom label
   - Toggle Edit, Sort, and Filter options
   - Drag to reorder columns
5. Configure **Column Formatting Defaults** (optional):
   - Text alignment
   - Column width
   - Date/number formats
   - Default background/text colors
6. Add **Conditional Formatting Rules** (optional):
   - Cell rules: Format specific column cells based on their value
   - Row rules: Format entire rows based on any field value
   - Rules are layered - multiple matching rules stack their styles
7. Adjust **Table Settings** in the sidebar:
   - Pagination
   - Rows per page
   - Row height
   - CSV export toggle
8. Publish the table

## Data Filters

Pre-filter which posts appear in the table by setting conditions in the **Data Filters** section:

### Adding Filters
1. Click **+ Add Filter**
2. Select a field to filter by (Post Fields, ACF, Meta, or Taxonomies)
3. Choose an operator:
   - **Equals / Not Equals**: Exact match
   - **Contains / Does Not Contain**: Partial text match
   - **Starts With / Ends With**: Text prefix/suffix match
   - **Greater Than / Less Than**: Numeric or date comparison
   - **Greater Than or Equal / Less Than or Equal**: Inclusive comparison
   - **Is Empty / Is Not Empty**: Check for blank values
   - **In List / Not In List**: Match against comma-separated values
4. Enter the comparison value

### Filter Logic
- **All conditions (AND)**: Posts must match ALL filters
- **Any condition (OR)**: Posts must match AT LEAST ONE filter

### Examples
- Show only published posts: `post_status` equals `publish`
- Show posts from 2024: `post_date` greater than or equal `2024-01-01`
- Show posts in specific categories: `category` in `news, updates, announcements`
- Show posts with a specific ACF value: `project_status` equals `active`
- Exclude drafts: `post_status` not equals `draft`

## Frontend Formatting Toolbar

When logged-in users with edit permissions view a table, they see a formatting toolbar above the table:

### Selection
- **Click a cell**: Select that cell for formatting
- **Click row number**: Select the entire row for formatting  
- **Click a column header**: Select the entire column for formatting
- **Click outside table**: Deselect current selection

### Editing
- **Double-click a cell**: Edit the cell value (for editable columns)
- **Enter**: Save edit
- **Escape**: Cancel edit

### Formatting Options
- **Background Color**: Set background color for selection
- **Text Color**: Set text color for selection
- **Bold**: Toggle bold text for selection
- **Clear**: Remove custom formatting from selection

### Formatting Hierarchy (layered, each level overrides the previous):
1. Column defaults (set in admin)
2. Conditional formatting rules (set in admin)
3. Custom column formats (set via frontend toolbar)
4. Custom row formats (set via frontend toolbar)
5. Custom cell formats (set via frontend toolbar)

## Using the Shortcode

Basic usage:
```
[pds_table id="123"]
```

With custom row limit:
```
[pds_table id="123" limit="10"]
```

With custom CSS class:
```
[pds_table id="123" class="my-custom-table"]
```

## Field Type Support

| Field Type | Display | Inline Editor |
|------------|---------|---------------|
| Text | Plain text | Text input |
| Number | Formatted number | Number input |
| Date | Formatted date | Date picker |
| Boolean | ✓ / ✗ | Checkbox toggle |
| Select/Dropdown | Label from options | Dropdown list |

## Conditional Formatting

### Operators

- `equals` / `not equals`
- `contains` / `not contains`
- `greater than` / `less than`
- `is empty` / `is not empty`
- `is checked` / `is not checked` (for booleans)

### Special Tokens

- `{{TODAY}}` - Current date (for date comparisons)

### Examples

**Highlight completed items green:**
- Scope: Cell
- Column: Status
- When: equals "Complete"
- Style: Background #d4edda, Text #155724

**Highlight overdue rows red:**
- Scope: Row
- When: Due Date is less than {{TODAY}}
- Style: Background #f8d7da, Text #721c24

## REST API Endpoints

The plugin exposes REST API endpoints under `/wp-json/pds-tables/v1/`:

- `GET /tables/{id}/data` - Fetch table data with pagination/sorting/filtering
- `POST /tables/{id}/data` - Update a cell value
- `GET /tables/{id}/config` - Get table configuration
- `GET /fields/{post_type}` - Get available fields for a post type

## Requirements

- WordPress 5.0+
- PHP 7.4+
- ACF (Advanced Custom Fields) - optional, for ACF field support

## Changelog

### 1.0.0
- Initial release

## License

GPL v2 or later
