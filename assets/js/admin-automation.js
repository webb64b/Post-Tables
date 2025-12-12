/**
 * PDS Post Tables - Automation Builder
 */
(function($) {
    'use strict';

    const AutomationBuilder = {
        config: null,
        fields: [],
        postType: '',

        init: function() {
            const $builder = $('#pds-automation-builder');
            if (!$builder.length) return;

            this.config = $builder.data('config') || {};
            this.$builder = $builder;

            this.loadFields().then(() => {
                this.render();
                this.bindEvents();
            });
        },

        loadFields: function() {
            const tableId = this.config.table_id;
            const postType = this.config.post_type;

            if (!tableId && !postType) {
                return Promise.resolve();
            }

            const endpoint = tableId
                ? `${pdsAutomationAdmin.restUrl}/automation/table-fields/${tableId}`
                : `${pdsAutomationAdmin.restUrl}/automation/fields/${postType}`;

            return $.ajax({
                url: endpoint,
                method: 'GET',
                headers: { 'X-WP-Nonce': pdsAutomationAdmin.restNonce }
            }).then(response => {
                this.fields = response.fields || [];
                this.postType = response.post_type || postType;
            }).catch(() => {
                this.fields = [];
            });
        },

        render: function() {
            const html = `
                <div class="pds-automation-builder-wrap">
                    ${this.renderSourceSelect()}
                    ${this.renderEnabledToggle()}
                    ${this.renderTriggerSection()}
                    ${this.renderActionsSection()}
                    ${this.renderScheduleSection()}
                    ${this.renderSettingsSection()}
                </div>
            `;

            this.$builder.html(html);
            this.updateHiddenFields();
        },

        renderSourceSelect: function() {
            const tables = pdsAutomationAdmin.tables || {};
            const postTypes = pdsAutomationAdmin.postTypes || {};

            return `
                <div class="pds-section pds-source-section">
                    <h3>${this.i18n('Source')}</h3>
                    <div class="pds-field-row">
                        <label>
                            <input type="radio" name="pds_source_type" value="table" ${this.config.table_id ? 'checked' : ''}>
                            ${this.i18n('Table')}
                        </label>
                        <label>
                            <input type="radio" name="pds_source_type" value="post_type" ${!this.config.table_id && this.config.post_type ? 'checked' : ''}>
                            ${this.i18n('Post Type')}
                        </label>
                    </div>
                    <div class="pds-source-table" ${this.config.table_id ? '' : 'style="display:none"'}>
                        <select id="pds_source_table">
                            <option value="">${this.i18n('Select a table...')}</option>
                            ${Object.entries(tables).map(([id, name]) =>
                                `<option value="${id}" ${this.config.table_id == id ? 'selected' : ''}>${this.escapeHtml(name)}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="pds-source-post-type" ${!this.config.table_id && this.config.post_type ? '' : 'style="display:none"'}>
                        <select id="pds_source_post_type">
                            <option value="">${this.i18n('Select a post type...')}</option>
                            ${Object.entries(postTypes).map(([name, label]) =>
                                `<option value="${name}" ${this.config.post_type === name ? 'selected' : ''}>${this.escapeHtml(label)}</option>`
                            ).join('')}
                        </select>
                    </div>
                </div>
            `;
        },

        renderEnabledToggle: function() {
            return `
                <div class="pds-section pds-enabled-section">
                    <label class="pds-toggle">
                        <input type="checkbox" id="pds_enabled" ${this.config.enabled ? 'checked' : ''}>
                        <span class="pds-toggle-slider"></span>
                        <span class="pds-toggle-label">${this.i18n('Automation Enabled')}</span>
                    </label>
                </div>
            `;
        },

        renderTriggerSection: function() {
            const trigger = this.config.trigger || {};
            const triggerTypes = pdsAutomationAdmin.triggerTypes || {};

            let triggerOptionsHtml = '<option value="">' + this.i18n('selectTrigger') + '</option>';
            Object.entries(triggerTypes).forEach(([category, data]) => {
                triggerOptionsHtml += `<optgroup label="${this.escapeHtml(data.label)}">`;
                Object.entries(data.triggers).forEach(([type, info]) => {
                    triggerOptionsHtml += `<option value="${type}" ${trigger.type === type ? 'selected' : ''}>${this.escapeHtml(info.label)}</option>`;
                });
                triggerOptionsHtml += '</optgroup>';
            });

            return `
                <div class="pds-section pds-trigger-section">
                    <h3>${this.i18n('Trigger')}</h3>
                    <p class="description">${this.i18n('When should this automation run?')}</p>

                    <div class="pds-field-row">
                        <label>${this.i18n('Trigger Type')}</label>
                        <select id="pds_trigger_type">${triggerOptionsHtml}</select>
                    </div>

                    <div id="pds_trigger_options" class="pds-trigger-options">
                        ${this.renderTriggerOptions(trigger)}
                    </div>

                    <div class="pds-trigger-conditions">
                        <h4>${this.i18n('triggerConditions')}</h4>
                        <p class="description">${this.i18n('triggerConditionsHelp')}</p>
                        <div id="pds_trigger_conditions">
                            ${this.renderConditionGroup(trigger.conditions || { logic: 'AND', rules: [] }, 'trigger')}
                        </div>
                    </div>
                </div>
            `;
        },

        renderTriggerOptions: function(trigger) {
            const type = trigger.type || '';
            if (!type) return '';

            let html = '';

            // Field selector for triggers that need it
            const needsField = ['field_changed', 'field_changed_to', 'field_changed_from', 'field_transition',
                               'date_equals_today', 'date_days_before', 'date_days_after', 'date_is_overdue',
                               'date_is_upcoming', 'field_matches'];

            if (needsField.includes(type)) {
                html += `
                    <div class="pds-field-row">
                        <label>${this.i18n('Field')}</label>
                        <select id="pds_trigger_field" class="pds-field-select">
                            <option value="">${this.i18n('selectField')}</option>
                            ${this.renderFieldOptions(trigger.field)}
                        </select>
                    </div>
                `;
            }

            // Value selector for certain triggers
            const needsValue = ['field_changed_to', 'field_changed_from'];
            if (needsValue.includes(type)) {
                html += `
                    <div class="pds-field-row">
                        <label>${type === 'field_changed_to' ? this.i18n('To Value') : this.i18n('From Value')}</label>
                        <input type="text" id="pds_trigger_value" value="${this.escapeHtml(trigger.value || '')}">
                    </div>
                `;
            }

            // From/To for transition
            if (type === 'field_transition') {
                html += `
                    <div class="pds-field-row">
                        <label>${this.i18n('From Value')}</label>
                        <input type="text" id="pds_trigger_from_value" value="${this.escapeHtml(trigger.from_value || '')}">
                    </div>
                    <div class="pds-field-row">
                        <label>${this.i18n('To Value')}</label>
                        <input type="text" id="pds_trigger_to_value" value="${this.escapeHtml(trigger.to_value || '')}">
                    </div>
                `;
            }

            // Days input for date triggers
            const needsDays = ['date_days_before', 'date_days_after', 'date_is_upcoming'];
            if (needsDays.includes(type)) {
                html += `
                    <div class="pds-field-row">
                        <label>${this.i18n('Days')}</label>
                        <input type="number" id="pds_trigger_days" min="0" value="${trigger.days || trigger.value || 7}">
                    </div>
                `;
            }

            // Operator and value for field_matches
            if (type === 'field_matches') {
                const operators = pdsAutomationAdmin.operators || {};
                html += `
                    <div class="pds-field-row">
                        <label>${this.i18n('Operator')}</label>
                        <select id="pds_trigger_operator">
                            ${Object.entries(operators).map(([op, label]) =>
                                `<option value="${op}" ${trigger.operator === op ? 'selected' : ''}>${this.escapeHtml(label)}</option>`
                            ).join('')}
                        </select>
                    </div>
                    <div class="pds-field-row">
                        <label>${this.i18n('Value')}</label>
                        <input type="text" id="pds_trigger_value" value="${this.escapeHtml(trigger.value || '')}">
                    </div>
                `;
            }

            // External changes option for field change triggers
            const fieldChangeTriggers = ['field_changed', 'field_changed_to', 'field_changed_from', 'field_transition'];
            if (fieldChangeTriggers.includes(type)) {
                html += `
                    <div class="pds-field-row">
                        <label>
                            <input type="checkbox" id="pds_trigger_external" ${trigger.include_external_changes ? 'checked' : ''}>
                            ${this.i18n('Include changes made outside Post Tables')}
                        </label>
                    </div>
                `;
            }

            return html;
        },

        renderActionsSection: function() {
            const actions = this.config.actions || [];

            return `
                <div class="pds-section pds-actions-section">
                    <h3>${this.i18n('Actions')}</h3>
                    <p class="description">${this.i18n('What should happen when this automation triggers?')}</p>

                    <div id="pds_actions_list">
                        ${actions.map((action, index) => this.renderAction(action, index)).join('')}
                    </div>

                    <button type="button" class="button pds-add-action">
                        + ${this.i18n('addAction')}
                    </button>
                </div>
            `;
        },

        renderAction: function(action, index) {
            const type = action.type || '';
            const actionTypes = pdsAutomationAdmin.actionTypes || {};

            let actionOptionsHtml = '<option value="">' + this.i18n('selectAction') + '</option>';
            Object.entries(actionTypes).forEach(([actionType, info]) => {
                actionOptionsHtml += `<option value="${actionType}" ${type === actionType ? 'selected' : ''}>${this.escapeHtml(info.label)}</option>`;
            });

            return `
                <div class="pds-action" data-index="${index}">
                    <div class="pds-action-header">
                        <span class="pds-action-number">${this.i18n('Action')} #${index + 1}</span>
                        <button type="button" class="button-link pds-remove-action">${this.i18n('remove')}</button>
                    </div>

                    <div class="pds-field-row">
                        <label>${this.i18n('Action Type')}</label>
                        <select class="pds-action-type">${actionOptionsHtml}</select>
                    </div>

                    <div class="pds-action-options">
                        ${this.renderActionOptions(action, index)}
                    </div>

                    <div class="pds-action-conditions">
                        <details>
                            <summary>${this.i18n('actionConditions')}</summary>
                            <p class="description">${this.i18n('actionConditionsHelp')}</p>
                            ${this.renderConditionGroup(action.conditions || { logic: 'AND', rules: [] }, 'action_' + index)}
                        </details>
                    </div>
                </div>
            `;
        },

        renderActionOptions: function(action, index) {
            const type = action.type || '';
            if (!type) return '';

            switch (type) {
                case 'send_email':
                    return this.renderEmailActionOptions(action, index);
                case 'update_field':
                    return this.renderUpdateFieldOptions(action, index);
                case 'copy_field':
                    return this.renderCopyFieldOptions(action, index);
                case 'clear_field':
                    return this.renderClearFieldOptions(action, index);
                case 'increment_field':
                    return this.renderIncrementFieldOptions(action, index);
                case 'change_status':
                    return this.renderChangeStatusOptions(action, index);
                case 'conditional':
                    return this.renderConditionalOptions(action, index);
                default:
                    return '';
            }
        },

        renderEmailActionOptions: function(action, index) {
            const consolidation = action.consolidation || {};

            return `
                <div class="pds-field-row">
                    <label>${this.i18n('Recipients')}</label>
                    <input type="text" class="pds-action-recipients large-text" value="${this.escapeHtml(action.recipients || '')}"
                           placeholder="email@example.com, {{user_email}}">
                    <p class="description">${this.i18n('Comma-separated emails. Supports placeholders.')}</p>
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Subject')}</label>
                    <input type="text" class="pds-action-subject large-text" value="${this.escapeHtml(action.subject || '')}">
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Body')}</label>
                    <textarea class="pds-action-body large-text" rows="6">${this.escapeHtml(action.body || '')}</textarea>
                </div>

                <div class="pds-field-row">
                    <button type="button" class="button button-small pds-show-placeholders">${this.i18n('Available Placeholders')}</button>
                </div>

                <details class="pds-consolidation-settings">
                    <summary>${this.i18n('consolidation')}</summary>
                    <p class="description">${this.i18n('consolidationHelp')}</p>

                    <div class="pds-field-row">
                        <label>
                            <input type="checkbox" class="pds-consolidation-enabled" ${consolidation.enabled ? 'checked' : ''}>
                            ${this.i18n('Enable consolidation')}
                        </label>
                    </div>

                    <div class="pds-consolidation-options" ${consolidation.enabled ? '' : 'style="display:none"'}>
                        <div class="pds-field-row">
                            <label>${this.i18n('Threshold')}</label>
                            <input type="number" class="pds-consolidation-threshold" min="2" value="${consolidation.threshold || 2}">
                            <p class="description">${this.i18n('Consolidate if this many or more items trigger')}</p>
                        </div>

                        <div class="pds-field-row">
                            <label>${this.i18n('Consolidated Subject')}</label>
                            <input type="text" class="pds-consolidation-subject large-text"
                                   value="${this.escapeHtml(consolidation.subject || '{{count}} items need attention')}">
                        </div>

                        <div class="pds-field-row">
                            <label>${this.i18n('Consolidated Body')}</label>
                            <textarea class="pds-consolidation-body large-text" rows="4">${this.escapeHtml(consolidation.body || 'The following items need attention:\n\n{{items_list}}')}</textarea>
                        </div>
                    </div>
                </details>
            `;
        },

        renderUpdateFieldOptions: function(action, index) {
            return `
                <div class="pds-field-row">
                    <label>${this.i18n('Field to Update')}</label>
                    <select class="pds-action-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(action.field_key)}
                    </select>
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Value Type')}</label>
                    <select class="pds-action-value-type">
                        <option value="static" ${action.value_type === 'static' ? 'selected' : ''}>${this.i18n('Static Value')}</option>
                        <option value="dynamic" ${action.value_type === 'dynamic' ? 'selected' : ''}>${this.i18n('From Another Field')}</option>
                        <option value="formula" ${action.value_type === 'formula' ? 'selected' : ''}>${this.i18n('Formula')}</option>
                    </select>
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Value')}</label>
                    <input type="text" class="pds-action-value large-text" value="${this.escapeHtml(action.value || '')}">
                    <p class="description pds-value-hint">${this.getValueTypeHint(action.value_type)}</p>
                </div>
            `;
        },

        renderCopyFieldOptions: function(action, index) {
            return `
                <div class="pds-field-row">
                    <label>${this.i18n('Source Field')}</label>
                    <select class="pds-action-source-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(action.source_field)}
                    </select>
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Target Field')}</label>
                    <select class="pds-action-target-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(action.target_field)}
                    </select>
                </div>
            `;
        },

        renderClearFieldOptions: function(action, index) {
            return `
                <div class="pds-field-row">
                    <label>${this.i18n('Field to Clear')}</label>
                    <select class="pds-action-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(action.field_key)}
                    </select>
                </div>
            `;
        },

        renderIncrementFieldOptions: function(action, index) {
            return `
                <div class="pds-field-row">
                    <label>${this.i18n('Field')}</label>
                    <select class="pds-action-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(action.field_key)}
                    </select>
                </div>

                <div class="pds-field-row">
                    <label>${this.i18n('Amount')}</label>
                    <input type="number" class="pds-action-amount" value="${action.amount || 1}">
                    <p class="description">${this.i18n('Use negative numbers to decrement')}</p>
                </div>
            `;
        },

        renderChangeStatusOptions: function(action, index) {
            const statuses = ['publish', 'draft', 'pending', 'private'];

            return `
                <div class="pds-field-row">
                    <label>${this.i18n('New Status')}</label>
                    <select class="pds-action-status">
                        ${statuses.map(status =>
                            `<option value="${status}" ${action.status === status ? 'selected' : ''}>${status}</option>`
                        ).join('')}
                    </select>
                </div>
            `;
        },

        renderConditionalOptions: function(action, index) {
            const branches = action.branches || [{ conditions: [], actions: [] }];

            return `
                <div class="pds-conditional-branches">
                    ${branches.map((branch, branchIndex) => this.renderConditionalBranch(branch, index, branchIndex)).join('')}
                </div>
                <button type="button" class="button button-small pds-add-branch">+ ${this.i18n('addBranch')}</button>
            `;
        },

        renderConditionalBranch: function(branch, actionIndex, branchIndex) {
            const isElse = branchIndex > 0 && (!branch.conditions || branch.conditions.length === 0);
            const label = branchIndex === 0 ? this.i18n('if') : (isElse ? this.i18n('else') : this.i18n('elseIf'));

            return `
                <div class="pds-branch" data-branch-index="${branchIndex}">
                    <div class="pds-branch-header">
                        <strong>${label}</strong>
                        ${branchIndex > 0 ? `<button type="button" class="button-link pds-remove-branch">${this.i18n('remove')}</button>` : ''}
                    </div>

                    ${!isElse ? `
                        <div class="pds-branch-conditions">
                            ${this.renderConditionGroup(branch.conditions || { logic: 'AND', rules: [] }, 'branch_' + actionIndex + '_' + branchIndex)}
                        </div>
                    ` : ''}

                    <div class="pds-branch-actions">
                        <strong>${this.i18n('then')}:</strong>
                        <div class="pds-branch-actions-list">
                            ${(branch.actions || []).map((a, i) => this.renderNestedAction(a, actionIndex, branchIndex, i)).join('')}
                        </div>
                        <button type="button" class="button button-small pds-add-nested-action">+ ${this.i18n('addAction')}</button>
                    </div>
                </div>
            `;
        },

        renderNestedAction: function(action, actionIndex, branchIndex, nestedIndex) {
            const type = action.type || '';
            const actionTypes = pdsAutomationAdmin.actionTypes || {};

            // Filter out 'conditional' to prevent deep nesting
            let actionOptionsHtml = '<option value="">' + this.i18n('selectAction') + '</option>';
            Object.entries(actionTypes).forEach(([actionType, info]) => {
                if (actionType !== 'conditional') {
                    actionOptionsHtml += `<option value="${actionType}" ${type === actionType ? 'selected' : ''}>${this.escapeHtml(info.label)}</option>`;
                }
            });

            return `
                <div class="pds-nested-action" data-nested-index="${nestedIndex}">
                    <select class="pds-nested-action-type">${actionOptionsHtml}</select>
                    <button type="button" class="button-link pds-remove-nested-action">${this.i18n('remove')}</button>
                    <div class="pds-nested-action-options">
                        ${this.renderActionOptions(action, `${actionIndex}_${branchIndex}_${nestedIndex}`)}
                    </div>
                </div>
            `;
        },

        renderScheduleSection: function() {
            const schedule = this.config.schedule || {};
            const frequencies = pdsAutomationAdmin.frequencies || {};

            return `
                <div class="pds-section pds-schedule-section">
                    <h3>${this.i18n('schedule')}</h3>
                    <p class="description">${this.i18n('scheduleHelp')}</p>

                    <div class="pds-field-row">
                        <label>${this.i18n('Check Frequency')}</label>
                        <select id="pds_schedule_frequency">
                            ${Object.entries(frequencies).map(([key, data]) =>
                                `<option value="${key}" ${schedule.frequency === key ? 'selected' : ''}>${this.escapeHtml(data.label)}</option>`
                            ).join('')}
                        </select>
                    </div>

                    <div class="pds-field-row pds-schedule-time" ${schedule.frequency === 'daily' ? '' : 'style="display:none"'}>
                        <label>${this.i18n('Time')}</label>
                        <input type="time" id="pds_schedule_time" value="${schedule.time || '09:00'}">
                    </div>
                </div>
            `;
        },

        renderSettingsSection: function() {
            const settings = this.config.settings || {};

            return `
                <div class="pds-section pds-settings-section">
                    <h3>${this.i18n('Settings')}</h3>

                    <div class="pds-field-row">
                        <label>
                            <input type="checkbox" id="pds_setting_prevent_loops" ${settings.prevent_loops !== false ? 'checked' : ''}>
                            ${this.i18n('Prevent loops (recommended)')}
                        </label>
                        <p class="description">${this.i18n('Prevents automation from triggering itself')}</p>
                    </div>

                    <div class="pds-field-row">
                        <label>
                            <input type="checkbox" id="pds_setting_run_once" ${settings.run_once_per_post ? 'checked' : ''}>
                            ${this.i18n('Run once per post')}
                        </label>
                        <p class="description">${this.i18n('For date triggers: only trigger once per post per date')}</p>
                    </div>

                    <div class="pds-field-row">
                        <label>
                            <input type="checkbox" id="pds_setting_log" ${settings.log_executions !== false ? 'checked' : ''}>
                            ${this.i18n('Log executions')}
                        </label>
                        <p class="description">${this.i18n('Keep a history of when this automation runs')}</p>
                    </div>
                </div>
            `;
        },

        renderConditionGroup: function(conditions, prefix) {
            const logic = conditions.logic || 'AND';
            const rules = conditions.rules || [];

            return `
                <div class="pds-condition-group" data-prefix="${prefix}">
                    <div class="pds-condition-logic">
                        <select class="pds-logic-select">
                            <option value="AND" ${logic === 'AND' ? 'selected' : ''}>${this.i18n('and')}</option>
                            <option value="OR" ${logic === 'OR' ? 'selected' : ''}>${this.i18n('or')}</option>
                        </select>
                    </div>
                    <div class="pds-conditions-list">
                        ${rules.map((rule, index) => this.renderConditionRule(rule, index)).join('')}
                    </div>
                    <div class="pds-condition-actions">
                        <button type="button" class="button button-small pds-add-condition">+ ${this.i18n('addCondition')}</button>
                        <button type="button" class="button button-small pds-add-condition-group">+ ${this.i18n('addConditionGroup')}</button>
                    </div>
                </div>
            `;
        },

        renderConditionRule: function(rule, index) {
            // Check if this is a nested group
            if (rule.logic && rule.rules) {
                return `
                    <div class="pds-condition-nested" data-index="${index}">
                        ${this.renderConditionGroup(rule, 'nested_' + index)}
                        <button type="button" class="button-link pds-remove-condition">${this.i18n('remove')}</button>
                    </div>
                `;
            }

            const operators = pdsAutomationAdmin.operators || {};

            return `
                <div class="pds-condition-rule" data-index="${index}">
                    <select class="pds-condition-field pds-field-select">
                        <option value="">${this.i18n('selectField')}</option>
                        ${this.renderFieldOptions(rule.field)}
                    </select>
                    <select class="pds-condition-operator">
                        ${Object.entries(operators).map(([op, label]) =>
                            `<option value="${op}" ${rule.operator === op ? 'selected' : ''}>${this.escapeHtml(label)}</option>`
                        ).join('')}
                    </select>
                    <input type="text" class="pds-condition-value" value="${this.escapeHtml(rule.value || '')}"
                           placeholder="${this.i18n('enterValue')}">
                    <button type="button" class="button-link pds-remove-condition">${this.i18n('remove')}</button>
                </div>
            `;
        },

        renderFieldOptions: function(selectedValue) {
            if (!this.fields.length) {
                return `<option value="">${this.i18n('noFieldsAvailable')}</option>`;
            }

            // Group by source
            const grouped = {};
            this.fields.forEach(field => {
                const source = field.source || 'other';
                if (!grouped[source]) grouped[source] = [];
                grouped[source].push(field);
            });

            let html = '';
            const sourceLabels = {
                post_fields: 'Post Fields',
                acf: 'ACF Fields',
                meta: 'Custom Meta',
                taxonomies: 'Taxonomies'
            };

            Object.entries(grouped).forEach(([source, fields]) => {
                html += `<optgroup label="${sourceLabels[source] || source}">`;
                fields.forEach(field => {
                    const selected = selectedValue === field.key ? 'selected' : '';
                    html += `<option value="${field.key}" ${selected}>${this.escapeHtml(field.label || field.key)}</option>`;
                });
                html += '</optgroup>';
            });

            return html;
        },

        bindEvents: function() {
            const self = this;

            // Source type toggle
            this.$builder.on('change', 'input[name="pds_source_type"]', function() {
                const type = $(this).val();
                self.$builder.find('.pds-source-table').toggle(type === 'table');
                self.$builder.find('.pds-source-post-type').toggle(type === 'post_type');
            });

            // Source selection change
            this.$builder.on('change', '#pds_source_table, #pds_source_post_type', function() {
                self.onSourceChange();
            });

            // Enable toggle
            this.$builder.on('change', '#pds_enabled', function() {
                self.config.enabled = $(this).is(':checked');
                self.updateHiddenFields();
            });

            // Trigger type change
            this.$builder.on('change', '#pds_trigger_type', function() {
                self.onTriggerTypeChange();
            });

            // Trigger option changes
            this.$builder.on('change', '#pds_trigger_field, #pds_trigger_value, #pds_trigger_from_value, #pds_trigger_to_value, #pds_trigger_days, #pds_trigger_operator, #pds_trigger_external', function() {
                self.updateTriggerConfig();
            });

            // Add action
            this.$builder.on('click', '.pds-add-action', function() {
                self.addAction();
            });

            // Remove action
            this.$builder.on('click', '.pds-remove-action', function() {
                $(this).closest('.pds-action').remove();
                self.reindexActions();
                self.updateActionsConfig();
            });

            // Action type change
            this.$builder.on('change', '.pds-action-type', function() {
                self.onActionTypeChange($(this));
            });

            // Action option changes
            this.$builder.on('change input', '.pds-action input, .pds-action select, .pds-action textarea', function() {
                self.updateActionsConfig();
            });

            // Consolidation toggle
            this.$builder.on('change', '.pds-consolidation-enabled', function() {
                $(this).closest('.pds-consolidation-settings').find('.pds-consolidation-options').toggle($(this).is(':checked'));
                self.updateActionsConfig();
            });

            // Add condition
            this.$builder.on('click', '.pds-add-condition', function() {
                self.addCondition($(this).closest('.pds-condition-group'));
            });

            // Add condition group
            this.$builder.on('click', '.pds-add-condition-group', function() {
                self.addConditionGroup($(this).closest('.pds-condition-group'));
            });

            // Remove condition
            this.$builder.on('click', '.pds-remove-condition', function() {
                $(this).closest('.pds-condition-rule, .pds-condition-nested').remove();
                self.updateConditionsConfig();
            });

            // Condition changes
            this.$builder.on('change', '.pds-condition-field, .pds-condition-operator, .pds-condition-value, .pds-logic-select', function() {
                self.updateConditionsConfig();
            });

            // Schedule changes
            this.$builder.on('change', '#pds_schedule_frequency', function() {
                self.$builder.find('.pds-schedule-time').toggle($(this).val() === 'daily');
                self.updateScheduleConfig();
            });

            this.$builder.on('change', '#pds_schedule_time', function() {
                self.updateScheduleConfig();
            });

            // Settings changes
            this.$builder.on('change', '#pds_setting_prevent_loops, #pds_setting_run_once, #pds_setting_log', function() {
                self.updateSettingsConfig();
            });

            // Conditional branches
            this.$builder.on('click', '.pds-add-branch', function() {
                self.addConditionalBranch($(this).closest('.pds-action'));
            });

            this.$builder.on('click', '.pds-remove-branch', function() {
                $(this).closest('.pds-branch').remove();
                self.updateActionsConfig();
            });

            // Nested actions in conditionals
            this.$builder.on('click', '.pds-add-nested-action', function() {
                self.addNestedAction($(this).closest('.pds-branch'));
            });

            this.$builder.on('click', '.pds-remove-nested-action', function() {
                $(this).closest('.pds-nested-action').remove();
                self.updateActionsConfig();
            });

            this.$builder.on('change', '.pds-nested-action-type', function() {
                self.onNestedActionTypeChange($(this));
            });

            // Show placeholders
            this.$builder.on('click', '.pds-show-placeholders', function() {
                self.showPlaceholdersModal();
            });

            // Value type change
            this.$builder.on('change', '.pds-action-value-type', function() {
                const hint = self.getValueTypeHint($(this).val());
                $(this).closest('.pds-action-options').find('.pds-value-hint').text(hint);
                self.updateActionsConfig();
            });
        },

        onSourceChange: function() {
            const sourceType = this.$builder.find('input[name="pds_source_type"]:checked').val();

            if (sourceType === 'table') {
                this.config.table_id = parseInt(this.$builder.find('#pds_source_table').val()) || 0;
                this.config.post_type = '';
            } else {
                this.config.table_id = 0;
                this.config.post_type = this.$builder.find('#pds_source_post_type').val();
            }

            this.loadFields().then(() => {
                // Re-render field selects
                this.$builder.find('.pds-field-select').each((i, el) => {
                    const $select = $(el);
                    const currentValue = $select.val();
                    $select.html('<option value="">' + this.i18n('selectField') + '</option>' + this.renderFieldOptions(currentValue));
                });
            });

            this.updateHiddenFields();
        },

        onTriggerTypeChange: function() {
            const type = this.$builder.find('#pds_trigger_type').val();
            this.config.trigger = this.config.trigger || {};
            this.config.trigger.type = type;

            const $options = this.$builder.find('#pds_trigger_options');
            $options.html(this.renderTriggerOptions(this.config.trigger));

            this.updateHiddenFields();
        },

        updateTriggerConfig: function() {
            const trigger = {
                type: this.$builder.find('#pds_trigger_type').val(),
                field: this.$builder.find('#pds_trigger_field').val(),
                value: this.$builder.find('#pds_trigger_value').val(),
                from_value: this.$builder.find('#pds_trigger_from_value').val(),
                to_value: this.$builder.find('#pds_trigger_to_value').val(),
                days: parseInt(this.$builder.find('#pds_trigger_days').val()) || 0,
                operator: this.$builder.find('#pds_trigger_operator').val(),
                include_external_changes: this.$builder.find('#pds_trigger_external').is(':checked'),
                conditions: this.getConditionsFromGroup(this.$builder.find('#pds_trigger_conditions .pds-condition-group').first())
            };

            this.config.trigger = trigger;
            this.updateHiddenFields();
        },

        addAction: function() {
            if (!this.config.actions) this.config.actions = [];
            this.config.actions.push({ type: '' });

            const index = this.config.actions.length - 1;
            const html = this.renderAction(this.config.actions[index], index);

            this.$builder.find('#pds_actions_list').append(html);
            this.updateHiddenFields();
        },

        onActionTypeChange: function($select) {
            const $action = $select.closest('.pds-action');
            const index = $action.data('index');
            const type = $select.val();

            if (!this.config.actions[index]) this.config.actions[index] = {};
            this.config.actions[index].type = type;

            const html = this.renderActionOptions(this.config.actions[index], index);
            $action.find('.pds-action-options').html(html);

            this.updateHiddenFields();
        },

        reindexActions: function() {
            this.$builder.find('.pds-action').each((index, el) => {
                $(el).data('index', index);
                $(el).find('.pds-action-number').text(this.i18n('Action') + ' #' + (index + 1));
            });
        },

        updateActionsConfig: function() {
            const actions = [];

            this.$builder.find('.pds-action').each((index, el) => {
                const $action = $(el);
                const type = $action.find('.pds-action-type').val();

                const action = { type };

                if (type === 'send_email') {
                    action.recipients = $action.find('.pds-action-recipients').val();
                    action.subject = $action.find('.pds-action-subject').val();
                    action.body = $action.find('.pds-action-body').val();

                    const consolidationEnabled = $action.find('.pds-consolidation-enabled').is(':checked');
                    if (consolidationEnabled) {
                        action.consolidation = {
                            enabled: true,
                            threshold: parseInt($action.find('.pds-consolidation-threshold').val()) || 2,
                            subject: $action.find('.pds-consolidation-subject').val(),
                            body: $action.find('.pds-consolidation-body').val()
                        };
                    }
                } else if (type === 'update_field') {
                    action.field_key = $action.find('.pds-action-field').val();
                    action.value_type = $action.find('.pds-action-value-type').val();
                    action.value = $action.find('.pds-action-value').val();
                } else if (type === 'copy_field') {
                    action.source_field = $action.find('.pds-action-source-field').val();
                    action.target_field = $action.find('.pds-action-target-field').val();
                } else if (type === 'clear_field' || type === 'increment_field') {
                    action.field_key = $action.find('.pds-action-field').val();
                    if (type === 'increment_field') {
                        action.amount = parseFloat($action.find('.pds-action-amount').val()) || 1;
                    }
                } else if (type === 'change_status') {
                    action.status = $action.find('.pds-action-status').val();
                } else if (type === 'conditional') {
                    action.branches = [];
                    $action.find('.pds-branch').each((i, branch) => {
                        const $branch = $(branch);
                        const branchData = {
                            conditions: this.getConditionsFromGroup($branch.find('.pds-condition-group').first()),
                            actions: []
                        };

                        $branch.find('.pds-nested-action').each((j, nested) => {
                            const $nested = $(nested);
                            const nestedType = $nested.find('.pds-nested-action-type').val();
                            const nestedAction = { type: nestedType };
                            // Add nested action options...
                            branchData.actions.push(nestedAction);
                        });

                        action.branches.push(branchData);
                    });
                }

                // Get action conditions
                const $conditionGroup = $action.find('.pds-action-conditions .pds-condition-group').first();
                if ($conditionGroup.length) {
                    action.conditions = this.getConditionsFromGroup($conditionGroup);
                }

                actions.push(action);
            });

            this.config.actions = actions;
            this.updateHiddenFields();
        },

        updateConditionsConfig: function() {
            this.updateTriggerConfig();
            this.updateActionsConfig();
        },

        getConditionsFromGroup: function($group) {
            if (!$group || !$group.length) return { logic: 'AND', rules: [] };

            const logic = $group.find('> .pds-condition-logic .pds-logic-select').val() || 'AND';
            const rules = [];

            $group.find('> .pds-conditions-list > .pds-condition-rule, > .pds-conditions-list > .pds-condition-nested').each((i, el) => {
                const $el = $(el);

                if ($el.hasClass('pds-condition-nested')) {
                    // Nested group
                    rules.push(this.getConditionsFromGroup($el.find('.pds-condition-group').first()));
                } else {
                    // Single rule
                    rules.push({
                        field: $el.find('.pds-condition-field').val(),
                        operator: $el.find('.pds-condition-operator').val(),
                        value: $el.find('.pds-condition-value').val()
                    });
                }
            });

            return { logic, rules };
        },

        addCondition: function($group) {
            const html = this.renderConditionRule({ field: '', operator: 'equals', value: '' }, Date.now());
            $group.find('> .pds-conditions-list').append(html);
            this.updateConditionsConfig();
        },

        addConditionGroup: function($group) {
            const html = `
                <div class="pds-condition-nested" data-index="${Date.now()}">
                    ${this.renderConditionGroup({ logic: 'OR', rules: [] }, 'nested_' + Date.now())}
                    <button type="button" class="button-link pds-remove-condition">${this.i18n('remove')}</button>
                </div>
            `;
            $group.find('> .pds-conditions-list').append(html);
            this.updateConditionsConfig();
        },

        updateScheduleConfig: function() {
            this.config.schedule = {
                frequency: this.$builder.find('#pds_schedule_frequency').val(),
                time: this.$builder.find('#pds_schedule_time').val(),
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
            };
            this.updateHiddenFields();
        },

        updateSettingsConfig: function() {
            this.config.settings = {
                prevent_loops: this.$builder.find('#pds_setting_prevent_loops').is(':checked'),
                run_once_per_post: this.$builder.find('#pds_setting_run_once').is(':checked'),
                log_executions: this.$builder.find('#pds_setting_log').is(':checked')
            };
            this.updateHiddenFields();
        },

        addConditionalBranch: function($action) {
            const actionIndex = $action.data('index');
            const branchIndex = $action.find('.pds-branch').length;

            const html = this.renderConditionalBranch({ conditions: [], actions: [] }, actionIndex, branchIndex);
            $action.find('.pds-conditional-branches').append(html);

            this.updateActionsConfig();
        },

        addNestedAction: function($branch) {
            const nestedIndex = $branch.find('.pds-nested-action').length;
            const actionIndex = $branch.closest('.pds-action').data('index');
            const branchIndex = $branch.data('branch-index');

            const html = this.renderNestedAction({ type: '' }, actionIndex, branchIndex, nestedIndex);
            $branch.find('.pds-branch-actions-list').append(html);

            this.updateActionsConfig();
        },

        onNestedActionTypeChange: function($select) {
            const $nested = $select.closest('.pds-nested-action');
            const type = $select.val();

            const html = this.renderActionOptions({ type }, 'nested');
            $nested.find('.pds-nested-action-options').html(html);

            this.updateActionsConfig();
        },

        updateHiddenFields: function() {
            $('#pds_automation_table_id').val(this.config.table_id || '');
            $('#pds_automation_post_type').val(this.config.post_type || '');
            $('#pds_automation_enabled').val(this.config.enabled ? '1' : '0');
            $('#pds_automation_trigger').val(JSON.stringify(this.config.trigger || {}));
            $('#pds_automation_actions').val(JSON.stringify(this.config.actions || []));
            $('#pds_automation_settings').val(JSON.stringify(this.config.settings || {}));
            $('#pds_automation_schedule').val(JSON.stringify(this.config.schedule || {}));
        },

        showPlaceholdersModal: function() {
            const placeholders = pdsAutomationAdmin.placeholders || {};

            let html = '<div class="pds-placeholders-modal"><div class="pds-modal-content">';
            html += '<span class="pds-modal-close">&times;</span>';
            html += '<h2>' + this.i18n('Available Placeholders') + '</h2>';

            Object.entries(placeholders).forEach(([category, items]) => {
                html += `<h3>${category.charAt(0).toUpperCase() + category.slice(1)}</h3>`;
                html += '<table class="wp-list-table widefat fixed striped">';
                Object.entries(items).forEach(([placeholder, description]) => {
                    html += `<tr><td><code>${this.escapeHtml(placeholder)}</code></td><td>${this.escapeHtml(description)}</td></tr>`;
                });
                html += '</table>';
            });

            html += '</div></div>';

            $('body').append(html);

            $('.pds-placeholders-modal').on('click', function(e) {
                if (e.target === this || $(e.target).hasClass('pds-modal-close')) {
                    $(this).remove();
                }
            });
        },

        getValueTypeHint: function(type) {
            switch (type) {
                case 'static':
                    return this.i18n('Enter a static value');
                case 'dynamic':
                    return this.i18n('Enter a field name (e.g., {{other_field}})');
                case 'formula':
                    return this.i18n('Use NOW(), TODAY, or {{field + 7 days}}');
                default:
                    return '';
            }
        },

        i18n: function(key) {
            return (pdsAutomationAdmin.i18n && pdsAutomationAdmin.i18n[key]) || key;
        },

        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        AutomationBuilder.init();
    });

})(jQuery);
