(function() {
    'use strict';

    /**
     * PDS Post Tables - Frontend Table Handler
     */
    class PDSPostTable {
        constructor(container) {
            this.container = container;
            this.config = JSON.parse(container.dataset.config);
            this.tableId = this.config.tableId;
            this.table = null;
            this.wrapper = container.closest('.pds-post-table-wrap');
            
            // Selection state
            this.selection = {
                type: null, // 'cell', 'row', 'column'
                cell: null,
                row: null,
                column: null,
                postId: null,
                fieldKey: null,
            };
            
            // Custom formats loaded from server
            this.customColumnFormats = this.config.customColumnFormats || {};
            
            // Pending changes for batch save mode
            // Structure: { "postId_fieldKey": { postId, fieldKey, oldValue, newValue, cell } }
            this.pendingChanges = {};
            this.autoSaveTimer = null;
            this.isSaving = false;
            
            // Change history for future undo/redo
            // Structure: [{ postId, fieldKey, oldValue, newValue, timestamp }]
            this.changeHistory = [];
            this.historyIndex = -1;
            
            this.init();
        }

        /**
         * Initialize the table
         */
        init() {
            const columns = this.buildColumns();
            const settings = this.config.settings || {};
            const paginationEnabled = settings.pagination !== false;
            const pageSize = settings.page_size || 25;
            
            // Store reference for callbacks
            const self = this;
            
            const tableConfig = {
                columns: columns,
                layout: 'fitData', // Size columns to data, scroll bar appears if columns exceed container
                rowFormatter: (row) => this.applyRowFormatting(row),
                placeholder: 'Loading data...',
                selectable: false, // We handle selection ourselves
                headerSortClickElement: 'icon', // Only sort when clicking sort arrow, not whole header
                index: 'ID', // Use ID field as row index for updateData
            };
            
            // Persistence - remember user's column widths, sort, filters, visibility
            if (settings.persist_state !== false) {
                tableConfig.persistence = {
                    sort: true,
                    filter: true,
                    columns: ['width', 'visible'],
                };
                tableConfig.persistenceID = 'pds_table_' + this.tableId;
                tableConfig.persistenceMode = 'local';
            }
            
            // Row grouping
            if (settings.group_by) {
                tableConfig.groupBy = settings.group_by;
                tableConfig.groupStartOpen = settings.group_start_open !== false;
                tableConfig.groupToggleElement = 'header';
                tableConfig.groupHeader = (value, count, data, group) => {
                    // Get the column title for the group field
                    const groupCol = this.config.columns.find(c => c.field === settings.group_by);
                    const colTitle = groupCol ? groupCol.title : settings.group_by;
                    
                    // For select fields, try to get the label instead of value
                    let displayValue = value;
                    if (groupCol && groupCol.fieldOptions && groupCol.fieldOptions[value]) {
                        displayValue = groupCol.fieldOptions[value];
                    }
                    
                    return `<span class="pds-group-title">${colTitle}: ${displayValue || '(empty)'}</span>` +
                           `<span class="pds-group-count">(${count} ${count === 1 ? 'row' : 'rows'})</span>`;
                };
            }
            
            // Configure data loading based on pagination setting
            if (paginationEnabled) {
                // Use remote pagination - loads data page by page
                tableConfig.pagination = true;
                tableConfig.paginationMode = 'remote';
                tableConfig.paginationSize = pageSize;
                tableConfig.paginationSizeSelector = [10, 25, 50, 100];
                tableConfig.height = 'auto';
                tableConfig.maxHeight = '600px';
                
                // Ajax configuration for paginated loading
                tableConfig.ajaxURL = pdsPostTables.restUrl + '/tables/' + this.tableId + '/data';
                tableConfig.ajaxConfig = {
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': pdsPostTables.nonce
                    }
                };
                tableConfig.ajaxURLGenerator = (url, config, params) => {
                    // Build URL with pagination params
                    const queryParams = new URLSearchParams();
                    queryParams.set('page', params.page || 1);
                    queryParams.set('per_page', params.size || pageSize);
                    if (params.sort && params.sort.length > 0) {
                        queryParams.set('sort_field', params.sort[0].field);
                        queryParams.set('sort_dir', params.sort[0].dir);
                    }
                    return url + '?' + queryParams.toString();
                };
                tableConfig.ajaxResponse = (url, params, response) => {
                    // Transform response for Tabulator
                    this.totalRows = response.total || 0;
                    const lastPage = Math.ceil(this.totalRows / (params.size || pageSize));
                    return {
                        data: response.rows || [],
                        last_page: lastPage,
                    };
                };
            } else {
                // No pagination - load all data, show full table height
                tableConfig.ajaxURL = pdsPostTables.restUrl + '/tables/' + this.tableId + '/data';
                tableConfig.ajaxConfig = {
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': pdsPostTables.nonce
                    }
                };
                tableConfig.ajaxURLGenerator = (url, config, params) => {
                    const queryParams = new URLSearchParams();
                    queryParams.set('per_page', 10000); // Load all rows
                    if (params.sort && params.sort.length > 0) {
                        queryParams.set('sort_field', params.sort[0].field);
                        queryParams.set('sort_dir', params.sort[0].dir);
                    }
                    return url + '?' + queryParams.toString();
                };
                tableConfig.ajaxResponse = (url, params, response) => {
                    this.totalRows = response.total || 0;
                    return response.rows || [];
                };
            }

            // Row height
            if (settings.row_height === 'compact') {
                tableConfig.rowHeight = 30;
            } else if (settings.row_height === 'comfortable') {
                tableConfig.rowHeight = 50;
            }

            // Default sorting (only if no persisted sort)
            if (settings.default_sort_column && !settings.persist_state) {
                tableConfig.initialSort = [
                    { column: settings.default_sort_column, dir: settings.default_sort_dir || 'asc' }
                ];
            } else if (settings.default_sort_column) {
                // Set initial sort but persistence will override if user changed it
                tableConfig.initialSort = [
                    { column: settings.default_sort_column, dir: settings.default_sort_dir || 'asc' }
                ];
            }

            // Create table
            this.table = new Tabulator(this.container, tableConfig);

            // Event handlers
            this.table.on('cellEdited', (cell) => this.handleCellEdit(cell));
            this.table.on('dataLoaded', () => this.updateTableInfo());
            this.table.on('pageLoaded', () => this.updateTableInfo());
            
            // Selection handlers
            this.table.on('cellClick', (e, cell) => this.handleCellClick(e, cell));
            this.table.on('rowClick', (e, row) => this.handleRowClick(e, row));
            
            // Double-click to edit
            this.table.on('cellDblClick', (e, cell) => this.handleCellDblClick(e, cell));
            
            // Click outside table to clear selection
            document.addEventListener('click', (e) => {
                if (!this.wrapper.contains(e.target)) {
                    this.clearSelection();
                }
            });
            
            // Click on table background (not on cells) to clear selection
            this.container.addEventListener('click', (e) => {
                if (e.target === this.container || e.target.classList.contains('tabulator-tableholder')) {
                    this.clearSelection();
                }
            });

            // Export button
            this.initExportButton();
            
            // Column toggle (after table built so columns are available)
            this.table.on('tableBuilt', () => {
                this.initColumnToggle();
                this.initBatchSave();
                this.initStickyScrollbar();
                this.initRealtimeSync();
            });

            // Toolbar (if user can edit)
            if (this.config.canEdit) {
                this.initToolbar();
            }
        }
        
        /**
         * Handle double-click to edit cell
         * ALL editing is triggered here on double-click only
         */
        handleCellDblClick(e, cell) {
            const field = cell.getField();
            if (!field) {
                return;
            }

            // Get colId from cell's data attribute (set during formatting)
            const cellEl = cell.getElement();
            const colId = cellEl.dataset.colId;

            // Check our custom config map for this column
            const colConfig = this.columnConfigMap?.[colId];
            if (!colConfig || !colConfig.editable) {
                return;
            }
            
            // Can't edit if user doesn't have permission
            if (!this.config.canEdit) {
                return;
            }
            
            // Clear selection first
            this.clearSelection();

            // Track edit start for conflict detection
            this.trackEditStart(cell);

            const editorType = colConfig.editorType;
            
            // Handle different editor types
            switch (editorType) {
                case 'modal':
                    // WYSIWYG/textarea - open modal
                    this.openContentModal(cell, true);
                    break;
                    
                case 'date':
                    // Date picker
                    this.openInlineEditor(cell, 'date');
                    break;
                    
                case 'datetime':
                    // Datetime picker
                    this.openInlineEditor(cell, 'datetime');
                    break;
                    
                case 'list':
                    // Dropdown select
                    this.openInlineEditor(cell, 'list', colConfig.fieldOptions);
                    break;

                case 'userMulti':
                    // Multi-select user picker
                    this.openUserMultiEditor(cell, colConfig.fieldOptions);
                    break;

                case 'tickCross':
                    // Checkbox - just toggle
                    this.toggleCheckbox(cell);
                    break;
                    
                case 'number':
                    // Number input
                    this.openInlineEditor(cell, 'number');
                    break;
                    
                case 'input':
                default:
                    // Text input
                    this.openInlineEditor(cell, 'input');
                    break;
            }
        }
        
        /**
         * Open an inline editor for a cell
         */
        openInlineEditor(cell, type, options = null) {
            const cellEl = cell.getElement();
            const currentValue = cell.getValue() || '';
            const field = cell.getField();
            const postId = cell.getRow().getData().ID;

            // Get cell position using getBoundingClientRect for accurate positioning
            // This works correctly even with frozen columns that use position:sticky
            const cellRect = cellEl.getBoundingClientRect();

            // Create editor container with fixed positioning based on cell's viewport position
            const editorContainer = document.createElement('div');
            editorContainer.className = 'pds-inline-editor';
            editorContainer.style.cssText = `
                position: fixed;
                top: ${cellRect.top}px;
                left: ${cellRect.left}px;
                width: ${cellRect.width}px;
                height: ${cellRect.height}px;
                z-index: 10000;
                background: #fff;
                display: flex;
                align-items: center;
                padding: 2px;
                box-sizing: border-box;
            `;

            let editor;

            if (type === 'date') {
                editor = document.createElement('input');
                editor.type = 'date';
                editor.style.cssText = 'width:100%;padding:4px;border:1px solid #0073aa;border-radius:2px;';
                // Convert to YYYY-MM-DD
                if (currentValue && /^\d{4}-\d{2}-\d{2}$/.test(currentValue)) {
                    editor.value = currentValue;
                } else if (currentValue) {
                    const date = new Date(currentValue);
                    if (!isNaN(date.getTime())) {
                        editor.value = date.toISOString().split('T')[0];
                    }
                }
            } else if (type === 'datetime') {
                editor = document.createElement('input');
                editor.type = 'datetime-local';
                editor.style.cssText = 'width:100%;padding:4px;border:1px solid #0073aa;border-radius:2px;';
                if (currentValue) {
                    // Try to convert to datetime-local format
                    const date = new Date(currentValue);
                    if (!isNaN(date.getTime())) {
                        editor.value = date.toISOString().slice(0, 16);
                    }
                }
            } else if (type === 'list' && options) {
                editor = document.createElement('select');
                editor.style.cssText = 'width:100%;padding:4px;border:1px solid #0073aa;border-radius:2px;';
                // Add empty option
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = '-- Select --';
                editor.appendChild(emptyOpt);
                // Add options
                for (const key in options) {
                    const opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = options[key];
                    if (key === currentValue || options[key] === currentValue) {
                        opt.selected = true;
                    }
                    editor.appendChild(opt);
                }
            } else if (type === 'number') {
                editor = document.createElement('input');
                editor.type = 'number';
                editor.style.cssText = 'width:100%;padding:4px;border:1px solid #0073aa;border-radius:2px;';
                editor.value = currentValue;
            } else {
                // Default text input
                editor = document.createElement('input');
                editor.type = 'text';
                editor.style.cssText = 'width:100%;padding:4px;border:1px solid #0073aa;border-radius:2px;';
                editor.value = currentValue;
            }

            editorContainer.appendChild(editor);

            // Append to body instead of cell to avoid positioning issues with frozen columns
            document.body.appendChild(editorContainer);

            // Store reference to editor container on cell for cleanup
            cellEl._pdsEditorContainer = editorContainer;

            // Focus editor
            editor.focus();
            if (editor.select) editor.select();

            // For date inputs, try to show picker
            if ((type === 'date' || type === 'datetime') && editor.showPicker) {
                try { editor.showPicker(); } catch (e) {}
            }
            
            const self = this;
            let saved = false;
            
            const saveAndClose = () => {
                if (saved) return;
                saved = true;

                const newValue = editor.value;

                // Clear edit tracking
                self.clearCurrentEdit();

                // Remove editor
                if (editorContainer.parentNode) {
                    editorContainer.parentNode.removeChild(editorContainer);
                }

                // Save if changed
                if (newValue !== currentValue) {
                    // Update cell value (this will trigger cellEdited event)
                    cell.setValue(newValue);
                }
            };

            const cancelEdit = () => {
                if (saved) return;
                saved = true;

                // Clear edit tracking
                self.clearCurrentEdit();

                if (editorContainer.parentNode) {
                    editorContainer.parentNode.removeChild(editorContainer);
                }
            };
            
            // Event handlers
            editor.addEventListener('blur', saveAndClose);
            editor.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveAndClose();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit();
                } else if (e.key === 'Tab') {
                    saveAndClose();
                }
            });
            
            // For select, also save on change
            if (type === 'list') {
                editor.addEventListener('change', saveAndClose);
            }
        }

        /**
         * Open multi-select user editor (checkbox list)
         */
        openUserMultiEditor(cell, options) {
            const cellEl = cell.getElement();
            let currentValue = cell.getValue() || [];
            const field = cell.getField();
            const postId = cell.getRow().getData().ID;

            // Ensure currentValue is an array
            if (!Array.isArray(currentValue)) {
                currentValue = currentValue ? [currentValue] : [];
            }

            // Convert to strings for comparison
            const selectedIds = currentValue.map(id => String(id));

            // Get cell position
            const cellRect = cellEl.getBoundingClientRect();

            // Create editor container
            const editorContainer = document.createElement('div');
            editorContainer.className = 'pds-inline-editor pds-user-multi-editor';
            editorContainer.style.cssText = `
                position: fixed;
                top: ${cellRect.bottom}px;
                left: ${cellRect.left}px;
                min-width: ${Math.max(cellRect.width, 200)}px;
                max-height: 250px;
                overflow-y: auto;
                z-index: 10000;
                background: #fff;
                border: 1px solid #0073aa;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.15);
                padding: 8px;
            `;

            // Build checkbox list
            let checkboxHtml = '';
            for (const userId in options) {
                const userName = options[userId];
                const isChecked = selectedIds.includes(String(userId));
                checkboxHtml += `
                    <label style="display:block;padding:4px 0;cursor:pointer;">
                        <input type="checkbox" value="${userId}" ${isChecked ? 'checked' : ''} style="margin-right:8px;">
                        ${this.escapeHtml(userName)}
                    </label>
                `;
            }

            // Add buttons
            editorContainer.innerHTML = `
                <div class="pds-user-multi-options">${checkboxHtml}</div>
                <div style="margin-top:8px;padding-top:8px;border-top:1px solid #ddd;text-align:right;">
                    <button type="button" class="pds-user-cancel-btn" style="padding:4px 12px;margin-right:4px;">Cancel</button>
                    <button type="button" class="pds-user-save-btn" style="padding:4px 12px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;">Save</button>
                </div>
            `;

            // Append to body
            document.body.appendChild(editorContainer);

            // Store reference
            cellEl._pdsEditorContainer = editorContainer;

            const self = this;
            let closed = false;

            const closeEditor = () => {
                if (closed) return;
                closed = true;
                if (editorContainer.parentNode) {
                    editorContainer.parentNode.removeChild(editorContainer);
                }
            };

            const saveAndClose = () => {
                if (closed) return;

                // Get selected values
                const checkboxes = editorContainer.querySelectorAll('input[type="checkbox"]:checked');
                const newValue = Array.from(checkboxes).map(cb => cb.value);

                closeEditor();

                // Compare arrays
                const oldSorted = [...selectedIds].sort().join(',');
                const newSorted = [...newValue].sort().join(',');

                if (oldSorted !== newSorted) {
                    cell.setValue(newValue);
                }
            };

            // Event handlers
            editorContainer.querySelector('.pds-user-cancel-btn').addEventListener('click', closeEditor);
            editorContainer.querySelector('.pds-user-save-btn').addEventListener('click', saveAndClose);

            // Close on click outside
            const clickOutsideHandler = (e) => {
                if (!editorContainer.contains(e.target) && !cellEl.contains(e.target)) {
                    closeEditor();
                    document.removeEventListener('click', clickOutsideHandler);
                }
            };
            setTimeout(() => {
                document.addEventListener('click', clickOutsideHandler);
            }, 10);

            // Close on Escape
            const keyHandler = (e) => {
                if (e.key === 'Escape') {
                    closeEditor();
                    document.removeEventListener('keydown', keyHandler);
                }
            };
            document.addEventListener('keydown', keyHandler);
        }

        /**
         * Toggle a checkbox/tickCross field
         */
        toggleCheckbox(cell) {
            const currentValue = cell.getValue();
            // Toggle: true -> false, false -> true, anything else -> true
            const newValue = currentValue === true || currentValue === 1 || currentValue === '1' ? false : true;
            cell.setValue(newValue);
        }
        
        /**
         * Initialize formatting toolbar
         */
        initToolbar() {
            const toolbar = this.wrapper.querySelector('.pds-table-toolbar');
            if (!toolbar) return;
            
            // Background color
            const bgColorInput = toolbar.querySelector('.pds-format-bg-color');
            if (bgColorInput) {
                bgColorInput.addEventListener('change', (e) => {
                    this.applyFormat({ background: e.target.value });
                });
            }
            
            // Text color
            const textColorInput = toolbar.querySelector('.pds-format-text-color');
            if (textColorInput) {
                textColorInput.addEventListener('change', (e) => {
                    this.applyFormat({ color: e.target.value });
                });
            }
            
            // Bold toggle
            const boldBtn = toolbar.querySelector('.pds-format-bold');
            if (boldBtn) {
                boldBtn.addEventListener('click', () => {
                    boldBtn.classList.toggle('active');
                    this.applyFormat({ font_weight: boldBtn.classList.contains('active') ? 'bold' : 'normal' });
                });
            }
            
            // Clear formatting
            const clearBtn = toolbar.querySelector('.pds-format-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    this.clearFormat();
                });
            }
            
            // Column header click for column selection
            this.container.addEventListener('click', (e) => {
                const header = e.target.closest('.tabulator-col');
                // Don't select if clicking on sort arrow
                if (header && !e.target.closest('.tabulator-col-sorter')) {
                    const field = header.getAttribute('tabulator-field');
                    if (field) {
                        this.selectColumn(field);
                    }
                }
            });
        }
        
        /**
         * Handle cell click for selection
         */
        handleCellClick(e, cell) {
            // Don't select if editing
            if (cell.getElement().classList.contains('tabulator-editing')) {
                return;
            }
            
            // Don't select on row selector column
            const field = cell.getColumn().getField();
            if (!field) {
                return; // Row selector column has no field
            }
            
            // Clear previous selection
            this.clearSelection();
            
            // Select cell
            this.selection.type = 'cell';
            this.selection.cell = cell;
            this.selection.postId = cell.getRow().getData().ID;
            this.selection.fieldKey = field;
            
            cell.getElement().classList.add('pds-selected');
            
            this.updateSelectionInfo();
        }
        
        /**
         * Handle row click for selection (with modifier key)
         */
        handleRowClick(e, row) {
            // Shift+click anywhere on row to select row
            if (e.shiftKey) {
                e.preventDefault();
                e.stopPropagation();
                this.selectRow(row);
            }
        }
        
        /**
         * Select a row for formatting
         */
        selectRow(row) {
            // Clear previous selection
            this.clearSelection();
            
            // Select row
            this.selection.type = 'row';
            this.selection.row = row;
            this.selection.postId = row.getData().ID;
            
            row.getElement().classList.add('pds-selected-row');
            
            this.updateSelectionInfo();
        }
        
        /**
         * Select column
         */
        selectColumn(fieldKey) {
            // Clear previous selection
            this.clearSelection();
            
            // Select column
            this.selection.type = 'column';
            this.selection.fieldKey = fieldKey;
            this.selection.column = this.table.getColumn(fieldKey);
            
            // Highlight column header
            const header = this.container.querySelector(`.tabulator-col[tabulator-field="${fieldKey}"]`);
            if (header) {
                header.classList.add('pds-selected-column');
            }
            
            // Highlight all cells in column
            this.table.getRows().forEach(row => {
                const cell = row.getCell(fieldKey);
                if (cell) {
                    cell.getElement().classList.add('pds-selected-column-cell');
                }
            });
            
            this.updateSelectionInfo();
        }
        
        /**
         * Clear selection
         */
        clearSelection() {
            // Clear cell selection
            this.container.querySelectorAll('.pds-selected').forEach(el => {
                el.classList.remove('pds-selected');
            });
            
            // Clear row selection
            this.container.querySelectorAll('.pds-selected-row').forEach(el => {
                el.classList.remove('pds-selected-row');
            });
            
            // Clear column selection
            this.container.querySelectorAll('.pds-selected-column, .pds-selected-column-cell').forEach(el => {
                el.classList.remove('pds-selected-column', 'pds-selected-column-cell');
            });
            
            this.selection = {
                type: null,
                cell: null,
                row: null,
                column: null,
                postId: null,
                fieldKey: null,
            };
            
            this.updateSelectionInfo();
        }
        
        /**
         * Update selection info in toolbar
         */
        updateSelectionInfo() {
            const infoEl = this.wrapper.querySelector('.pds-selection-info');
            if (!infoEl) return;
            
            if (!this.selection.type) {
                infoEl.textContent = 'None';
                return;
            }
            
            switch (this.selection.type) {
                case 'cell':
                    infoEl.textContent = `Cell (${this.selection.fieldKey})`;
                    break;
                case 'row':
                    infoEl.textContent = `Row #${this.selection.postId}`;
                    break;
                case 'column':
                    infoEl.textContent = `Column: ${this.selection.fieldKey}`;
                    break;
            }
        }
        
        /**
         * Apply format to selection
         */
        applyFormat(style) {
            if (!this.selection.type) {
                this.showError('Please select a cell, row, or column first');
                return;
            }
            
            const payload = {
                type: this.selection.type,
                style: style,
            };
            
            if (this.selection.postId) {
                payload.post_id = this.selection.postId;
            }
            if (this.selection.fieldKey) {
                payload.field_key = this.selection.fieldKey;
            }
            
            this.saveFormat(payload);
        }
        
        /**
         * Clear format from selection
         */
        clearFormat() {
            if (!this.selection.type) {
                this.showError('Please select a cell, row, or column first');
                return;
            }
            
            const payload = {
                type: this.selection.type,
                clear: true,
            };
            
            if (this.selection.postId) {
                payload.post_id = this.selection.postId;
            }
            if (this.selection.fieldKey) {
                payload.field_key = this.selection.fieldKey;
            }
            
            this.saveFormat(payload);
        }
        
        /**
         * Save format to server
         */
        saveFormat(payload) {
            fetch(pdsPostTables.restUrl + '/tables/' + this.tableId + '/format', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': pdsPostTables.nonce
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update local data immediately (no server reload needed)
                    if (data.type === 'column') {
                        // Update column formats cache
                        if (payload.clear) {
                            delete this.customColumnFormats[data.field_key];
                        } else {
                            this.customColumnFormats[data.field_key] = data.style;
                        }
                    } else if (data.type === 'cell') {
                        // Update cell format in local row data
                        const row = this.table.getRows().find(r => r.getData().ID === data.post_id);
                        if (row) {
                            const rowData = row.getData();
                            if (!rowData._cell_formats) {
                                rowData._cell_formats = {};
                            }
                            if (payload.clear) {
                                delete rowData._cell_formats[data.field_key];
                            } else {
                                rowData._cell_formats[data.field_key] = data.style;
                            }
                            row.update(rowData);
                        }
                    } else if (data.type === 'row') {
                        // Update row format in local row data
                        const row = this.table.getRows().find(r => r.getData().ID === data.post_id);
                        if (row) {
                            const rowData = row.getData();
                            if (payload.clear) {
                                delete rowData._row_format;
                            } else {
                                rowData._row_format = data.style;
                            }
                            row.update(rowData);
                        }
                    }
                    
                    // Redraw table to apply changes
                    this.table.redraw(true);
                    
                    // Clear selection after applying format
                    this.clearSelection();
                } else {
                    this.showError(data.message || 'Failed to save format');
                }
            })
            .catch(error => {
                console.error('Format save error:', error);
                this.showError('Failed to save format');
            });
        }
        
        /**
         * Reload data from REST API (refreshes from ajax source)
         */
        loadData() {
            // Use setData with ajax URL to refresh - Tabulator will use the configured ajax settings
            this.table.setData();
        }

        /**
         * Build column definitions
         */
        buildColumns() {
            const columns = [];
            let rowNum = 0;
            
            // Check if any data columns are frozen
            const hasAnyFrozen = this.config.columns.some(col => col.frozen);
            
            // Add row number column if user can edit
            if (this.config.canEdit) {
                const rowNumCol = {
                    title: '#',
                    width: 50,
                    hozAlign: 'center',
                    headerSort: false,
                    resizable: false,
                    cssClass: 'pds-row-number-cell',
                    formatter: (cell) => {
                        const row = cell.getRow();
                        const position = row.getPosition(true);
                        return '<div class="pds-row-number" title="Click to select row">' + position + '</div>';
                    },
                    cellClick: (e, cell) => {
                        e.stopPropagation();
                        this.selectRow(cell.getRow());
                    },
                };
                
                // Freeze row number column if any other columns are frozen
                if (hasAnyFrozen) {
                    rowNumCol.frozen = true;
                }
                
                columns.push(rowNumCol);
            }
            
            // Add data columns
            // Store column config for reference (our custom props here, NOT in Tabulator column def)
            this.columnConfigMap = {};
            
            this.config.columns.forEach(col => {
                const fieldName = col.field;
                const fieldType = col.fieldType || 'text';
                const colId = col.colId || fieldName;

                // Determine editor type based on field type
                let editorType = null;
                if (col.editable && this.config.canEdit) {
                    switch (fieldType) {
                        case 'wysiwyg':
                        case 'textarea':
                            editorType = 'modal';
                            break;
                        case 'date':
                            editorType = 'date';
                            break;
                        case 'datetime':
                            editorType = 'datetime';
                            break;
                        case 'select':
                            editorType = 'list';
                            break;
                        case 'user':
                            // User field uses list editor (single or multi-select)
                            editorType = col.multiple ? 'userMulti' : 'list';
                            break;
                        case 'boolean':
                            editorType = 'tickCross';
                            break;
                        case 'number':
                            editorType = 'number';
                            break;
                        default:
                            editorType = 'input';
                            break;
                    }
                }

                // Store our custom config for reference
                // Key by colId which is unique per column
                this.columnConfigMap[colId] = {
                    fieldType: fieldType,
                    fieldName: fieldName,
                    source: col.source || 'auto',
                    maxChars: col.maxChars || 0,
                    sortOrder: col.sortOrder || '',
                    fieldOptions: col.fieldOptions ? JSON.parse(JSON.stringify(col.fieldOptions)) : null,
                    colId: colId,
                    editable: col.editable && this.config.canEdit,
                    editorType: editorType,
                    multiple: col.multiple || false,
                };
                
                // Build Tabulator column definition (only Tabulator-recognized options)
                // NOTE: We do NOT set editor here - we handle editing manually via double-click
                const colDef = {
                    field: col.field,
                    title: col.title,
                    sorter: col.sorter || 'string',
                    headerFilter: col.headerFilter || false,
                    hozAlign: col.hozAlign || 'left',
                    width: col.width || undefined,
                    resizable: true,
                    formatter: (cell) => this.formatCell(cell, col),
                };
                
                // Custom sorter for select fields with defined sort order
                if (col.sortOrder && fieldType === 'select') {
                    const sortValues = col.sortOrder.split(',').map(v => v.trim());
                    colDef.sorter = (a, b, aRow, bRow, column, dir, sorterParams) => {
                        const aPos = sortValues.indexOf(a);
                        const bPos = sortValues.indexOf(b);
                        const aIndex = aPos === -1 ? Infinity : aPos;
                        const bIndex = bPos === -1 ? Infinity : bPos;
                        
                        if (aIndex === Infinity && bIndex === Infinity) {
                            return String(a || '').localeCompare(String(b || ''));
                        }
                        
                        return aIndex - bIndex;
                    };
                }
                
                // Frozen column
                if (col.frozen) {
                    colDef.frozen = true;
                }

                // Header filter for select fields
                if (col.headerFilter === 'list' && col.fieldOptions) {
                    colDef.headerFilterParams = {
                        values: Object.assign({}, this.formatSelectOptions(col.fieldOptions, true))
                    };
                }

                columns.push(colDef);
            });
            
            return columns;
        }

        /**
         * Format select options for Tabulator
         */
        formatSelectOptions(options, includeEmpty = false) {
            const formatted = {};
            
            if (includeEmpty) {
                formatted[''] = '(All)';
            }
            
            if (Array.isArray(options)) {
                options.forEach(opt => {
                    formatted[opt] = opt;
                });
            } else {
                for (const key in options) {
                    formatted[key] = options[key];
                }
            }
            
            return formatted;
        }

        /**
         * Create custom date editor function for Tabulator
         * Following Tabulator's custom editor pattern from docs
         */
        createDateEditor() {
            const self = this;
            
            return function(cell, onRendered, success, cancel, editorParams) {
                // Get current value
                const cellValue = cell.getValue() || '';
                
                // Create input element
                const input = document.createElement('input');
                input.setAttribute('type', 'date');
                input.style.padding = '4px';
                input.style.width = '100%';
                input.style.boxSizing = 'border-box';
                
                // Convert stored value to YYYY-MM-DD for the date input
                if (cellValue) {
                    // If already in YYYY-MM-DD format, use directly
                    if (/^\d{4}-\d{2}-\d{2}$/.test(cellValue)) {
                        input.value = cellValue;
                    } else {
                        // Try to parse and convert
                        const date = new Date(cellValue);
                        if (!isNaN(date.getTime())) {
                            // Format as YYYY-MM-DD
                            const year = date.getFullYear();
                            const month = String(date.getMonth() + 1).padStart(2, '0');
                            const day = String(date.getDate()).padStart(2, '0');
                            input.value = `${year}-${month}-${day}`;
                        }
                    }
                }
                
                // Store original value for comparison
                const originalInputValue = input.value;
                
                // Focus and style when rendered
                onRendered(function() {
                    input.focus();
                    input.style.height = '100%';
                    
                    // Try to open the date picker
                    try {
                        if (input.showPicker) {
                            input.showPicker();
                        }
                    } catch (e) {
                        // showPicker may fail if not triggered by user gesture
                    }
                });
                
                // Handle value change
                function onChange() {
                    if (input.value !== originalInputValue) {
                        // Return value in YYYY-MM-DD format (what ACF date_picker expects)
                        success(input.value);
                    } else {
                        cancel();
                    }
                }
                
                // Submit on blur or change
                input.addEventListener('blur', onChange);
                input.addEventListener('change', onChange);
                
                // Handle keyboard
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onChange();
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        cancel();
                    }
                });
                
                return input;
            };
        }

        /**
         * Create custom datetime editor function for Tabulator
         */
        createDatetimeEditor() {
            const self = this;
            
            return function(cell, onRendered, success, cancel, editorParams) {
                // Get current value
                const cellValue = cell.getValue() || '';
                
                // Create input element
                const input = document.createElement('input');
                input.setAttribute('type', 'datetime-local');
                input.style.padding = '4px';
                input.style.width = '100%';
                input.style.boxSizing = 'border-box';
                
                // Convert stored value to datetime-local format (YYYY-MM-DDTHH:MM)
                if (cellValue) {
                    const date = new Date(cellValue);
                    if (!isNaN(date.getTime())) {
                        // Format as YYYY-MM-DDTHH:MM
                        input.value = date.toISOString().slice(0, 16);
                    }
                }
                
                // Store original value for comparison
                const originalInputValue = input.value;
                
                // Focus when rendered
                onRendered(function() {
                    input.focus();
                    input.style.height = '100%';
                });
                
                // Handle value change
                function onChange() {
                    if (input.value !== originalInputValue) {
                        // Return ISO format
                        success(input.value);
                    } else {
                        cancel();
                    }
                }
                
                // Submit on blur or change
                input.addEventListener('blur', onChange);
                input.addEventListener('change', onChange);
                
                // Handle keyboard
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onChange();
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        cancel();
                    }
                });
                
                return input;
            };
        }

        /**
         * Format cell value with styling
         */
        formatCell(cell, colConfig) {
            let value = cell.getValue();
            const colId = colConfig.colId;
            const fieldKey = colConfig.field;
            const fieldType = colConfig.fieldType;
            const rowData = cell.getRow().getData();
            const cellEl = cell.getElement();

            // Store colId on cell element for later lookup (e.g., in handleCellDblClick)
            cellEl.dataset.colId = colId;

            // Get column defaults
            const defaults = this.config.columnDefaults[colId] || {};
            
            // Format value based on type
            value = this.formatValue(value, fieldType, defaults, colConfig);
            
            // Reset cell styles first
            cellEl.style.backgroundColor = '';
            cellEl.style.color = '';
            cellEl.style.fontWeight = '';
            
            // Apply default styling to cell element
            if (defaults.background) {
                cellEl.style.backgroundColor = defaults.background;
            }
            if (defaults.color) {
                cellEl.style.color = defaults.color;
            }
            if (defaults.font_weight === 'bold') {
                cellEl.style.fontWeight = 'bold';
            }
            
            // Apply conditional formatting (layered)
            const rules = this.config.conditionalRules || [];
            const cellRules = rules.filter(r => r.scope === 'cell' && r.target_column === colId);
            
            cellRules.forEach(rule => {
                if (this.evaluateCondition(rule.condition, cell.getValue(), rowData)) {
                    if (rule.style.background) {
                        cellEl.style.backgroundColor = rule.style.background;
                    }
                    if (rule.style.color) {
                        cellEl.style.color = rule.style.color;
                    }
                    if (rule.style.font_weight === 'bold') {
                        cellEl.style.fontWeight = 'bold';
                    }
                }
            });
            
            // Apply custom column formats (from toolbar)
            const customColFormat = this.customColumnFormats[fieldKey];
            if (customColFormat) {
                if (customColFormat.background) {
                    cellEl.style.backgroundColor = customColFormat.background;
                }
                if (customColFormat.color) {
                    cellEl.style.color = customColFormat.color;
                }
                if (customColFormat.font_weight === 'bold') {
                    cellEl.style.fontWeight = 'bold';
                }
            }
            
            // Apply custom cell formats (from row data)
            const cellFormats = rowData._cell_formats || {};
            const customCellFormat = cellFormats[fieldKey];
            if (customCellFormat) {
                if (customCellFormat.background) {
                    cellEl.style.backgroundColor = customCellFormat.background;
                }
                if (customCellFormat.color) {
                    cellEl.style.color = customCellFormat.color;
                }
                if (customCellFormat.font_weight === 'bold') {
                    cellEl.style.fontWeight = 'bold';
                }
            }
            
            // Return just the formatted value (styles are on cell element)
            return value;
        }

        /**
         * Format value based on type
         */
        formatValue(value, type, defaults, colConfig) {
            if (value === null || value === undefined || value === '') {
                return '';
            }
            
            switch (type) {
                case 'date':
                    return this.formatDate(value, defaults.date_format || 'm/d/Y');
                    
                case 'datetime':
                    return this.formatDate(value, defaults.date_format || 'm/d/Y H:i');
                    
                case 'number':
                    return this.formatNumber(value, defaults);
                    
                case 'boolean':
                    return value === true || value === 1 || value === '1' 
                        ? '<span class="pds-bool-true">✓</span>' 
                        : '<span class="pds-bool-false">✗</span>';
                    
                case 'select':
                    // Map value to label if options available
                    if (colConfig.fieldOptions && colConfig.fieldOptions[value]) {
                        return colConfig.fieldOptions[value];
                    }
                    return value;

                case 'user':
                    // User field - display user name(s) instead of ID(s)
                    if (!value) return '';

                    // Handle array of user IDs (multi-select)
                    if (Array.isArray(value)) {
                        const names = value.map(id => {
                            if (colConfig.fieldOptions && colConfig.fieldOptions[id]) {
                                return colConfig.fieldOptions[id];
                            }
                            return id;
                        });
                        return names.join(', ');
                    }

                    // Single user ID
                    if (colConfig.fieldOptions && colConfig.fieldOptions[value]) {
                        return colConfig.fieldOptions[value];
                    }
                    return value;
                
                case 'wysiwyg':
                    // Hybrid approach: render HTML if fits, strip if truncated
                    const wysiwygValue = String(value);
                    const wysiwygMaxChars = colConfig.maxChars || 0;
                    const wysiwygPlainText = this.stripHtml(wysiwygValue);
                    
                    if (wysiwygMaxChars > 0 && wysiwygPlainText.length > wysiwygMaxChars) {
                        // Truncated - show plain text preview
                        const truncated = wysiwygPlainText.substring(0, wysiwygMaxChars).trim();
                        return '<span class="pds-truncated pds-wysiwyg-cell" data-full-content="1">' + 
                               this.escapeHtml(truncated) + 
                               '<span class="pds-truncated-indicator">... [more]</span></span>';
                    }
                    // Not truncated - render HTML with constraints
                    return '<span class="pds-wysiwyg-cell pds-wysiwyg-rendered">' + wysiwygValue + '</span>';
                
                case 'textarea':
                    // Strip HTML and handle truncation
                    const plainText = this.stripHtml(String(value));
                    const maxChars = colConfig.maxChars || 0;
                    
                    if (maxChars > 0 && plainText.length > maxChars) {
                        const truncated = plainText.substring(0, maxChars).trim();
                        return '<span class="pds-truncated" data-full-content="1">' + 
                               this.escapeHtml(truncated) + 
                               '<span class="pds-truncated-indicator">... [more]</span></span>';
                    }
                    return this.escapeHtml(plainText);
                    
                default:
                    // Check if this looks like HTML content (for WYSIWYG fields saved as 'text' type)
                    const textValue = String(value);
                    const hasHtmlTags = /<[^>]+>/.test(textValue);
                    
                    if (hasHtmlTags) {
                        // Treat as WYSIWYG - render HTML with constraints
                        const textMaxChars = colConfig.maxChars || 0;
                        const strippedText = this.stripHtml(textValue);
                        
                        if (textMaxChars > 0 && strippedText.length > textMaxChars) {
                            const truncated = strippedText.substring(0, textMaxChars).trim();
                            return '<span class="pds-truncated pds-wysiwyg-cell" data-full-content="1">' + 
                                   this.escapeHtml(truncated) + 
                                   '<span class="pds-truncated-indicator">... [more]</span></span>';
                        }
                        return '<span class="pds-wysiwyg-cell pds-wysiwyg-rendered">' + textValue + '</span>';
                    }
                    
                    // Regular text - handle truncation
                    const defaultMaxChars = colConfig.maxChars || 0;
                    
                    if (defaultMaxChars > 0 && textValue.length > defaultMaxChars) {
                        const truncatedText = textValue.substring(0, defaultMaxChars).trim();
                        return '<span class="pds-truncated" data-full-content="1">' + 
                               this.escapeHtml(truncatedText) + 
                               '<span class="pds-truncated-indicator">... [more]</span></span>';
                    }
                    return this.escapeHtml(textValue);
            }
        }
        
        /**
         * Strip HTML tags from string
         */
        stripHtml(html) {
            if (!html) return '';
            const doc = new DOMParser().parseFromString(html, 'text/html');
            return doc.body.textContent || '';
        }

        /**
         * Format date value
         */
        formatDate(value, format) {
            if (!value) return '';
            
            let date;
            
            // Handle YYYY-MM-DD format without timezone shift
            if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
                // Parse as local date to avoid timezone issues
                const parts = value.split('-');
                date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            } else if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) {
                // ISO datetime - parse normally
                date = new Date(value);
            } else {
                date = new Date(value);
            }
            
            if (isNaN(date.getTime())) return value;
            
            const pad = n => n < 10 ? '0' + n : n;
            
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                           'July', 'August', 'September', 'October', 'November', 'December'];
            const monthsShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            
            const replacements = {
                'Y': date.getFullYear(),
                'y': String(date.getFullYear()).slice(-2),
                'm': pad(date.getMonth() + 1),
                'n': date.getMonth() + 1,
                'd': pad(date.getDate()),
                'j': date.getDate(),
                'F': months[date.getMonth()],
                'M': monthsShort[date.getMonth()],
                'H': pad(date.getHours()),
                'i': pad(date.getMinutes()),
                's': pad(date.getSeconds())
            };
            
            let result = format;
            for (const key in replacements) {
                result = result.replace(new RegExp(key, 'g'), replacements[key]);
            }
            
            return result;
        }

        /**
         * Format number value
         */
        formatNumber(value, defaults) {
            const num = parseFloat(value);
            if (isNaN(num)) return value;
            
            const decimals = parseInt(defaults.number_decimals) || 0;
            const useThousands = defaults.number_thousands !== false;
            const prefix = defaults.number_prefix || '';
            const suffix = defaults.number_suffix || '';
            
            let formatted = num.toFixed(decimals);
            
            if (useThousands) {
                const parts = formatted.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                formatted = parts.join('.');
            }
            
            return prefix + formatted + suffix;
        }

        /**
         * Apply row formatting
         */
        applyRowFormatting(row) {
            const rowData = row.getData();
            const rules = this.config.conditionalRules || [];
            const rowRules = rules.filter(r => r.scope === 'row');
            
            const el = row.getElement();
            
            // Reset styles
            el.style.backgroundColor = '';
            el.style.color = '';
            el.style.fontWeight = '';
            
            // Apply matching conditional rules (layered)
            rowRules.forEach(rule => {
                const fieldValue = rowData[rule.condition.field];
                
                if (this.evaluateCondition(rule.condition, fieldValue, rowData)) {
                    if (rule.style.background) {
                        el.style.backgroundColor = rule.style.background;
                    }
                    if (rule.style.color) {
                        el.style.color = rule.style.color;
                    }
                    if (rule.style.font_weight === 'bold') {
                        el.style.fontWeight = 'bold';
                    }
                }
            });
            
            // Apply custom row format (from toolbar)
            const customRowFormat = rowData._row_format;
            if (customRowFormat) {
                if (customRowFormat.background) {
                    el.style.backgroundColor = customRowFormat.background;
                }
                if (customRowFormat.color) {
                    el.style.color = customRowFormat.color;
                }
                if (customRowFormat.font_weight === 'bold') {
                    el.style.fontWeight = 'bold';
                }
            }
        }

        /**
         * Evaluate condition
         */
        evaluateCondition(condition, value, rowData) {
            let compareValue = condition.value;
            
            // Handle special tokens
            if (compareValue === '{{TODAY}}') {
                compareValue = new Date().toISOString().split('T')[0];
            } else if (compareValue === '{{CURRENT_USER}}') {
                // Would need to pass user ID from PHP
                return false;
            }
            
            // Handle empty value for conditions
            const isEmpty = value === null || value === undefined || value === '';
            
            switch (condition.operator) {
                case 'equals':
                    return String(value) === String(compareValue);
                    
                case 'not_equals':
                    return String(value) !== String(compareValue);
                    
                case 'contains':
                    return String(value).toLowerCase().includes(String(compareValue).toLowerCase());
                    
                case 'not_contains':
                    return !String(value).toLowerCase().includes(String(compareValue).toLowerCase());
                    
                case 'greater_than':
                    return parseFloat(value) > parseFloat(compareValue);
                    
                case 'less_than':
                    return parseFloat(value) < parseFloat(compareValue);
                    
                case 'is_empty':
                    return isEmpty;
                    
                case 'is_not_empty':
                    return !isEmpty;
                    
                case 'is_true':
                    return value === true || value === 1 || value === '1';
                    
                case 'is_false':
                    return value === false || value === 0 || value === '0' || isEmpty;
                    
                default:
                    return false;
            }
        }

        /**
         * Handle cell edit (called by Tabulator's native cellEdited event)
         */
        handleCellEdit(cell) {
            const postId = cell.getRow().getData().ID;
            const field = cell.getColumn().getField();
            const newValue = cell.getValue();
            const oldValue = cell.getOldValue();
            
            // Skip if value hasn't actually changed
            if (newValue === oldValue) {
                return;
            }
            
            const settings = this.config.settings || {};
            const saveMode = settings.save_mode || 'immediate';
            
            if (saveMode === 'batch') {
                // Queue the change for batch save
                this.queueChange(postId, field, oldValue, newValue, cell);
            } else {
                // Immediate save (original behavior)
                this.saveCell(postId, field, newValue, cell);
            }
        }
        
        /**
         * Queue a change for batch save
         */
        queueChange(postId, fieldKey, oldValue, newValue, cell) {
            const changeKey = `${postId}_${fieldKey}`;
            
            // Check if we already have a pending change for this cell
            const existing = this.pendingChanges[changeKey];
            
            if (existing) {
                // Update the new value, keep original oldValue
                existing.newValue = newValue;
                
                // If we've reverted to original value, remove from pending
                if (existing.oldValue === newValue) {
                    delete this.pendingChanges[changeKey];
                    cell.getElement().classList.remove('pds-cell-modified');
                }
            } else {
                // New change
                this.pendingChanges[changeKey] = {
                    postId,
                    fieldKey,
                    oldValue,
                    newValue,
                    cell,
                    timestamp: Date.now()
                };
                
                // Add to history for future undo/redo
                this.changeHistory.push({
                    postId,
                    fieldKey,
                    oldValue,
                    newValue,
                    timestamp: Date.now()
                });
            }
            
            // Mark cell as modified
            if (this.pendingChanges[changeKey]) {
                cell.getElement().classList.add('pds-cell-modified');
            }
            
            // Update UI
            this.updatePendingChangesUI();
            
            // Reset auto-save timer
            this.resetAutoSaveTimer();
        }
        
        /**
         * Save a single cell immediately
         */
        saveCell(postId, fieldKey, value, cell) {
            // Get colId from cell's data attribute for config lookup
            const colId = cell.getElement().dataset.colId;
            const colConfig = this.columnConfigMap?.[colId] || {};
            const source = colConfig.source || 'auto';
            
            cell.getElement().classList.add('pds-saving');
            
            return fetch(pdsPostTables.restUrl + '/tables/' + this.tableId + '/data', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': pdsPostTables.nonce
                },
                body: JSON.stringify({
                    post_id: postId,
                    field_key: fieldKey,
                    value: value,
                    source: source
                })
            })
            .then(response => response.json())
            .then(data => {
                cell.getElement().classList.remove('pds-saving');
                
                if (!data.success) {
                    cell.restoreOldValue();
                    this.showError(data.message || 'Failed to save');
                    return false;
                } else {
                    cell.getElement().classList.add('pds-saved');
                    setTimeout(() => {
                        cell.getElement().classList.remove('pds-saved');
                    }, 1000);
                    return true;
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                cell.getElement().classList.remove('pds-saving');
                cell.restoreOldValue();
                this.showError('Failed to save: ' + error.message);
                return false;
            });
        }
        
        /**
         * Save all pending changes
         */
        async saveAllChanges() {
            const changes = Object.values(this.pendingChanges);
            
            if (changes.length === 0) {
                return;
            }
            
            if (this.isSaving) {
                return;
            }
            
            this.isSaving = true;
            this.updateSaveButtonState();
            
            let successCount = 0;
            let errorCount = 0;
            
            // Save changes sequentially to avoid overwhelming the server
            for (const change of changes) {
                // Get colId from cell's data attribute for config lookup
                let colId = null;
                if (change.cell) {
                    colId = change.cell.getElement().dataset.colId;
                }
                const colConfig = this.columnConfigMap?.[colId] || {};
                const source = colConfig.source || 'auto';
                
                try {
                    const response = await fetch(pdsPostTables.restUrl + '/tables/' + this.tableId + '/data', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': pdsPostTables.nonce
                        },
                        body: JSON.stringify({
                            post_id: change.postId,
                            field_key: change.fieldKey,
                            value: change.newValue,
                            source: source
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        successCount++;
                        // Remove from pending and update UI
                        const changeKey = `${change.postId}_${change.fieldKey}`;
                        delete this.pendingChanges[changeKey];
                        
                        if (change.cell) {
                            change.cell.getElement().classList.remove('pds-cell-modified');
                            change.cell.getElement().classList.add('pds-saved');
                            setTimeout(() => {
                                change.cell.getElement().classList.remove('pds-saved');
                            }, 1000);
                        }
                    } else {
                        errorCount++;
                        console.error('Failed to save:', change, data.message);
                    }
                } catch (error) {
                    errorCount++;
                    console.error('Save error:', error);
                }
            }
            
            this.isSaving = false;
            this.updatePendingChangesUI();
            this.updateSaveButtonState();
            
            // Show summary
            if (errorCount > 0) {
                this.showError(`Saved ${successCount} changes, ${errorCount} failed`);
            }
        }
        
        /**
         * Discard all pending changes
         */
        discardAllChanges() {
            if (Object.keys(this.pendingChanges).length === 0) {
                return;
            }
            
            if (!confirm('Discard all unsaved changes? This cannot be undone.')) {
                return;
            }
            
            // Revert each cell to its old value
            for (const change of Object.values(this.pendingChanges)) {
                if (change.cell) {
                    // Update the cell value without triggering edit event
                    change.cell.getRow().update({ [change.fieldKey]: change.oldValue });
                    change.cell.getElement().classList.remove('pds-cell-modified');
                }
            }
            
            // Clear pending changes
            this.pendingChanges = {};
            this.updatePendingChangesUI();
            this.clearAutoSaveTimer();
        }
        
        /**
         * Update the pending changes indicator in toolbar (smart save button)
         */
        updatePendingChangesUI() {
            const count = Object.keys(this.pendingChanges).length;
            const saveBtn = this.wrapper.querySelector('.pds-save-btn');
            const saveIcon = this.wrapper.querySelector('.pds-save-icon');
            const saveText = this.wrapper.querySelector('.pds-save-text');
            const saveCount = this.wrapper.querySelector('.pds-save-count');

            if (!saveBtn) return;

            // Remove all state classes
            saveBtn.classList.remove('pds-save-btn-saved', 'pds-save-btn-pending', 'pds-save-btn-saving', 'pds-save-btn-error');

            if (count > 0) {
                // Has changes - show prominent save button
                saveBtn.classList.add('pds-save-btn-pending');
                saveBtn.disabled = false;

                if (saveIcon) {
                    saveIcon.innerHTML = '<span class="dashicons dashicons-cloud-upload"></span>';
                }
                if (saveText) {
                    saveText.textContent = 'Save';
                }
                if (saveCount) {
                    saveCount.textContent = count;
                }
            } else {
                // No changes - show saved state
                saveBtn.classList.add('pds-save-btn-saved');
                saveBtn.disabled = true;

                if (saveIcon) {
                    saveIcon.innerHTML = '<span class="dashicons dashicons-yes-alt"></span>';
                }
                if (saveText) {
                    saveText.textContent = 'Saved';
                }
                if (saveCount) {
                    saveCount.textContent = '';
                }
            }
        }

        /**
         * Update save button state (during save)
         */
        updateSaveButtonState() {
            const saveBtn = this.wrapper.querySelector('.pds-save-btn');
            const saveIcon = this.wrapper.querySelector('.pds-save-icon');
            const saveText = this.wrapper.querySelector('.pds-save-text');
            const saveCount = this.wrapper.querySelector('.pds-save-count');

            if (!saveBtn) return;

            // Remove all state classes
            saveBtn.classList.remove('pds-save-btn-saved', 'pds-save-btn-pending', 'pds-save-btn-saving', 'pds-save-btn-error');

            if (this.isSaving) {
                // Saving state
                saveBtn.classList.add('pds-save-btn-saving');
                saveBtn.disabled = true;

                if (saveIcon) {
                    saveIcon.innerHTML = '<span class="dashicons dashicons-update"></span>';
                }
                if (saveText) {
                    saveText.textContent = 'Saving...';
                }
                if (saveCount) {
                    saveCount.textContent = '';
                }
            } else {
                // Not saving - update based on pending changes
                this.updatePendingChangesUI();
            }
        }
        
        /**
         * Reset the auto-save timer
         */
        resetAutoSaveTimer() {
            this.clearAutoSaveTimer();
            
            const settings = this.config.settings || {};
            const interval = settings.autosave_interval || 0;
            
            if (interval > 0 && Object.keys(this.pendingChanges).length > 0) {
                this.autoSaveTimer = setTimeout(() => {
                    console.log('PDS Tables: Auto-saving...');
                    this.saveAllChanges();
                }, interval * 60 * 1000); // Convert minutes to ms
            }
        }
        
        /**
         * Clear the auto-save timer
         */
        clearAutoSaveTimer() {
            if (this.autoSaveTimer) {
                clearTimeout(this.autoSaveTimer);
                this.autoSaveTimer = null;
            }
        }
        
        /**
         * Check if there are unsaved changes
         */
        hasUnsavedChanges() {
            return Object.keys(this.pendingChanges).length > 0;
        }
        
        /**
         * Initialize batch save UI and handlers
         */
        initBatchSave() {
            const settings = this.config.settings || {};

            if (settings.save_mode !== 'batch' || !this.config.canEdit) {
                return;
            }

            // Set up beforeunload handler
            window.addEventListener('beforeunload', (e) => {
                if (this.hasUnsavedChanges()) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                    return e.returnValue;
                }
            });

            // Set up main save button click (saves immediately)
            const saveBtn = this.wrapper.querySelector('.pds-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', (e) => {
                    // Only save if there are pending changes
                    if (Object.keys(this.pendingChanges).length > 0) {
                        this.saveAllChanges();
                    }
                });
            }

            // Set up "Save all" dropdown item
            const saveAllBtn = this.wrapper.querySelector('.pds-save-all-btn');
            if (saveAllBtn) {
                saveAllBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.saveAllChanges();
                });
            }

            // Set up "Discard all" dropdown item
            const discardAllBtn = this.wrapper.querySelector('.pds-discard-all-btn');
            if (discardAllBtn) {
                discardAllBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.discardAllChanges();
                });
            }

            // Initialize UI state
            this.updatePendingChangesUI();
        }

        /**
         * Initialize sticky scrollbar for horizontal scrolling
         * Creates a fixed scrollbar at the bottom of the viewport
         */
        initStickyScrollbar() {
            const settings = this.config.settings || {};

            // Check if sticky scrollbar is enabled
            if (settings.scrollbar_position !== 'sticky') {
                return;
            }

            // Get the table holder element (where horizontal scroll happens)
            const tableHolder = this.container.querySelector('.tabulator-tableholder');
            if (!tableHolder) {
                return;
            }

            // Create sticky scrollbar element
            this.stickyScrollbar = document.createElement('div');
            this.stickyScrollbar.className = 'pds-sticky-scrollbar';
            this.stickyScrollbar.innerHTML = '<div class="pds-sticky-scrollbar-inner"></div>';
            document.body.appendChild(this.stickyScrollbar);

            // Add class to body
            document.body.classList.add('pds-has-sticky-scrollbar');

            // Add class to table container to hide its native scrollbar
            this.container.classList.add('pds-using-sticky-scrollbar');

            const scrollbarInner = this.stickyScrollbar.querySelector('.pds-sticky-scrollbar-inner');

            // Sync scrollbar width with table content width
            const updateScrollbarWidth = () => {
                const scrollWidth = tableHolder.scrollWidth;
                const clientWidth = tableHolder.clientWidth;

                scrollbarInner.style.width = scrollWidth + 'px';
                this.stickyScrollbar.style.width = clientWidth + 'px';
                this.stickyScrollbar.style.left = this.container.getBoundingClientRect().left + 'px';

                // Show/hide based on whether scrolling is needed
                if (scrollWidth > clientWidth) {
                    this.stickyScrollbar.classList.add('pds-scrollbar-visible');
                } else {
                    this.stickyScrollbar.classList.remove('pds-scrollbar-visible');
                }
            };

            // Check if table is in viewport
            const isTableInViewport = () => {
                const rect = this.container.getBoundingClientRect();
                const viewportHeight = window.innerHeight;

                // Table is in viewport if its top is above viewport bottom
                // and its bottom is below viewport top
                return rect.top < viewportHeight && rect.bottom > 0;
            };

            // Update visibility based on scroll position
            const updateVisibility = () => {
                if (isTableInViewport()) {
                    updateScrollbarWidth();
                } else {
                    this.stickyScrollbar.classList.remove('pds-scrollbar-visible');
                }
            };

            // Sync scroll positions
            let isSyncing = false;

            this.stickyScrollbar.addEventListener('scroll', () => {
                if (isSyncing) return;
                isSyncing = true;
                tableHolder.scrollLeft = this.stickyScrollbar.scrollLeft;
                isSyncing = false;
            });

            tableHolder.addEventListener('scroll', () => {
                if (isSyncing) return;
                isSyncing = true;
                this.stickyScrollbar.scrollLeft = tableHolder.scrollLeft;
                isSyncing = false;
            });

            // Initial setup
            updateScrollbarWidth();
            updateVisibility();

            // Update on resize
            window.addEventListener('resize', () => {
                updateScrollbarWidth();
                updateVisibility();
            });

            // Update on scroll
            window.addEventListener('scroll', updateVisibility);

            // Update when table redraws (e.g., columns change)
            this.table.on('renderComplete', updateScrollbarWidth);

            // Cleanup on destroy
            this.table.on('tableDestroyed', () => {
                if (this.stickyScrollbar && this.stickyScrollbar.parentNode) {
                    this.stickyScrollbar.parentNode.removeChild(this.stickyScrollbar);
                }
                document.body.classList.remove('pds-has-sticky-scrollbar');
            });
        }

        /**
         * Get AJAX params for data loading
         */
        getAjaxParams() {
            const params = {};
            
            // Pagination is handled by Tabulator
            
            // Sorting
            const sorters = this.table.getSorters();
            if (sorters.length > 0) {
                params.sort_field = sorters[0].field;
                params.sort_dir = sorters[0].dir;
            }
            
            // Filters
            const filters = this.table.getHeaderFilters();
            if (filters.length > 0) {
                params.filters = {};
                filters.forEach(f => {
                    params.filters[f.field] = f.value;
                });
            }
            
            return params;
        }

        /**
         * Update table info display
         */
        updateTableInfo() {
            const wrapper = this.container.closest('.pds-post-table-wrap');
            const infoEl = wrapper.querySelector('.pds-table-info');
            
            if (infoEl && this.table) {
                const settings = this.config.settings || {};
                const paginationEnabled = settings.pagination !== false;
                
                if (paginationEnabled) {
                    // Remote pagination - use totalRows from ajax response
                    const page = this.table.getPage() || 1;
                    const pageSize = this.table.getPageSize() || (settings.page_size || 25);
                    const total = this.totalRows || this.table.getDataCount('active');
                    
                    const start = ((page - 1) * pageSize) + 1;
                    const end = Math.min(page * pageSize, total);
                    
                    if (total > 0) {
                        infoEl.textContent = `Showing ${start} to ${end} of ${total} entries`;
                    } else {
                        infoEl.textContent = 'No entries found';
                    }
                } else {
                    // No pagination - show total count
                    const total = this.totalRows || this.table.getDataCount('active');
                    
                    if (total > 0) {
                        infoEl.textContent = `Showing all ${total} entries`;
                    } else {
                        infoEl.textContent = 'No entries found';
                    }
                }
            }
        }

        /**
         * Initialize export button
         */
        initExportButton() {
            const wrapper = this.container.closest('.pds-post-table-wrap');
            const exportBtn = wrapper.querySelector('.pds-export-csv');
            
            if (exportBtn) {
                exportBtn.addEventListener('click', () => {
                    this.table.download('csv', 'table-export.csv');
                });
            }
        }
        
        /**
         * Initialize column toggle dropdown
         */
        initColumnToggle() {
            const settings = this.config.settings || {};
            
            if (settings.allow_column_toggle === false) {
                return;
            }
            
            const wrapper = this.container.closest('.pds-post-table-wrap');
            const toggleContainer = wrapper.querySelector('.pds-column-toggle-container');
            
            if (!toggleContainer) {
                return;
            }
            
            const toggleBtn = toggleContainer.querySelector('.pds-column-toggle-btn');
            const dropdown = toggleContainer.querySelector('.pds-column-toggle-dropdown');
            
            if (!toggleBtn || !dropdown) {
                return;
            }
            
            // Build the column list
            this.buildColumnToggleList(dropdown);
            
            // Toggle dropdown on button click
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown.classList.toggle('pds-dropdown-open');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!toggleContainer.contains(e.target)) {
                    dropdown.classList.remove('pds-dropdown-open');
                }
            });
            
            // Reset button
            const resetBtn = dropdown.querySelector('.pds-column-reset-btn');
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    this.resetColumnVisibility();
                    this.buildColumnToggleList(dropdown);
                });
            }
        }
        
        /**
         * Build the column toggle checkbox list
         */
        buildColumnToggleList(dropdown) {
            const listContainer = dropdown.querySelector('.pds-column-toggle-list');
            if (!listContainer) return;
            
            listContainer.innerHTML = '';
            
            // Get all columns (skip row number column)
            const columns = this.table.getColumns().filter(col => {
                const field = col.getField();
                return field && field !== '#';
            });
            
            columns.forEach(col => {
                const field = col.getField();
                const title = col.getDefinition().title || field;
                const isVisible = col.isVisible();
                
                const label = document.createElement('label');
                label.className = 'pds-column-toggle-item';
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = isVisible;
                checkbox.dataset.field = field;
                
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        col.show();
                    } else {
                        col.hide();
                    }
                });
                
                const text = document.createTextNode(' ' + title);
                
                label.appendChild(checkbox);
                label.appendChild(text);
                listContainer.appendChild(label);
            });
        }
        
        /**
         * Reset all columns to visible
         */
        resetColumnVisibility() {
            const columns = this.table.getColumns();
            columns.forEach(col => {
                if (col.getField()) {
                    col.show();
                }
            });
            
            // Clear persisted column visibility
            const storageKey = 'tabulator-pds_table_' + this.tableId + '-columns';
            localStorage.removeItem(storageKey);
        }

        /**
         * Show error message
         */
        showError(message) {
            // Simple alert for now - could be replaced with toast notification
            alert(message);
        }

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /**
         * Open content modal for viewing/editing long text
         */
        openContentModal(cell, startInEditMode = false) {
            const colDef = cell.getColumn().getDefinition();
            const fieldKey = cell.getField();
            const value = cell.getValue() || '';
            const rowData = cell.getRow().getData();
            const postId = rowData.ID;
            const title = colDef.title || fieldKey;
            
            // Find the column config from our original config
            const colConfig = this.config.columns.find(c => c.field === fieldKey) || {};
            const fieldType = colConfig.fieldType || 'text';
            const source = colConfig.source || 'auto';
            const canEdit = this.config.canEdit && colConfig.editable;
            
            // Determine if this is WYSIWYG content (either by type or by detecting HTML)
            const hasHtmlContent = value && typeof value === 'string' && /<[^>]+>/.test(value);
            const isWysiwyg = fieldType === 'wysiwyg' || fieldType === 'textarea' || hasHtmlContent;
            
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'pds-content-modal-overlay';
            
            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            
            // Track state
            let isEditing = false;
            let currentValue = value;
            let isClosed = false;
            const self = this;
            
            // Close handler - guards against multiple calls
            const closeModal = () => {
                if (isClosed) return;
                isClosed = true;
                
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                document.body.style.overflow = '';
                document.removeEventListener('keydown', escHandler);
            };
            
            // Escape key handler
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                }
            };
            
            // Switch to edit mode function
            const switchToEditMode = () => {
                if (isEditing) return;
                isEditing = true;
                
                const bodyEl = overlay.querySelector('.pds-content-modal-body');
                const footerEl = overlay.querySelector('.pds-content-modal-footer');
                
                if (isWysiwyg) {
                    bodyEl.innerHTML = `<div class="pds-content-modal-wysiwyg" contenteditable="true">${currentValue}</div>`;
                    setTimeout(() => bodyEl.querySelector('.pds-content-modal-wysiwyg')?.focus(), 50);
                } else {
                    bodyEl.innerHTML = `<textarea class="pds-content-modal-editor">${self.escapeHtml(currentValue)}</textarea>`;
                    setTimeout(() => bodyEl.querySelector('.pds-content-modal-editor')?.focus(), 50);
                }
                
                bodyEl.classList.remove('pds-view-mode');
                
                // Update footer
                footerEl.innerHTML = `
                    <button type="button" class="pds-btn-secondary pds-modal-cancel-btn">Cancel</button>
                    <button type="button" class="pds-btn-primary pds-modal-save-btn">Save</button>
                `;
                
                // Cancel handler
                footerEl.querySelector('.pds-modal-cancel-btn').addEventListener('click', closeModal);
                
                // Save handler
                footerEl.querySelector('.pds-modal-save-btn').addEventListener('click', () => {
                    const editor = isWysiwyg 
                        ? bodyEl.querySelector('.pds-content-modal-wysiwyg')
                        : bodyEl.querySelector('.pds-content-modal-editor');
                    
                    const newValue = isWysiwyg ? editor.innerHTML : editor.value;
                    
                    if (newValue !== currentValue) {
                        self.saveModalContent(cell, postId, fieldKey, source, newValue, closeModal);
                    } else {
                        closeModal();
                    }
                });
            };
            
            // Build initial HTML based on whether we start in edit mode
            if (startInEditMode && canEdit) {
                overlay.innerHTML = `
                    <div class="pds-content-modal">
                        <div class="pds-content-modal-header">
                            <h3>${this.escapeHtml(title)}</h3>
                            <button type="button" class="pds-content-modal-close">&times;</button>
                        </div>
                        <div class="pds-content-modal-body">
                            ${isWysiwyg 
                                ? `<div class="pds-content-modal-wysiwyg" contenteditable="true">${currentValue}</div>`
                                : `<textarea class="pds-content-modal-editor">${this.escapeHtml(currentValue)}</textarea>`
                            }
                        </div>
                        <div class="pds-content-modal-footer">
                            <button type="button" class="pds-btn-secondary pds-modal-cancel-btn">Cancel</button>
                            <button type="button" class="pds-btn-primary pds-modal-save-btn">Save</button>
                        </div>
                    </div>
                `;
                isEditing = true;
                
                // Focus editor
                setTimeout(() => {
                    const editor = isWysiwyg 
                        ? overlay.querySelector('.pds-content-modal-wysiwyg')
                        : overlay.querySelector('.pds-content-modal-editor');
                    editor?.focus();
                }, 50);
                
                // Cancel handler
                overlay.querySelector('.pds-modal-cancel-btn').addEventListener('click', closeModal);
                
                // Save handler
                overlay.querySelector('.pds-modal-save-btn').addEventListener('click', () => {
                    const editor = isWysiwyg 
                        ? overlay.querySelector('.pds-content-modal-wysiwyg')
                        : overlay.querySelector('.pds-content-modal-editor');
                    
                    const newValue = isWysiwyg ? editor.innerHTML : editor.value;
                    
                    if (newValue !== currentValue) {
                        self.saveModalContent(cell, postId, fieldKey, source, newValue, closeModal);
                    } else {
                        closeModal();
                    }
                });
            } else {
                // Start in view mode - render HTML for WYSIWYG content
                overlay.innerHTML = `
                    <div class="pds-content-modal">
                        <div class="pds-content-modal-header">
                            <h3>${this.escapeHtml(title)}</h3>
                            <button type="button" class="pds-content-modal-close">&times;</button>
                        </div>
                        <div class="pds-content-modal-body pds-view-mode">
                            ${isWysiwyg ? value : '<p>' + this.escapeHtml(value).replace(/\n/g, '</p><p>') + '</p>'}
                        </div>
                        <div class="pds-content-modal-footer">
                            ${canEdit ? '<button type="button" class="pds-btn-edit">Edit</button>' : ''}
                            <button type="button" class="pds-btn-secondary pds-modal-close-btn">Close</button>
                        </div>
                    </div>
                `;
                
                overlay.querySelector('.pds-modal-close-btn').addEventListener('click', closeModal);
                
                // Edit button handler
                const editBtn = overlay.querySelector('.pds-btn-edit');
                if (editBtn) {
                    editBtn.addEventListener('click', switchToEditMode);
                }
            }
            
            // Common close handlers
            overlay.querySelector('.pds-content-modal-close').addEventListener('click', closeModal);
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal();
            });
            document.addEventListener('keydown', escHandler);
        }
        
        /**
         * Save content from modal
         */
        saveModalContent(cell, postId, fieldKey, source, newValue, closeCallback) {
            const tableRef = this.table;
            const settings = this.config.settings || {};
            const saveMode = settings.save_mode || 'immediate';
            const oldValue = cell.getValue();
            
            if (saveMode === 'batch') {
                // Queue the change for batch save
                this.queueChange(postId, fieldKey, oldValue, newValue, cell);
                
                // Update the table data locally
                const updateObj = { ID: postId };
                updateObj[fieldKey] = newValue;
                tableRef.updateData([updateObj]).then(() => {
                    // Re-mark the cell as modified after update
                    setTimeout(() => {
                        const updatedCell = tableRef.getRow(postId)?.getCell(fieldKey);
                        if (updatedCell) {
                            updatedCell.getElement().classList.add('pds-cell-modified');
                        }
                    }, 50);
                    closeCallback();
                });
            } else {
                // Immediate save (original behavior)
                fetch(pdsPostTables.restUrl + '/tables/' + this.tableId + '/data', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pdsPostTables.nonce
                    },
                    body: JSON.stringify({
                        post_id: postId,
                        field_key: fieldKey,
                        value: newValue,
                        source: source || 'auto'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update the table data
                        const updateObj = { ID: postId };
                        updateObj[fieldKey] = newValue;
                        tableRef.updateData([updateObj]).then(() => {
                            closeCallback();
                        });
                    } else {
                        alert(data.message || 'Failed to save');
                    }
                })
                .catch(error => {
                    console.error('Save error:', error);
                    alert('Failed to save');
                });
            }
        }

        /**
         * Initialize real-time sync via WordPress Heartbeat API
         */
        initRealtimeSync() {
            // Check if Heartbeat is available
            if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
                console.log('PDS Tables: Heartbeat API not available, real-time sync disabled');
                return;
            }

            // Track last sync time
            this.lastSyncTimestamp = Math.floor(Date.now() / 1000);

            // Track current edit for conflict detection
            this.currentEdit = null;

            // Active users viewing this table
            this.activeUsers = [];

            const self = this;

            // Send our table ID with each heartbeat tick
            jQuery(document).on('heartbeat-send', function(e, data) {
                data.pds_table_sync = {
                    table_id: self.tableId,
                    last_sync: self.lastSyncTimestamp
                };
            });

            // Receive updates from heartbeat
            jQuery(document).on('heartbeat-tick', function(e, data) {
                if (data.pds_table_sync && data.pds_table_sync.table_id == self.tableId) {
                    self.handleRealtimeUpdate(data.pds_table_sync);
                }
            });

            // Update sync indicator on each tick
            jQuery(document).on('heartbeat-tick', function() {
                self.updateSyncIndicator('synced');
            });

            // Show error state if heartbeat fails
            jQuery(document).on('heartbeat-error', function() {
                self.updateSyncIndicator('error');
            });

            console.log('PDS Tables: Real-time sync initialized for table', this.tableId);
        }

        /**
         * Handle real-time update from heartbeat
         */
        handleRealtimeUpdate(syncData) {
            const { changes, active_users, timestamp } = syncData;

            // Update active users
            if (active_users) {
                this.activeUsers = active_users;
                this.updateActiveUsersIndicator();
            }

            // Update timestamp
            if (timestamp) {
                this.lastSyncTimestamp = timestamp;
            }

            // No changes to process
            if (!changes || !changes.rows || changes.rows.length === 0) {
                return;
            }

            // Check for conflicts with current edit
            if (this.currentEdit) {
                const conflictChange = changes.details.find(d =>
                    d.post_id == this.currentEdit.postId &&
                    d.field_key === this.currentEdit.fieldKey
                );

                if (conflictChange) {
                    this.handleEditConflict(conflictChange, changes.rows);
                    return;
                }
            }

            // Update rows in Tabulator (won't disturb scroll/sort)
            this.table.updateData(changes.rows);

            // Flash updated rows briefly
            changes.rows.forEach(row => {
                const tabulatorRow = this.table.getRow(row.ID);
                if (tabulatorRow) {
                    const el = tabulatorRow.getElement();
                    el.classList.add('pds-row-updated-remote');
                    setTimeout(() => {
                        el.classList.remove('pds-row-updated-remote');
                    }, 2000);
                }
            });

            // Show notification
            this.showSyncNotification(changes);
        }

        /**
         * Handle conflict when someone edits the same cell we're editing
         */
        handleEditConflict(conflictChange, rows) {
            const newRow = rows.find(r => r.ID == conflictChange.post_id);
            const newValue = newRow ? newRow[conflictChange.field_key] : 'unknown';

            const message = `${conflictChange.user_name} just edited this field.\n\n` +
                `Their value: "${newValue}"\n` +
                `Your value: "${this.currentEdit.currentValue}"\n\n` +
                `Do you want to keep your edit (OK) or accept their change (Cancel)?`;

            if (!confirm(message)) {
                // Accept their change - cancel current edit and update
                this.cancelCurrentEdit();
                this.table.updateData(rows);
            }
            // Otherwise keep editing
        }

        /**
         * Cancel current edit (close any open editors)
         */
        cancelCurrentEdit() {
            this.currentEdit = null;

            // Close any open inline editors
            const openEditor = document.querySelector('.pds-inline-editor');
            if (openEditor) {
                openEditor.remove();
            }

            // Close any open multi-select editors
            const multiEditor = document.querySelector('.pds-user-multi-editor');
            if (multiEditor) {
                multiEditor.remove();
            }
        }

        /**
         * Track when user starts editing a cell
         */
        trackEditStart(cell) {
            this.currentEdit = {
                postId: cell.getRow().getData().ID,
                fieldKey: cell.getField(),
                originalValue: cell.getValue(),
                currentValue: cell.getValue(),
                startTime: Date.now()
            };
        }

        /**
         * Update current edit value (as user types)
         */
        updateCurrentEditValue(value) {
            if (this.currentEdit) {
                this.currentEdit.currentValue = value;
            }
        }

        /**
         * Clear current edit tracking
         */
        clearCurrentEdit() {
            this.currentEdit = null;
        }

        /**
         * Show sync notification (toast)
         */
        showSyncNotification(changes) {
            const editorNames = [...new Set(changes.details.map(d => d.user_name))].join(', ');
            const rowCount = changes.rows.length;
            const message = `${rowCount} row${rowCount > 1 ? 's' : ''} updated by ${editorNames}`;

            this.showToast(message, 'info');
        }

        /**
         * Show toast notification
         */
        showToast(message, type = 'info') {
            // Remove existing toast
            const existing = this.wrapper.querySelector('.pds-toast');
            if (existing) {
                existing.remove();
            }

            const toast = document.createElement('div');
            toast.className = `pds-toast pds-toast-${type}`;
            toast.textContent = message;

            this.wrapper.appendChild(toast);

            // Animate in
            setTimeout(() => toast.classList.add('pds-toast-visible'), 10);

            // Remove after delay
            setTimeout(() => {
                toast.classList.remove('pds-toast-visible');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        /**
         * Update sync status indicator
         */
        updateSyncIndicator(status) {
            const indicator = this.wrapper.querySelector('.pds-sync-status');
            if (!indicator) return;

            const dot = indicator.querySelector('.pds-sync-dot');
            const text = indicator.querySelector('.pds-sync-text');

            if (status === 'synced') {
                dot.className = 'pds-sync-dot pds-sync-connected';
                text.textContent = 'Live';
                indicator.title = 'Real-time sync active';
            } else if (status === 'error') {
                dot.className = 'pds-sync-dot pds-sync-disconnected';
                text.textContent = 'Offline';
                indicator.title = 'Connection lost - changes may not sync';
            } else if (status === 'syncing') {
                dot.className = 'pds-sync-dot pds-sync-syncing';
                text.textContent = 'Syncing...';
            }
        }

        /**
         * Update active users indicator
         */
        updateActiveUsersIndicator() {
            const container = this.wrapper.querySelector('.pds-active-users');
            if (!container) return;

            const count = this.activeUsers.length;
            const countEl = container.querySelector('.pds-user-count');
            const listEl = container.querySelector('.pds-user-list');

            if (countEl) {
                // +1 for current user
                countEl.textContent = count + 1;
            }

            if (listEl) {
                if (count === 0) {
                    listEl.innerHTML = '<span class="pds-user-list-empty">Only you are viewing this table</span>';
                } else {
                    const names = this.activeUsers.map(u =>
                        `<span class="pds-user-item">
                            <img src="${u.avatar}" alt="${u.name}" class="pds-user-avatar">
                            ${u.name}
                        </span>`
                    ).join('');
                    listEl.innerHTML = names;
                }
            }

            // Update tooltip
            if (count > 0) {
                const names = this.activeUsers.map(u => u.name).join(', ');
                container.title = `Also viewing: ${names}`;
            } else {
                container.title = 'You are the only one viewing this table';
            }
        }
    }

    /**
     * Initialize all tables on page
     */
    function initTables() {
        document.querySelectorAll('.pds-post-table').forEach(container => {
            if (!container._pdsTable) {
                container._pdsTable = new PDSPostTable(container);
            }
        });
    }

    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTables);
    } else {
        initTables();
    }

    // Expose for external access
    window.PDSPostTable = PDSPostTable;

})();
