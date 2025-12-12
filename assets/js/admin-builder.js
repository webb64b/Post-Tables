(function($) {
    'use strict';

    // Available fields cache
    let availableFields = {};
    let currentPostType = '';

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        initPostTypeSelector();
        initColumnsBuilder();
        initQueryFilters();
        initColumnDefaults();
        initConditionalRules();
        initColorPickers();
        initShortcodeCopy();
        initFormSubmitHandler();
    });

    /**
     * Initialize form submit handler to ensure all JSON is updated before save
     */
    function initFormSubmitHandler() {
        // Use mousedown on publish button - fires before form submission
        $(document).on('mousedown', '#publish, #save-post, input[type="submit"]', function() {
            updateColumnsJson();
            updateFiltersJson();
            updateColumnDefaultsJson();
            updateRulesJson();
            
            var columnsVal = $('input[name="pds_table_columns"]').val();
        });

        // Also hook into form submit as backup
        $(document).on('submit', '#post', function(e) {
            updateColumnsJson();
            updateFiltersJson();
            updateColumnDefaultsJson();
            updateRulesJson();
            
        });
        
        // Update JSON every 2 seconds as a safety net
        setInterval(function() {
            if ($('#pds-columns-container .pds-column-row').length > 0) {
                updateColumnsJson();
            }
            if ($('#pds-filters-container .pds-filter-row').length > 0) {
                updateFiltersJson();
            }
        }, 2000);
    }

    /**
     * Initialize post type selector
     */
    function initPostTypeSelector() {
        const $selector = $('#pds_source_post_type');
        currentPostType = $selector.val();

        if (currentPostType) {
            loadFieldsForPostType(currentPostType);
        }

        $selector.on('change', function() {
            currentPostType = $(this).val();
            
            if (currentPostType) {
                loadFieldsForPostType(currentPostType);
                $('#pds-add-column').prop('disabled', false);
                $('#pds-add-filter').prop('disabled', false);
            } else {
                availableFields = {};
                $('#pds-add-column').prop('disabled', true);
                $('#pds-add-filter').prop('disabled', true);
            }
        });
    }

    /**
     * Load fields for post type via REST API
     */
    function loadFieldsForPostType(postType) {
        $.ajax({
            url: pdsPostTablesAdmin.restUrl + '/fields/' + postType,
            method: 'GET',
            headers: {
                'X-WP-Nonce': pdsPostTablesAdmin.nonce
            },
            success: function(response) {
                availableFields = response;
                populateFieldSelects();
                
                // Enable add buttons now that fields are loaded
                $('#pds-add-column').prop('disabled', false);
                $('#pds-add-filter').prop('disabled', false);
            },
            error: function(xhr) {
                console.error('Error loading fields:', xhr);
            }
        });
    }

    /**
     * Populate field select dropdowns (columns and filters)
     */
    function populateFieldSelects() {
        // Populate column field selects
        $('.pds-column-field-select').each(function() {
            const $select = $(this);
            const $row = $select.closest('.pds-column-row');
            const currentFieldKey = $row.find('.pds-column-field-key').val();
            const currentSource = $row.find('.pds-column-source').val();

            $select.empty();
            $select.append('<option value="">' + pdsPostTablesAdmin.i18n.selectField + '</option>');

            // Add grouped options
            for (const source in availableFields) {
                const fields = availableFields[source];
                const sourceLabel = getSourceLabel(source);

                if (Object.keys(fields).length === 0) continue;

                const $group = $('<optgroup>').attr('label', sourceLabel);

                // Sort fields alphabetically by label
                const sortedKeys = Object.keys(fields).sort((a, b) => {
                    const labelA = (fields[a].label || a).toLowerCase();
                    const labelB = (fields[b].label || b).toLowerCase();
                    return labelA.localeCompare(labelB);
                });

                for (const fieldKey of sortedKeys) {
                    const field = fields[fieldKey];
                    const $option = $('<option>')
                        .val(fieldKey)
                        .text(field.label)
                        .data('source', source)
                        .data('type', field.type)
                        .data('options', field.options || {});

                    // Match both field_key AND source to select the correct option
                    if (fieldKey === currentFieldKey && source === currentSource) {
                        $option.prop('selected', true);
                    }

                    $group.append($option);
                }

                $select.append($group);
            }
        });
        
        // Populate filter field selects
        $('#pds-filters-container .pds-filter-row').each(function() {
            populateFilterFieldSelect($(this));
        });
    }

    /**
     * Get human readable source label
     */
    function getSourceLabel(source) {
        const labels = {
            'post_fields': 'Post Fields',
            'acf': 'ACF Fields',
            'meta': 'Custom Meta',
            'taxonomies': 'Taxonomies'
        };
        return labels[source] || source;
    }

    /**
     * Initialize columns builder
     */
    function initColumnsBuilder() {
        const $container = $('#pds-columns-container');

        // Make sortable
        $container.sortable({
            handle: '.pds-column-handle',
            update: function() {
                updateColumnsJson();
                rebuildColumnDefaults();
                rebuildRuleTargets();
            }
        });

        // Add column button
        $('#pds-add-column').on('click', function() {
            addColumnRow();
        });

        // Remove column
        $container.on('click', '.pds-remove-column', function() {
            $(this).closest('.pds-column-row').remove();
            updateColumnsJson();
            rebuildColumnDefaults();
            rebuildRuleTargets();
            
            if ($container.find('.pds-column-row').length === 0) {
                $container.html('<p class="pds-no-columns">' + pdsPostTablesAdmin.i18n.selectField + '</p>');
            }
        });

        // Field select change
        $container.on('change', '.pds-column-field-select', function() {
            
            const $row = $(this).closest('.pds-column-row');
            const $option = $(this).find('option:selected');
            
            const fieldKey = $(this).val();
            const source = $option.data('source') || '';
            const type = $option.data('type') || 'text';
            const options = $option.data('options') || {};
            const label = $option.text();
            
            
            $row.find('.pds-column-source').val(source);
            $row.find('.pds-column-type').val(type);
            $row.find('.pds-column-options').val(JSON.stringify(options));
            $row.find('.pds-column-field-key').val(fieldKey);
            
            
            // Auto-fill label if empty
            const $labelInput = $row.find('.pds-column-label-input');
            if (!$labelInput.val()) {
                $labelInput.val(label);
            }
            
            updateColumnsJson();
            rebuildColumnDefaults();
            rebuildRuleTargets();
        });

        // Other field changes
        $container.on('change', '.pds-column-label-input, .pds-column-editable, .pds-column-sortable, .pds-column-filterable', function() {
            updateColumnsJson();
            rebuildColumnDefaults();
            rebuildRuleTargets();
        });
    }

    /**
     * Add a new column row
     */
    function addColumnRow() {
        const $container = $('#pds-columns-container');
        const index = $container.find('.pds-column-row').length;
        
        // Remove "no columns" message
        $container.find('.pds-no-columns').remove();
        
        // Get template
        let template = $('#tmpl-pds-column-row').html();
        template = template.replace(/\{\{index\}\}/g, index);
        
        // Generate unique ID
        const colId = 'col_' + generateUUID();
        
        const $row = $(template);
        $row.find('.pds-column-id').val(colId);
        
        $container.append($row);
        
        // Populate the field select
        populateFieldSelects();
        
        updateColumnsJson();
    }

    /**
     * Update columns JSON hidden field
     */
    function updateColumnsJson() {
        const columns = [];
        
        
        $('#pds-columns-container .pds-column-row').each(function(index) {
            const $row = $(this);
            
            // Read from hidden fields (populated by PHP or JS on field change)
            // This ensures we don't lose data if the select isn't populated yet
            const fieldKey = $row.find('.pds-column-field-key').val();
            
            
            if (!fieldKey) {
                return;
            }
            
            let options = {};
            try {
                options = JSON.parse($row.find('.pds-column-options').val() || '{}');
            } catch (e) {
                console.error('Row ' + index + ' options parse error:', e);
            }
            
            const columnData = {
                id: $row.find('.pds-column-id').val(),
                field_key: fieldKey,
                source: $row.find('.pds-column-source').val(),
                label: $row.find('.pds-column-label-input').val(),
                type: $row.find('.pds-column-type').val() || 'text',
                editable: $row.find('.pds-column-editable').is(':checked'),
                sortable: $row.find('.pds-column-sortable').is(':checked'),
                filterable: $row.find('.pds-column-filterable').is(':checked'),
                frozen: $row.find('.pds-column-frozen').is(':checked'),
                options: options
            };
            
            columns.push(columnData);
        });
        
        const jsonValue = JSON.stringify(columns);
        
        // Use NAME selector - more reliable than ID since templates might duplicate IDs
        $('input[name="pds_table_columns"]').val(jsonValue);
    }

    /**
     * Initialize query filters
     */
    function initQueryFilters() {
        // Use document-level delegation for the add button (more reliable)
        $(document).on('click', '#pds-add-filter', function(e) {
            e.preventDefault();
            if (!$(this).prop('disabled')) {
                addFilterRow();
            }
        });

        // Remove filter
        $(document).on('click', '.pds-remove-filter', function() {
            $(this).closest('.pds-filter-row').remove();
            updateFiltersJson();
            
            if ($('#pds-filters-container .pds-filter-row').length === 0) {
                $('#pds-filters-container').html('<p class="pds-no-filters">No filters added. All posts will be displayed.</p>');
            }
        });

        // Field select change
        $(document).on('change', '.pds-filter-field', function() {
            const $row = $(this).closest('.pds-filter-row');
            const $option = $(this).find('option:selected');
            
            const fieldKey = $(this).val();
            const source = $option.data('source') || '';
            
            $row.find('.pds-filter-field-key').val(fieldKey);
            $row.find('.pds-filter-source').val(source);
            
            updateFiltersJson();
        });

        // Operator change - show/hide value field
        $(document).on('change', '.pds-filter-operator', function() {
            const $row = $(this).closest('.pds-filter-row');
            const operator = $(this).val();
            
            if (operator === 'is_empty' || operator === 'is_not_empty') {
                $row.find('.pds-filter-value-input').hide();
            } else {
                $row.find('.pds-filter-value-input').show();
            }
            
            updateFiltersJson();
        });
        
        // Value change
        $(document).on('change input', '.pds-filter-value', function() {
            updateFiltersJson();
        });
    }

    /**
     * Add a new filter row
     */
    function addFilterRow() {
        const $container = $('#pds-filters-container');
        const $template = $('#tmpl-pds-filter-row');
        
        if ($template.length === 0) {
            console.error('Filter template not found');
            return;
        }
        
        const template = $template.html();
        
        if (!template || template.trim() === '') {
            console.error('Filter template is empty');
            return;
        }
        
        const index = $container.find('.pds-filter-row').length;
        
        // Generate unique ID
        const uniqueId = 'filter_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        // Replace template placeholders
        let html = template.replace(/\{\{index\}\}/g, index);
        html = html.replace(/filter_[a-f0-9-]+/g, uniqueId);
        
        // Remove "no filters" message
        $container.find('.pds-no-filters').remove();
        
        // Add new row
        const $row = $(html);
        $container.append($row);
        
        // Populate field select
        populateFilterFieldSelect($row);
        
        updateFiltersJson();
    }

    /**
     * Populate filter field select dropdown
     */
    function populateFilterFieldSelect($row) {
        const $select = $row.find('.pds-filter-field');
        const currentValue = $row.find('.pds-filter-field-key').val();
        const currentSource = $row.find('.pds-filter-source').val();
        
        // Clear existing options except first
        $select.find('option:not(:first)').remove();
        
        // Add fields grouped by source
        for (const source in availableFields) {
            const fields = availableFields[source];
            const sourceLabel = getSourceLabel(source);
            
            if (Object.keys(fields).length === 0) continue;
            
            const $optgroup = $('<optgroup>').attr('label', sourceLabel);
            
            // Sort fields alphabetically by label
            const sortedKeys = Object.keys(fields).sort((a, b) => {
                const labelA = (fields[a].label || a).toLowerCase();
                const labelB = (fields[b].label || b).toLowerCase();
                return labelA.localeCompare(labelB);
            });
            
            for (const fieldKey of sortedKeys) {
                const field = fields[fieldKey];
                const $option = $('<option>')
                    .val(fieldKey)
                    .text(field.label || fieldKey)
                    .data('source', source)
                    .data('type', field.type);
                
                if (fieldKey === currentValue && source === currentSource) {
                    $option.prop('selected', true);
                }
                
                $optgroup.append($option);
            }
            
            if ($optgroup.children().length > 0) {
                $select.append($optgroup);
            }
        }
        
        // Show/hide value field based on operator
        const operator = $row.find('.pds-filter-operator').val();
        if (operator === 'is_empty' || operator === 'is_not_empty') {
            $row.find('.pds-filter-value-input').hide();
        }
    }

    /**
     * Update filters JSON hidden field
     */
    function updateFiltersJson() {
        const filters = [];
        
        $('#pds-filters-container .pds-filter-row').each(function() {
            const $row = $(this);
            const fieldKey = $row.find('.pds-filter-field-key').val();
            
            if (!fieldKey) {
                return; // Skip empty rows
            }
            
            const filterData = {
                id: $row.find('.pds-filter-id').val(),
                field: fieldKey,
                source: $row.find('.pds-filter-source').val(),
                operator: $row.find('.pds-filter-operator').val(),
                value: $row.find('.pds-filter-value').val()
            };
            
            filters.push(filterData);
        });
        
        $('input[name="pds_table_query_filters"]').val(JSON.stringify(filters));
    }

    /**
     * Initialize column defaults
     */
    function initColumnDefaults() {
        const $container = $('#pds-column-defaults-container');

        // Update JSON on any change
        $container.on('change', '[data-default]', function() {
            updateColumnDefaultsJson();
        });

        // Handle type override changes - show/hide relevant type-specific options
        $container.on('change', '.pds-type-override-select', function() {
            const $row = $(this).closest('.pds-column-defaults-row');
            const overrideType = $(this).val();
            const detectedType = $row.data('detected-type');
            const effectiveType = overrideType || detectedType;

            // Update the data-column-type attribute
            $row.attr('data-column-type', effectiveType);

            // Show/hide type-specific options
            $row.find('[data-show-for]').each(function() {
                const $el = $(this);
                const showFor = $el.data('show-for').split(',');
                if (showFor.indexOf(effectiveType) !== -1) {
                    $el.show();
                } else {
                    $el.hide();
                }
            });

            updateColumnDefaultsJson();
        });
    }

    /**
     * Rebuild column defaults UI when columns change
     */
    function rebuildColumnDefaults() {
        const $container = $('#pds-column-defaults-container');
        const columns = JSON.parse($('input[name="pds_table_columns"]').val() || '[]');
        const currentDefaults = JSON.parse($('input[name="pds_table_column_defaults"]').val() || '{}');

        if (columns.length === 0) {
            $container.html('<p class="pds-no-columns">Add columns above to configure their formatting.</p>');
            return;
        }

        $container.empty();

        columns.forEach(function(column) {
            const defaults = currentDefaults[column.id] || {};
            const detectedType = column.type;
            // Use type_override if set, otherwise use detected type
            const effectiveType = defaults.type_override || detectedType;

            const template = $('#tmpl-pds-column-defaults-row').html()
                .replace(/\{\{id\}\}/g, column.id)
                .replace(/\{\{label\}\}/g, column.label || column.field_key)
                .replace(/\{\{type\}\}/g, detectedType);

            const $row = $(template);

            // Update the data attributes
            $row.attr('data-column-type', effectiveType);
            $row.attr('data-detected-type', detectedType);

            // Restore saved values
            for (const key in defaults) {
                const $input = $row.find('[data-default="' + key + '"]');
                if ($input.is(':checkbox')) {
                    $input.prop('checked', defaults[key]);
                } else {
                    $input.val(defaults[key]);
                }
            }

            // Show/hide type-specific options based on effective type
            $row.find('[data-show-for]').each(function() {
                const $el = $(this);
                const showFor = $el.data('show-for').split(',');
                if (showFor.indexOf(effectiveType) !== -1) {
                    $el.show();
                } else {
                    $el.hide();
                }
            });

            $container.append($row);
        });

        // Reinitialize color pickers
        initColorPickers();

        updateColumnDefaultsJson();
    }

    /**
     * Update column defaults JSON
     */
    function updateColumnDefaultsJson() {
        const defaults = {};
        
        $('#pds-column-defaults-container .pds-column-defaults-row').each(function() {
            const $row = $(this);
            const columnId = $row.data('column-id');
            
            defaults[columnId] = {};
            
            $row.find('[data-default]').each(function() {
                const $input = $(this);
                const key = $input.data('default');
                
                if ($input.is(':checkbox')) {
                    defaults[columnId][key] = $input.is(':checked');
                } else {
                    defaults[columnId][key] = $input.val();
                }
            });
        });
        
        $('input[name="pds_table_column_defaults"]').val(JSON.stringify(defaults));
    }

    /**
     * Initialize conditional rules
     */
    function initConditionalRules() {
        const $container = $('#pds-rules-container');

        // Add cell rule
        $('#pds-add-cell-rule').on('click', function() {
            addRuleRow('cell');
        });

        // Add row rule
        $('#pds-add-row-rule').on('click', function() {
            addRuleRow('row');
        });

        // Remove rule
        $container.on('click', '.pds-remove-rule', function() {
            $(this).closest('.pds-rule-row').remove();
            updateRulesJson();
            
            if ($container.find('.pds-rule-row').length === 0) {
                $container.html('<p class="pds-no-rules">No formatting rules added yet.</p>');
            }
        });

        // Rule field changes
        $container.on('change', '[data-rule]', function() {
            updateRulesJson();
        });

        // Bold checkbox special handling
        $container.on('change', '.pds-rule-style-bold', function() {
            updateRulesJson();
        });
        
        // Populate existing rule dropdowns on page load
        rebuildRuleTargets();
    }

    /**
     * Add a new rule row
     */
    function addRuleRow(scope) {
        const $container = $('#pds-rules-container');
        const index = $container.find('.pds-rule-row').length;
        
        // Remove "no rules" message
        $container.find('.pds-no-rules').remove();
        
        // Get template
        let template = $('#tmpl-pds-rule-row').html();
        template = template.replace(/\{\{index\}\}/g, index);
        template = template.replace(/\{\{scope\}\}/g, scope);
        
        const $row = $(template);
        
        // Generate unique ID
        $row.find('.pds-rule-id').val('rule_' + generateUUID());
        $row.find('.pds-rule-scope').val(scope);
        $row.attr('data-scope', scope);
        
        // Update header
        $row.find('.pds-rule-type').text(scope === 'cell' ? 'Cell Rule' : 'Row Rule');
        
        // Show/hide appropriate fields based on scope
        if (scope === 'cell') {
            $row.find('.pds-rule-target').show();
            $row.find('.pds-rule-condition-field').hide();
        } else {
            $row.find('.pds-rule-target').hide();
            $row.find('.pds-rule-condition-field').show();
        }
        
        $container.append($row);
        
        // Populate selects with column options
        rebuildRuleTargets();
        
        // Initialize color pickers
        $row.find('.pds-color-picker').wpColorPicker({
            change: function() {
                updateRulesJson();
            },
            clear: function() {
                updateRulesJson();
            }
        });
        
        updateRulesJson();
    }

    /**
     * Rebuild rule target selects when columns change
     */
    function rebuildRuleTargets() {
        const columnsJson = $('input[name="pds_table_columns"]').val() || '[]';
        const columns = JSON.parse(columnsJson);
        
        // Update target column selects
        $('.pds-rule-target-column').each(function() {
            const $select = $(this);
            const currentValue = $select.val();
            
            $select.find('option:not(:first)').remove();
            
            columns.forEach(function(column) {
                $select.append(
                    $('<option>')
                        .val(column.id)
                        .text(column.label || column.field_key)
                        .prop('selected', column.id === currentValue)
                );
            });
        });
        
        // Update condition field selects
        $('.pds-rule-condition-field').each(function() {
            const $select = $(this);
            const currentValue = $select.val();
            
            $select.find('option:not(:first)').remove();
            
            columns.forEach(function(column) {
                $select.append(
                    $('<option>')
                        .val(column.field_key)
                        .text(column.label || column.field_key)
                        .prop('selected', column.field_key === currentValue)
                );
            });
        });
    }

    /**
     * Update rules JSON
     */
    function updateRulesJson() {
        const rules = [];
        
        $('#pds-rules-container .pds-rule-row').each(function() {
            const $row = $(this);
            
            const rule = {
                id: $row.find('.pds-rule-id').val(),
                scope: $row.find('.pds-rule-scope').val(),
                target_column: $row.find('.pds-rule-target-column').val() || '',
                condition: {
                    field: $row.find('.pds-rule-condition-field').val() || '',
                    operator: $row.find('.pds-rule-condition-operator').val(),
                    value: $row.find('.pds-rule-condition-value').val()
                },
                style: {
                    background: $row.find('.pds-rule-style-bg').val() || '',
                    color: $row.find('.pds-rule-style-color').val() || '',
                    font_weight: $row.find('.pds-rule-style-bold').is(':checked') ? 'bold' : 'normal'
                }
            };
            
            rules.push(rule);
        });
        
        $('input[name="pds_table_conditional_rules"]').val(JSON.stringify(rules));
    }

    /**
     * Initialize color pickers
     */
    function initColorPickers() {
        $('.pds-color-picker').not('.wp-color-picker').wpColorPicker({
            change: function() {
                // Trigger change after color picker updates
                setTimeout(function() {
                    updateColumnDefaultsJson();
                    updateRulesJson();
                }, 100);
            },
            clear: function() {
                updateColumnDefaultsJson();
                updateRulesJson();
            }
        });
    }

    /**
     * Initialize shortcode copy
     */
    function initShortcodeCopy() {
        $('#pds-copy-shortcode').on('click', function() {
            const shortcode = $('#pds-shortcode-display').text();
            
            navigator.clipboard.writeText(shortcode).then(function() {
                const $btn = $('#pds-copy-shortcode');
                $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
                
                setTimeout(function() {
                    $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
                }, 2000);
            });
        });
    }

    /**
     * Generate UUID
     */
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

})(jQuery);
