jQuery(document).ready(function($) {
    'use strict';
    
    // Initialize admin interface
    initAdminInterface();
    
    function initAdminInterface() {
        // Tab functionality
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            
            var $this = $(this);
            var target = $this.attr('href');
            
            if (!target || target === '#') {
                return;
            }
            
            // Remove active class from all tabs and panes
            $('.nav-tab').removeClass('nav-tab-active');
            $('.tab-pane').removeClass('active');
            
            // Add active class to clicked tab
            $this.addClass('nav-tab-active');
            
            // Show corresponding pane
            $(target).addClass('active');
        });
        
        // Setup method selector
        $('input[name="setup_method"]').on('change', function() {
            $('.ssp-method-specific').hide();
            var method = $(this).val();
            if (method === 'manual') {
                $('#manual-section').show();
            } else if (method === 'category_based') {
                $('#category-based-section').show();
            } else if (method === 'ai_recommended') {
                // AI recommended doesn't need additional fields
                $('.ssp-method-specific').hide();
            }
            // Re-evaluate controls when setup method changes
            if (typeof updatePillarDependentControls === 'function') {
                updatePillarDependentControls();
            }
        });
        
        // Trigger change event on page load to show correct section
        $('input[name="setup_method"]:checked').trigger('change');

        // Pillar-dependent controls: allow no-pillar manual silos
        function updatePillarDependentControls() {
            var selectedPillar = $('input[name="pillar_post"]:checked').length;
            var hasPillar = selectedPillar > 0;
            var setupMethod = $('input[name="setup_method"]:checked').val();
            var linkingMode = $('input[name="linking_mode"]:checked').val();

            var $linkingModes = $('input[name="linking_mode"]');
            var $pillarOptions = $('#supports-to-pillar, #pillar-to-supports');
            var $manualSupportChecks = $('input[name="support_posts[]"]');
            var $submitBtn = $('#ssp-create-silo-form button[type="submit"]');

            // Linking modes stay enabled; pillar options disabled without pillar
            $linkingModes.prop('disabled', false);
            $pillarOptions.prop('disabled', !hasPillar);

            // Disable pillar-dependent modes if no pillar
            var $starModes = $linkingModes.filter('[value="star_hub"], [value="hub_chain"]');
            if (!hasPillar) {
                $starModes.prop('disabled', true);
                // If current selection is invalid, switch to a safe default
                if (linkingMode === 'star_hub' || linkingMode === 'hub_chain') {
                    $linkingModes.filter('[value="chained"]').prop('checked', true);
                    linkingMode = 'chained';
                    if (typeof showNotice === 'function') {
                        showNotice('Star/Hub and Hub-Chain require a pillar. Switched to Chained.', 'info');
                    }
                }
            } else {
                $starModes.prop('disabled', false);
            }

            // For manual, allow no-pillar if enough supports selected and supported mode
            var allowNoPillar = false;
            if (setupMethod === 'manual' && !hasPillar) {
                var supportSelected = $manualSupportChecks.filter(':checked').length;
                allowNoPillar = supportSelected >= 2 && ['linear','chained','cross_linking','ai_contextual','custom'].indexOf(linkingMode) !== -1;
            }

            // Manual support checkboxes enabled only for manual method
            $manualSupportChecks.prop('disabled', setupMethod !== 'manual');

            // Submit enabled if pillar present or allowed no-pillar
            $submitBtn.prop('disabled', !(hasPillar || allowNoPillar));
        }

        // Bind updates for pillar selection changes
        $(document).on('change', 'input[name="pillar_post"]', updatePillarDependentControls);
        // Bind updates for support post selection changes (affects no-pillar eligibility)
        $(document).on('change', 'input[name="support_posts[]"]', updatePillarDependentControls);
        // Initialize once on load
        updatePillarDependentControls();
        
        // Linking mode selector
        $('input[name="linking_mode"]').on('change', function() {
            $('.ssp-mode-specific').hide();
            $('#pillar-options').hide();
            $('#contextual-options').hide();
            
            if ($(this).val() === 'custom') {
                $('#custom-pattern-section').show();
            } else if ($(this).val() === 'star_hub' || $(this).val() === 'hub_chain') {
                // Show pillar options only if checkbox is checked
                if ($('#pillar-to-supports').is(':checked')) {
                    $('#pillar-options').show();
                }
            } else if ($(this).val() === 'ai_contextual') {
                $('#contextual-options').show();
            }
            // Re-evaluate controls when linking mode changes
            if (typeof updatePillarDependentControls === 'function') {
                updatePillarDependentControls();
            }
        });
        
        // Pillar to supports checkbox handler (now works for ALL modes)
        $('#pillar-to-supports').on('change', function() {
            if ($(this).is(':checked')) {
                $('#pillar-options').show();
            } else {
                $('#pillar-options').hide();
            }
        });
        
        // Auto-assign category checkbox handler
        $('#auto-assign-category').on('change', function() {
            if ($(this).is(':checked')) {
                $('#auto-assign-options').show();
            } else {
                $('#auto-assign-options').hide();
            }
        });
        
        // Custom pattern rules
        $(document).on('click', '.ssp-add-rule', handleAddCustomRule);
        $(document).on('click', '.ssp-remove-rule', handleRemoveCustomRule);
        $('#save-pattern-btn').on('click', handleSavePattern);
        $('#load-pattern-btn').on('click', handleLoadPattern);
        
        // Initialize custom pattern with one default rule
        if ($('#custom-rules-list').length && $('#custom-rules-list .custom-rule-row').length === 0) {
            addCustomRule('', '');
        }
        
        // AI Provider selector - switch model options
        $('#ai-provider-select').on('change', function() {
            var provider = $(this).val();
            if (provider === 'openrouter') {
                $('.openai-models').hide();
                $('.openrouter-models').show();
                // Select first OpenRouter model
                $('#ai-model-select').val('anthropic/claude-3-sonnet');
            } else {
                $('.openai-models').show();
                $('.openrouter-models').hide();
                // Select default OpenAI model
                $('#ai-model-select').val('gpt-3.5-turbo');
            }
        });
        
        // Initialize model dropdown based on current provider
        if ($('#ai-provider-select').length) {
            var currentProvider = $('#ai-provider-select').val();
            if (currentProvider === 'openrouter') {
                $('.openai-models').hide();
                $('.openrouter-models').show();
            } else {
                $('.openai-models').show();
                $('.openrouter-models').hide();
            }
        }
        
        // Silo selector handlers
        $('#silo-select, #preview-silo-select').on('change', function() {
            var hasSelection = $(this).val() !== '';
            $(this).siblings('.ssp-preview-actions, .ssp-link-actions').find('button').prop('disabled', !hasSelection);
        });
        
        // Create silo form
        $('#ssp-create-silo-form').on('submit', handleCreateSilo);
        
        // Silo management buttons
        $(document).on('click', '.ssp-view-silo', handleViewSilo);
        $(document).on('click', '.ssp-delete-silo', handleDeleteSilo);
        $(document).on('click', '.ssp-generate-links', handleGenerateLinks);
        $(document).on('click', '.ssp-preview-silo', handlePreviewSilo);
        
        // Link engine buttons
        $('#generate-links-btn').on('click', handleGenerateLinksEngine);
        $('#preview-links-btn').on('click', handlePreviewLinksEngine);
        $('#remove-links-btn').on('click', handleRemoveLinks);
        
        // Preview controls
        $('#load-preview-btn').on('click', handleLoadPreview);
        
        // Test connection
        $('#test-connection-btn').on('click', handleTestConnection);
        
        // Exclusions
        $('#add-post-exclusion').on('click', handleAddPostExclusion);
        $('#add-anchor-exclusion').on('click', handleAddAnchorExclusion);
        $(document).on('click', '.ssp-remove-exclusion', handleRemoveExclusion);
        
        // Troubleshoot
        $('#recreate-tables').on('click', handleRecreateTables);
        $('#check-tables').on('click', handleCheckTables);
        $('#view-debug-report').on('click', handleViewDebugReport);
        $('#download-debug-report').on('click', handleDownloadDebugReport);
        $('#clear-debug-logs').on('click', handleClearDebugLogs);
        
        // Anchor Report handlers
        $('#load-anchor-report, #refresh-anchor-report').on('click', handleLoadAnchorReport);
        $('#anchor-silo-filter, #anchor-status-filter').on('change', handleLoadAnchorReport);
        $('#anchor-settings-form').on('submit', handleSaveAnchorSettings);
        $('#export-anchor-report').on('click', handleExportAnchorReport);
        $(document).on('click', '.view-anchor-details', handleViewAnchorDetails);
        
        // Anchor Management handlers
        $('#anchor-mgmt-silo-select').on('change', function() {
            var siloId = $(this).val();
            $('#load-anchor-management').prop('disabled', !siloId);
        });
        $('#load-anchor-management').on('click', handleLoadAnchorManagement);
        $(document).on('click', '.get-ai-suggestions-btn', handleGetAISuggestions);
        $(document).on('click', '.select-anchor-suggestion', handleSelectAnchorSuggestion);
        
        // Close suggestions modal (use delegated handlers to prevent conflicts)
        $(document).on('click', '#anchor-suggestions-modal .ssp-modal-close', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#anchor-suggestions-modal').hide();
            $('#suggestions-list').hide().html('');
        });
        
        $(document).on('click', '#anchor-suggestions-modal .ssp-modal-dismiss', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#anchor-suggestions-modal').hide();
            $('#suggestions-list').hide().html('');
        });
        
        // Close on overlay click
        $(document).on('click', '#anchor-suggestions-modal', function(e) {
            if (e.target === this) {
                $('#anchor-suggestions-modal').hide();
                $('#suggestions-list').hide().html('');
            }
        });
        
        // Close on ESC key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#anchor-suggestions-modal').is(':visible')) {
                $('#anchor-suggestions-modal').hide();
                $('#suggestions-list').hide().html('');
            }
        });
        
        // Anchor Details Modal close
        function closeAnchorModal() {
                $('#anchor-details-modal').fadeOut();
            $(document).off('keydown.sspAnchorModal');
        }
        
        $(document).on('click', '#anchor-details-modal .ssp-modal-close', closeAnchorModal);
        $(document).on('click', '#anchor-details-modal .ssp-modal-dismiss', closeAnchorModal);
        $(document).on('click', '#anchor-details-modal', function(e) {
            if ($(e.target).is('#anchor-details-modal')) {
                closeAnchorModal();
            }
        });
        // ESC key to close anchor modal
        $(document).on('keydown.sspAnchorModal', function(e) {
            if (e.key === 'Escape' && $('#anchor-details-modal').is(':visible')) {
                closeAnchorModal();
            }
        });
        
        // Orphan Posts handlers
        $('#select-all-orphans, #select-all-orphans-btn').on('click', handleSelectAllOrphans);
        $('#deselect-all-orphans-btn').on('click', handleDeselectAllOrphans);
        $(document).on('change', '.orphan-post-checkbox', handleOrphanCheckboxChange);
        $('#assign-orphans-btn').on('click', handleAssignOrphanPosts);
        $('#assign-orphan-silo-select').on('change', handleOrphanCheckboxChange);
        $(document).on('change', '.assign-single-orphan-silo', handleSingleOrphanSiloChange);
        $(document).on('click', '.assign-single-orphan-btn', handleAssignSingleOrphan);
    }
    
    function handleCreateSilo(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var originalText = $submitBtn.text();
        
        $submitBtn.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        // Validate form data
        var setupMethod = $form.find('input[name="setup_method"]:checked').val();
        var pillarPosts = [];
        var selectedPillar = $form.find('input[name="pillar_post"]:checked').val();
        if (selectedPillar) {
            pillarPosts.push(selectedPillar);
        }
        
        if (!setupMethod) {
            showNotice('Please select a setup method', 'error');
            $submitBtn.prop('disabled', false).text(originalText);
            return;
        }
        
        // Validate pillar posts - allow no pillar only for manual method with enough supports
        if (pillarPosts.length === 0) {
            if (setupMethod === 'manual') {
                // Check if manual method has enough support posts and compatible linking mode
                var supportPosts = [];
                $form.find('input[name="support_posts[]"]:checked').each(function() {
                    supportPosts.push($(this).val());
                });
                var linkingMode = $form.find('input[name="linking_mode"]:checked').val();
                var compatibleNoPillarModes = ['linear', 'chained', 'cross_linking', 'ai_contextual', 'custom'];
                
                if (supportPosts.length < 2) {
                    showNotice('At least 2 support posts are required when no pillar is selected', 'error');
                    $submitBtn.prop('disabled', false).text(originalText);
                    return;
                }
                
                if (!compatibleNoPillarModes.includes(linkingMode)) {
                    showNotice('Selected linking mode requires a pillar post. Please select a compatible mode (Linear, Chained, Cross-linking, AI-Contextual, or Custom)', 'error');
                    $submitBtn.prop('disabled', false).text(originalText);
                    return;
                }
                // No pillar is allowed for manual method with valid conditions - continue
            } else {
                // AI and Category methods require pillar posts
            showNotice('Please select at least one pillar post', 'error');
            $submitBtn.prop('disabled', false).text(originalText);
            return;
            }
        }
        
        var formData = {
            action: 'ssp_create_silo',
            nonce: ssp_ajax.nonce,
            setup_method: setupMethod,
            linking_mode: $form.find('input[name="linking_mode"]:checked').val(),
            use_ai_anchors: $form.find('input[name="use_ai_anchors"]').is(':checked'),
            auto_link: $form.find('input[name="auto_link"]').is(':checked'),
            auto_update: $form.find('input[name="auto_update"]').is(':checked'),
            auto_assign_category: $form.find('input[name="auto_assign_category"]').is(':checked'),
            auto_assign_category_id: $form.find('select[name="auto_assign_category_id"]').val(),
            link_placement: $form.find('input[name="link_placement"]:checked').val(),
            supports_to_pillar: $form.find('input[name="supports_to_pillar"]').is(':checked'),
            pillar_to_supports: $form.find('input[name="pillar_to_supports"]').is(':checked'),
            max_pillar_links: $form.find('input[name="max_pillar_links"]').val(),
            max_contextual_links: $form.find('input[name="max_contextual_links"]').val()
        };
        
        // Add pillar post (single radio button - may be empty for no-pillar manual silos)
        if (pillarPosts.length > 0) {
            formData['pillar_post'] = pillarPosts[0];
        }
        
        // Add method-specific data
        var setupMethod = formData.setup_method;
        
        // For AI-Recommended, show recommendations first
        if (setupMethod === 'ai_recommended') {
            if (pillarPosts.length === 0) {
                showNotice('Please select at least one pillar post', 'error');
                $submitBtn.prop('disabled', false).text(originalText);
                return;
            }
            $submitBtn.prop('disabled', false).text(originalText);
            showAIRecommendations(pillarPosts, formData, $form);
            return;
        }
        
        if (setupMethod === 'category_based') {
            formData.category_id = $form.find('select[name="category_id"]').val();
        } else if (setupMethod === 'manual') {
            var supportPosts = [];
            $form.find('input[name="support_posts[]"]:checked').each(function() {
                supportPosts.push($(this).val());
            });
            if (supportPosts.length === 0) {
                showNotice('Please select at least one support post', 'error');
                $submitBtn.prop('disabled', false).text(originalText);
                return;
            }
            // Send as individual array elements for PHP to receive properly
            supportPosts.forEach(function(postId, index) {
                formData['support_posts[' + index + ']'] = postId;
            });
        }
        
        // Add custom pattern rules if custom mode is selected
        var linkingMode = $form.find('input[name="linking_mode"]:checked').val();
        if (linkingMode === 'custom') {
            var customRules = [];
            $('#custom-rules-list .custom-rule-row').each(function() {
                var source = $(this).find('.rule-source').val();
                var target = $(this).find('.rule-target').val();
                
                if (source && target) {
                    customRules.push({
                        source: source,
                        target: target
                    });
                }
            });
            
            formData.custom_source = customRules.map(function(r) { return r.source; });
            formData.custom_target = customRules.map(function(r) { return r.target; });
        }
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    var message = response.data.message || 'Silo created successfully!';
                    
                    // Show additional details if available
                    if (response.data.silos_created) {
                        message += ' (' + response.data.silos_created + ' silo(s))';
                    }
                    
                    showNotice(message, 'success');
                    $form[0].reset();
                    $('.ssp-method-specific').hide();
                    
                    // Refresh page to show new silo(s)
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to create silo', 'error');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Failed to create silo';
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.data) {
                        errorMessage = response.data;
                    }
                } catch (e) {
                    // Use default error message
                }
                showNotice(errorMessage, 'error');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text(originalText);
            }
        });
    }
    
    function handleDeleteSilo(e) {
        e.preventDefault();
        
        if (!confirm(ssp_ajax.strings.confirm_delete)) {
            return;
        }
        
        var $button = $(this);
        var siloId = $button.data('silo-id');
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_delete_silo',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Silo deleted successfully! Refreshing page...', 'success');
                    // Refresh page after short delay to show message
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data || 'Failed to delete silo', 'error');
                    $button.prop('disabled', false).text('Delete');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
                $button.prop('disabled', false).text('Delete');
            }
        });
    }
    
    function handleGenerateLinks(e) {
        e.preventDefault();
        
        var $button = $(this);
        var siloId = $('#silo-select').val();
        
        if (!siloId) {
            showNotice('Please select a silo first.', 'error');
            return;
        }
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_generate_links',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message || 'Links generated successfully!', 'success');
                    // Refresh page to show updated stats
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to generate links', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Generate Links');
            }
        });
    }
    
    function handleViewSilo(e) {
        e.preventDefault();
        
        var siloId = $(this).data('silo-id');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_silo_details',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success && response.data) {
                    displaySiloDetailsModal(response.data);
                } else {
                    showNotice(response.data || 'Failed to load silo details', 'error');
                }
            },
            error: function() {
                showNotice('Error loading silo details', 'error');
            }
        });
    }
    
    function displaySiloDetailsModal(siloData) {
        // Remove existing modal if any
        $('#ssp-silo-details-modal').remove();
        
        var modalHtml = '<div id="ssp-silo-details-modal" class="ssp-modal-overlay" role="dialog" aria-modal="true">';
        modalHtml +=   '<div class="ssp-modal-content ssp-silo-modal" tabindex="-1" aria-labelledby="ssp-silo-details-title">';
        // Header
        modalHtml +=     '<div class="ssp-modal-header">';
        modalHtml +=       '<h2 id="ssp-silo-details-title">📊 Silo Details</h2>';
        modalHtml +=       '<button type="button" class="ssp-modal-close" aria-label="Close">&times;</button>';
        modalHtml +=     '</div>';
        // Body
        modalHtml +=     '<div class="ssp-modal-body">';
        modalHtml +=       '<div class="silo-info">';
        modalHtml +=         '<h3>Basic Information</h3>';
        modalHtml +=         '<p><strong>Silo Name:</strong> ' + escapeHtml(siloData.name || '') + '</p>';
        modalHtml +=         '<p><strong>Pillar Post:</strong> ' + escapeHtml(siloData.pillar_title || 'N/A') + ' <span class="ssp-mono">(ID: ' + escapeHtml(siloData.pillar_id || 0) + ')</span></p>';
        modalHtml +=         '<p><strong>Setup Method:</strong> <span class="ssp-badge ssp-badge-info">' + escapeHtml(siloData.setup_method || '') + '</span></p>';
        modalHtml +=         '<p><strong>Linking Mode:</strong> <span class="ssp-badge ssp-badge-primary">' + escapeHtml(siloData.linking_mode || '') + '</span></p>';
        modalHtml +=         '<p><strong>Total Support Posts:</strong> ' + escapeHtml(siloData.posts ? siloData.posts.length : 0) + '</p>';
        modalHtml +=         '<p><strong>Total Links:</strong> ' + escapeHtml(siloData.total_links || 0) + '</p>';
        modalHtml +=         '<p><strong>Created:</strong> ' + escapeHtml(siloData.created_at || '') + '</p>';
        modalHtml +=       '</div>';
        
        // Display all settings
        if (siloData.settings) {
            modalHtml +=       '<div class="silo-settings">';
            modalHtml +=         '<h3>⚙️ All Settings</h3>';
            modalHtml +=         '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
            modalHtml +=           '<thead><tr><th style="width: 40%;">Setting</th><th>Value</th></tr></thead>';
            modalHtml +=           '<tbody>';
            
            // Format settings for display
            var settingsLabels = {
                'use_ai_anchors': 'Use AI for Anchor Text',
                'auto_link': 'Auto-link Posts',
                'auto_update': 'Auto-update on New Posts',
                'supports_to_pillar': 'Support Posts Link to Pillar',
                'pillar_to_supports': 'Pillar Links to Support Posts',
                'max_pillar_links': 'Max Pillar Links',
                'max_contextual_links': 'Max Contextual Links per Post',
                'placement_type': 'Link Placement',
                'auto_assign_category': 'Auto-assign to Category',
                'auto_assign_category_id': 'Category ID',
                'category_id': 'Category ID',
                'custom_pattern': 'Custom Linking Pattern'
            };
            
            var placementTypes = {
                'natural': 'Natural Link Insertion',
                'first_paragraph': 'First Paragraph Only'
            };
            
            function formatSettingValue(key, value) {
                if (value === true || value === 'true' || value === 1) return '✓ Yes';
                if (value === false || value === 'false' || value === 0) return '✗ No';
                if (key === 'placement_type' && placementTypes[value]) return placementTypes[value];
                if (key === 'auto_assign_category_id' && siloData.category_name) return value + ' (' + escapeHtml(siloData.category_name) + ')';
                if (key === 'category_id' && siloData.category_name) return value + ' (' + escapeHtml(siloData.category_name) + ')';
                if (key === 'custom_pattern' && Array.isArray(value)) {
                    if (value.length === 0) return 'None';
                    return value.length + ' rule(s) defined';
                }
                return escapeHtml(String(value));
            }
            
            // Display settings in a logical order
            var orderedKeys = [
                'use_ai_anchors',
                'auto_link',
                'auto_update',
                'placement_type',
                'supports_to_pillar',
                'pillar_to_supports',
                'max_pillar_links',
                'max_contextual_links',
                'auto_assign_category',
                'auto_assign_category_id',
                'category_id',
                'custom_pattern'
            ];
            
            orderedKeys.forEach(function(key) {
                if (siloData.settings.hasOwnProperty(key)) {
                    var label = settingsLabels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    var value = formatSettingValue(key, siloData.settings[key]);
                    modalHtml +=           '<tr>';
                    modalHtml +=             '<td><strong>' + escapeHtml(label) + '</strong></td>';
                    modalHtml +=             '<td>' + value + '</td>';
                    modalHtml +=           '</tr>';
                }
            });
            
            // Show custom pattern details if exists
            if (siloData.settings.custom_pattern && Array.isArray(siloData.settings.custom_pattern) && siloData.settings.custom_pattern.length > 0) {
                modalHtml +=           '<tr>';
                modalHtml +=             '<td colspan="2" style="padding: 10px 0;">';
                modalHtml +=               '<strong>Custom Pattern Rules:</strong>';
                modalHtml +=               '<ul style="margin: 10px 0 0 20px;">';
                siloData.settings.custom_pattern.forEach(function(rule) {
                    modalHtml +=           '<li>' + escapeHtml(rule.source || '') + ' → ' + escapeHtml(rule.target || '') + '</li>';
                });
                modalHtml +=               '</ul>';
                modalHtml +=             '</td>';
                modalHtml +=           '</tr>';
            }
            
            modalHtml +=           '</tbody>';
            modalHtml +=         '</table>';
            modalHtml +=       '</div>';
        }
        
        modalHtml +=       '<h3>Support Posts in This Silo</h3>';
        modalHtml +=       '<div class="ssp-table-tools">';
        modalHtml +=         '<label class="screen-reader-text" for="ssp-silo-posts-filter">Filter posts</label>';
        modalHtml +=         '<input id="ssp-silo-posts-filter" type="search" placeholder="Filter by title..." />';
        modalHtml +=       '</div>';
        modalHtml +=       '<table class="wp-list-table widefat fixed striped">';
        modalHtml +=         '<thead><tr><th>Post Title</th><th style="width: 110px;">Post ID</th><th style="width: 110px;">Links</th><th style="width: 160px;">Actions</th></tr></thead>';
        modalHtml +=         '<tbody>';
        
        if (siloData.posts && Array.isArray(siloData.posts)) {
            siloData.posts.forEach(function(post) {
                var postId = escapeHtml(post.id || '');
                var postTitle = escapeHtml(post.title || '');
                var linkCount = escapeHtml(post.link_count || 0);
                var adminBase = window.location.origin + '/wp-admin/';
                var editUrl = adminBase + 'post.php?post=' + postId + '&action=edit';
                var viewUrl = '/?p=' + postId;
                modalHtml +=         '<tr>';
                modalHtml +=           '<td><strong>' + postTitle + '</strong></td>';
                modalHtml +=           '<td class="ssp-mono">#' + postId + '</td>';
                modalHtml +=           '<td>' + linkCount + '</td>';
                modalHtml +=           '<td class="ssp-actions">' +
                                        '<a class="button button-small" href="' + editUrl + '" target="_blank" rel="noopener">Edit</a> ' +
                                        '<a class="button button-small" href="' + viewUrl + '" target="_blank" rel="noopener">View</a>' +
                                      '</td>';
                modalHtml +=         '</tr>';
            });
        }
        
        modalHtml +=         '</tbody></table>';
        modalHtml +=     '</div>'; // body
        // Footer
        modalHtml +=     '<div class="ssp-modal-footer">';
        modalHtml +=       '<button type="button" class="button button-primary ssp-modal-dismiss">Close</button>';
        modalHtml +=     '</div>';
        modalHtml +=   '</div>';
        modalHtml += '</div>';
        
        $('body').append(modalHtml);
        
        // Focus the modal content for accessibility
        var $modalContent = $('#ssp-silo-details-modal .ssp-modal-content');
        if ($modalContent.length) { $modalContent.focus(); }
        
        // Function to close modal and clean up handlers
        function closeModal() {
                $('#ssp-silo-details-modal').remove();
            $(document).off('click.sspSiloModal keydown.sspSiloModal');
        }
        
        // Close interactions - attach directly to modal elements
        $('#ssp-silo-details-modal .ssp-modal-close').on('click', closeModal);
        $('#ssp-silo-details-modal .ssp-modal-dismiss').on('click', closeModal);
        $('#ssp-silo-details-modal').on('click.sspSiloModal', function(e) {
            if ($(e.target).is('#ssp-silo-details-modal')) {
                closeModal();
            }
        });
        // ESC key to close
        $(document).on('keydown.sspSiloModal', function(e) {
            if (e.key === 'Escape' && $('#ssp-silo-details-modal').length) {
                closeModal();
            }
        });

        // Client-side filter for posts table
        $('#ssp-silo-posts-filter').on('input', function() {
            var query = $(this).val().toLowerCase();
            $('#ssp-silo-details-modal tbody tr').each(function() {
                var title = $(this).find('td').eq(0).text().toLowerCase();
                $(this).toggle(title.indexOf(query) !== -1);
            });
        });
    }
    
    function handlePreviewSilo(e) {
        e.preventDefault();
        
        var siloId = $(this).data('silo-id');
        
        // Open preview in new tab or modal
        window.open('?page=semantic-silo-pro&tab=preview&silo_id=' + siloId, '_blank');
    }
    
    function handleGenerateLinksEngine(e) {
        e.preventDefault();
        
        var $button = $(this);
        var siloId = $('#silo-select').val();
        
        if (!siloId) {
            showNotice('Please select a silo first', 'error');
            return;
        }
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_generate_links',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message || 'Links generated successfully! Refreshing page...', 'success');
                    // Refresh page to show updated link counts
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to generate links', 'error');
                    $button.prop('disabled', false).text('Generate Links');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
                $button.prop('disabled', false).text('Generate Links');
            }
        });
    }
    
    function handlePreviewLinksEngine(e) {
        e.preventDefault();
        
        var $button = $(this);
        var siloId = $('#silo-select').val();
        
        if (!siloId) {
            showNotice('Please select a silo first', 'error');
            return;
        }
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_preview_links',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    displayPreviewResults(response.data);
                } else {
                    showNotice(response.data || 'Failed to load preview', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Preview Links');
            }
        });
    }
    
    function handleRemoveLinks(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to remove all links for this silo?')) {
            return;
        }
        
        var $button = $(this);
        var siloId = $('#silo-select').val();
        
        if (!siloId) {
            showNotice('Please select a silo first', 'error');
            return;
        }
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_remove_links',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message || 'Links removed successfully! Refreshing page...', 'success');
                    // Refresh page after short delay to show updated state
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data || 'Failed to remove links', 'error');
                    $button.prop('disabled', false).text('Remove Links');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
                $button.prop('disabled', false).text('Remove Links');
            }
        });
    }
    
    function handleLoadPreview(e) {
        e.preventDefault();
        
        var $button = $(this);
        var siloId = $('#preview-silo-select').val();
        
        if (!siloId) {
            showNotice('Please select a silo first', 'error');
            return;
        }
        
        $button.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_preview_links',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                if (response.success) {
                    displayPreviewResults(response.data);
                } else {
                    showNotice(response.data || 'Failed to load preview', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Load Preview');
            }
        });
    }
    
    function handleTestConnection(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $result = $('#connection-result');
        
        $button.prop('disabled', true).text('Testing...');
        $result.html('');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_test_connection',
                nonce: ssp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="ssp-success">✓ ' + response.data + '</div>');
                } else {
                    $result.html('<div class="ssp-error">✗ ' + response.data + '</div>');
                }
            },
            error: function() {
                $result.html('<div class="ssp-error">✗ Connection test failed</div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Connection');
            }
        });
    }
    
    function handleAddPostExclusion(e) {
        e.preventDefault();
        
        var postId = $('#exclude-post-select').val();
        
        if (!postId) {
            showNotice('Please select a post to exclude', 'error');
            return;
        }
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_add_exclusion',
                nonce: ssp_ajax.nonce,
                item_type: 'post',
                item_value: postId
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Post excluded successfully!', 'success');
                    location.reload();
                } else {
                    showNotice(response.data || 'Failed to exclude post', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            }
        });
    }
    
    function handleAddAnchorExclusion(e) {
        e.preventDefault();
        
        var anchorText = $('#exclude-anchor-input').val().trim();
        
        if (!anchorText) {
            showNotice('Please enter anchor text to exclude', 'error');
            return;
        }
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_add_exclusion',
                nonce: ssp_ajax.nonce,
                item_type: 'anchor',
                item_value: anchorText
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Anchor text excluded successfully!', 'success');
                    $('#exclude-anchor-input').val('');
                    location.reload();
                } else {
                    showNotice(response.data || 'Failed to exclude anchor text', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            }
        });
    }
    
    function handleRemoveExclusion(e) {
        e.preventDefault();
        
        var $button = $(this);
        var itemType = $button.data('type');
        var itemValue = $button.data('value');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_remove_exclusion',
                nonce: ssp_ajax.nonce,
                item_type: itemType,
                item_value: itemValue
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Exclusion removed successfully!', 'success');
                    $button.closest('.ssp-excluded-item').fadeOut();
                } else {
                    showNotice(response.data || 'Failed to remove exclusion', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            }
        });
    }
    
    function displayPreviewResults(data) {
        var $results = $('#preview-results, #link-results');
        var html = '<div class="ssp-preview-results">';
        
        if (!data || Object.keys(data).length === 0) {
            html += '<p class="ssp-no-results">No preview data available for this silo.</p>';
        } else {
            html += '<h3>📋 Link Preview</h3>';
            html += '<p class="description">Below are the links that will be created when you click "Generate Links"</p>';
            
            var totalLinks = 0;
            $.each(data, function(postId, previews) {
                if (previews && previews.length > 0) {
                    totalLinks += previews.length;
                    html += '<div class="ssp-post-preview">';
                    html += '<h4>📄 ' + getPostTitle(postId) + '</h4>';
                    html += '<ul class="ssp-preview-links">';
                    
                    $.each(previews, function(index, preview) {
                        html += '<li style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 3px solid #0073aa;">';
                        html += '<strong>🔗 Links to:</strong> ' + preview.target_title + '<br>';
                        html += '<strong>⚓ Anchor Text:</strong> <code style="background: #fff; padding: 2px 6px;">' + preview.anchor_text + '</code>';
                        
                        // Show anchor variations if available
                        if (preview.anchor_variations && preview.anchor_variations.length > 1) {
                            html += '<br><span style="color: #666; font-size: 12px;">Variations: ';
                            preview.anchor_variations.forEach(function(variation, i) {
                                if (i > 0) html += ', ';
                                html += '<code style="background: #fff; padding: 2px 4px; font-size: 11px;">' + variation + '</code>';
                            });
                            html += '</span>';
                        }
                        
                        html += '<br><strong>📍 Insertion:</strong> <span style="color: #666;">' + preview.insertion_point + '</span>';
                        html += '</li>';
                    });
                    
                    html += '</ul>';
                    html += '</div>';
                }
            });
            
            html += '<p style="margin-top: 20px; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 3px;">';
            html += '<strong>✅ Total Links:</strong> ' + totalLinks + ' link' + (totalLinks !== 1 ? 's' : '') + ' will be created';
            html += '</p>';
        }
        
        html += '</div>';
        $results.html(html).show();
    }
    
    function getPostTitle(postId) {
        // Try to get title from existing data or return placeholder
        var $option = $('select option[value="' + postId + '"]');
        if ($option.length) {
            return $option.text();
        }
        return 'Post ID: ' + postId;
    }
    
    function handleAddCustomRule(e) {
        e.preventDefault();
        addCustomRule('', '');
    }
    
    function handleRemoveCustomRule(e) {
        e.preventDefault();
        
        var $rule = $(this).closest('.custom-rule-row');
        if ($('#custom-rules-list .custom-rule-row').length > 1) {
            $rule.remove();
        } else {
            showNotice('You must have at least one rule', 'error');
        }
    }
    
    function handleSavePattern(e) {
        e.preventDefault();
        
        var patternName = $('#pattern-name-input').val().trim();
        
        if (!patternName) {
            showNotice('Please enter a pattern name', 'error');
            return;
        }
        
        // Collect all custom rules
        var rules = [];
        $('#custom-rules-list .custom-rule-row').each(function() {
            var source = $(this).find('.rule-source').val();
            var target = $(this).find('.rule-target').val();
            
            if (source && target) {
                rules.push({
                    source: source,
                    target: target
                });
            }
        });
        
        if (rules.length === 0) {
            showNotice('Please add at least one rule before saving', 'error');
            return;
        }
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_save_pattern',
                nonce: ssp_ajax.nonce,
                pattern_name: patternName,
                pattern_rules: JSON.stringify(rules)
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Pattern "' + patternName + '" saved successfully!', 'success');
                    $('#pattern-name-input').val('');
                    // Reload page to update dropdown
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to save pattern', 'error');
                }
            },
            error: function() {
                showNotice('Error saving pattern', 'error');
            }
        });
    }
    
    function handleLoadPattern(e) {
        e.preventDefault();
        
        var patternName = $('#load-saved-pattern').val();
        
        if (!patternName) {
            showNotice('Please select a pattern to load', 'error');
            return;
        }
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_load_pattern',
                nonce: ssp_ajax.nonce,
                pattern_name: patternName
            },
            success: function(response) {
                if (response.success && response.data.pattern) {
                    // Clear existing rules
                    $('#custom-rules-list').empty();
                    
                    // Add loaded rules
                    var rules = response.data.pattern.rules;
                    rules.forEach(function(rule) {
                        addCustomRule(rule.source, rule.target);
                    });
                    
                    showNotice('Pattern "' + patternName + '" loaded successfully!', 'success');
                } else {
                    showNotice(response.data || 'Failed to load pattern', 'error');
                }
            },
            error: function() {
                showNotice('Error loading pattern', 'error');
            }
        });
    }
    
    function addCustomRule(source, target) {
        var ruleHtml = '<div class="custom-rule-row" style="margin-bottom: 10px;">';
        ruleHtml += '<input type="text" class="rule-source" placeholder="Source (e.g., pillar, 123)" value="' + (source || '') + '" style="width: 150px; margin-right: 10px;">';
        ruleHtml += ' → ';
        ruleHtml += '<input type="text" class="rule-target" placeholder="Target (e.g., pillar, 456)" value="' + (target || '') + '" style="width: 150px; margin-right: 10px;">';
        ruleHtml += '<button type="button" class="button ssp-remove-rule">Remove</button>';
        ruleHtml += '</div>';
        
        $('#custom-rules-list').append(ruleHtml);
    }
    
    function handleRecreateTables(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure? This will delete ALL existing silos and links!')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#recreate-tables-result');
        
        $button.prop('disabled', true).text('Recreating...');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_recreate_tables',
                nonce: ssp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>AJAX Error</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Recreate Tables');
            }
        });
    }
    
    function handleCheckTables(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $result = $('#check-tables-result');
        
        $button.prop('disabled', true).text('Checking...');
        $result.html('<p>Checking database tables...</p>');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_check_tables',
                nonce: ssp_ajax.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text('Check Tables');
                
                if (response.success) {
                    var noticeClass = response.data.all_exist ? 'notice-success' : 'notice-warning';
                    var html = '<div class="notice ' + noticeClass + '"><p>' + response.data.message + '</p>';
                    
                    html += '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>Table Name</th><th>Status</th></tr></thead><tbody>';
                    response.data.tables.forEach(function(table) {
                        var statusColor = table.exists ? 'green' : 'red';
                        var statusIcon = table.exists ? '✅' : '❌';
                        html += '<tr><td>' + table.table + '</td><td style="color: ' + statusColor + '; font-weight: bold;">' + statusIcon + ' ' + table.status + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                    
                    $result.html(html);
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Failed to check tables') + '</p></div>');
                }
            },
            error: function() {
                $button.prop('disabled', false).text('Check Tables');
                $result.html('<div class="notice notice-error"><p>Error checking tables</p></div>');
            }
        });
    }
    
    function handleViewDebugReport(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $container = $('#debug-report-container');
        
        $button.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_debug_report',
                nonce: ssp_ajax.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text('📋 View Debug Report');
                
                if (response.success && response.data.logs) {
                    var logs = response.data.logs;
                    var html = '<div class="debug-report-table">';
                    html += '<table class="wp-list-table widefat striped">';
                    html += '<thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>';
                    html += '<tbody>';
                    
                    if (logs.length > 0) {
                        logs.forEach(function(log) {
                            var levelClass = 'log-' + log.level.toLowerCase();
                            html += '<tr class="' + levelClass + '">';
                            html += '<td style="white-space: nowrap;">' + log.created_at + '</td>';
                            html += '<td><span class="log-badge log-badge-' + log.level.toLowerCase() + '">' + log.level.toUpperCase() + '</span></td>';
                            html += '<td>' + log.message + '</td>';
                            html += '<td style="font-size: 11px; color: #666;">' + (log.context || '-') + '</td>';
                            html += '</tr>';
                        });
                    } else {
                        html += '<tr><td colspan="4">No logs found</td></tr>';
                    }
                    
                    html += '</tbody></table>';
                    html += '</div>';
                    
                    $container.html(html).slideDown();
                } else {
                    showNotice('No logs found', 'info');
                }
            },
            error: function() {
                $button.prop('disabled', false).text('📋 View Debug Report');
                showNotice('Error loading debug report', 'error');
            }
        });
    }
    
    function handleDownloadDebugReport(e) {
        e.preventDefault();
        
        // Open download in new window
        var url = ssp_ajax.ajax_url + '?action=ssp_download_debug_report&nonce=' + ssp_ajax.nonce;
        window.open(url, '_blank');
        showNotice('Debug report download started', 'success');
    }
    
    function handleClearDebugLogs(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to clear all debug logs? This cannot be undone.')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Clearing...');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_clear_debug_logs',
                nonce: ssp_ajax.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text('🗑️ Clear All Logs');
                
                if (response.success) {
                    showNotice('Debug logs cleared successfully', 'success');
                    $('#debug-report-container').slideUp().html('');
                } else {
                    showNotice(response.data || 'Failed to clear logs', 'error');
                }
            },
            error: function() {
                $button.prop('disabled', false).text('🗑️ Clear All Logs');
                showNotice('Error clearing logs', 'error');
            }
        });
    }
    
    function showNotice(message, type) {
        // Remove any existing alerts first
        $('.ssp-alert').remove();
        
        // Map WordPress notice types to alert styles
        var alertClass = 'ssp-alert-' + type;
        var icons = {
            'success': '✓',
            'error': '✕',
            'warning': '⚠',
            'info': 'ℹ'
        };
        var icon = icons[type] || icons['info'];
        
        // Create fixed-position alert (toast notification)
        var $notice = $('<div class="ssp-alert ' + alertClass + '">' +
            '<div class="ssp-alert-content">' +
            '<span class="ssp-alert-icon">' + icon + '</span>' +
            '<span class="ssp-alert-message">' + message + '</span>' +
            '<button type="button" class="ssp-alert-close" aria-label="Close">&times;</button>' +
            '</div>' +
            '</div>');
        
        // Append to body for fixed positioning
        $('body').append($notice);
        
        // Animate in after a brief delay
        setTimeout(function() {
            $notice.addClass('ssp-alert-show');
        }, 10);
        
        // Close button handler
        $notice.find('.ssp-alert-close').on('click', function() {
            $notice.removeClass('ssp-alert-show');
            setTimeout(function() {
                $notice.remove();
            }, 300);
        });
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            if ($notice.hasClass('ssp-alert-show')) {
                $notice.removeClass('ssp-alert-show');
                setTimeout(function() {
                    $notice.remove();
                }, 300);
            }
        }, 5000);
    }
    
    /**
     * Show AI recommendations modal for user approval
     */
    function showAIRecommendations(pillarPosts, formData, $form) {
        // Show loading
        showNotice('Getting AI recommendations...', 'info');
        
        // Prepare data for AI recommendation request
        var recData = {
            action: 'ssp_get_ai_recommendations',
            nonce: ssp_ajax.nonce
        };
        
        pillarPosts.forEach(function(postId, index) {
            recData['pillar_post_ids[' + index + ']'] = postId;
        });
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: recData,
            success: function(response) {
                if (response.success && response.data.recommendations) {
                    // Show warnings if any
                    if (response.data.warnings && response.data.warnings.length > 0) {
                        showNotice('Warning: ' + response.data.warnings.join('. '), 'warning');
                    }
                    displayAIRecommendationsModal(response.data.recommendations, formData, $form);
                } else {
                    showNotice(response.data || 'Failed to get AI recommendations', 'error');
                }
            },
            error: function() {
                showNotice('Error getting AI recommendations', 'error');
            }
        });
    }
    
    /**
     * Display AI recommendations modal with approve/reject options
     */
    function displayAIRecommendationsModal(recommendations, formData, $form) {
        // Remove existing modal if any
        $('#ssp-ai-modal').remove();

        // Remove ALL old handlers
        $(document).off('click', '#approve-ai-recommendations');
        $('#approve-ai-recommendations').off('click');

        // Lock background scrolling
        $('body').addClass('ssp-modal-open');

        var modalHtml = '<div id="ssp-ai-modal" class="ssp-modal-overlay">';
        modalHtml += '<div class="ssp-modal-content">';
        modalHtml += '<span class="ssp-modal-close">&times;</span>';
        modalHtml += '<h2>🤖 AI Recommendations - Review & Approve</h2>';
        modalHtml += '<div class="ssp-modal-body">';
        modalHtml += '<p>Select which posts to include in your silo(s):</p>';

        recommendations.forEach(function(rec) {
            modalHtml += '<div class="ssp-recommendation-group">';
            modalHtml += '<h3>📄 Pillar: ' + rec.pillar_title + '</h3>';
            modalHtml += '<div class="ssp-recommendations-list">';

            console.log("AI rec block:", rec);


            if (rec.recommendations && rec.recommendations.length > 0) {
                
                console.log("Rec posts:", rec.recommendations);
                    
                modalHtml += '<table class="wp-list-table widefat">';
                modalHtml += '<thead><tr>';
                modalHtml += '<th style="width:40px;"><input type="checkbox" class="select-all-recs" data-pillar="' + rec.pillar_id + '" checked></th>';
                modalHtml += '<th>Post Title</th>';
                modalHtml += '<th style="width:100px;">Relevance</th>';
                modalHtml += '<th style="width:40%;">Excerpt</th>';
                modalHtml += '</tr></thead><tbody>';

                rec.recommendations.forEach(function(post) {
                    var relevancePercent = Math.round(post.relevance * 100);
                    var relevanceClass = relevancePercent >= 80 ? 'high' :
                                        relevancePercent >= 60 ? 'medium' : 'low';

                    modalHtml += '<tr>';
                    modalHtml += '<td><input type="checkbox" class="ai-rec-checkbox" data-pillar="' + rec.pillar_id + '" data-post="' + post.id + '" checked></td>';
                    modalHtml += '<td><strong>' + post.title + '</strong></td>';
                    modalHtml += '<td><span class="relevance-badge relevance-' + relevanceClass + '">' + relevancePercent + '%</span></td>';
                    modalHtml += '<td style="font-size:12px;color:#666;">' + post.excerpt + '</td>';
                    modalHtml += '</tr>';
                });

                modalHtml += '</tbody></table>';
            } else {
                modalHtml += '<p>No recommendations found for this pillar.</p>';
            }

            modalHtml += '</div></div>';
        });

        modalHtml += '</div>'; // END modal-body

        modalHtml += '<div class="ssp-modal-actions">';
        modalHtml += '<button id="approve-ai-recommendations" class="button button-primary button-large">✓ Approve & Create Silo</button>';
        modalHtml += '<button id="cancel-ai-recommendations" class="button button-large">Cancel</button>';
        modalHtml += '</div>';

        modalHtml += '</div></div>';

        jQuery('#wpwrap').append(modalHtml);

        // Select all checkboxes in a pillar
        $(document).on('change', '.select-all-recs', function() {
            var pid = $(this).data('pillar');
            $('.ai-rec-checkbox[data-pillar="' + pid + '"]').prop('checked', $(this).is(':checked'));
        });

        // Approve
        $('#approve-ai-recommendations').off('click').on('click', function (e) {
            e.preventDefault();

            const $btn = $(this);

            // Disable button + show loading
            $btn.prop('disabled', true).text('Processing...');

            createSiloWithApprovedRecommendations(
                recommendations,
                formData,
                $form,
                function onSuccess() {
                    // Close modal after success
                    $('body').removeClass('ssp-modal-open');
                    $('#ssp-ai-modal').remove();
                },
                function onFail() {
                    // Re-enable on failure
                    $btn.prop('disabled', false).text('Approve & Create Silo');
                }
            );
        });

        // Cancel/close
        $(document).on('click', '#cancel-ai-recommendations, .ssp-modal-close', function() {
            $('body').removeClass('ssp-modal-open');
            $('#ssp-ai-modal').remove();
        });
        
        // Background click
        $(document).on('click', '#ssp-ai-modal', function(e) {
            if (e.target === this) {
                $('body').removeClass('ssp-modal-open');
                $('#ssp-ai-modal').remove();
            }
        });

        return true;
    }
    
    /**
     * Create silo with user-approved AI recommendations
     */
    function createSiloWithApprovedRecommendations(
	recommendations,
	 formData,
	 $form,
	 onSuccess,
	 onFail
	) {
		var approvedPosts = {};

		// Collect selected posts from modal
		$('.ai-rec-checkbox:checked').each(function () {
			var pillarId = $(this).data('pillar');
			var postId = $(this).data('post');

			if (!approvedPosts[pillarId]) {
				approvedPosts[pillarId] = [];
			}
			approvedPosts[pillarId].push(postId);
		});

		// If nothing selected → fail early
		if (Object.keys(approvedPosts).length === 0) {
			showNotice(
				'Please select at least one post to include.',
				'error'
			);
			if (onFail) onFail();
			return false;
		}

		// Add AI-approved posts into form data
		formData.approved_recommendations = JSON.stringify(approvedPosts);

		// 🔥 CRITICAL FIX — Merge ALL form fields exactly like normal Create Silo
		// This includes radio buttons, checkboxes, hidden fields and drop-downs.
		$form.serializeArray().forEach(function (item) {
			if (typeof formData[item.name] === 'undefined') {
				formData[item.name] = item.value;
			}
		});

		// Also include unchecked checkboxes that serializeArray ignores
		$form.find('input[type="checkbox"]').each(function () {
			var name = $(this).attr('name');
			if (name && typeof formData[name] === 'undefined') {
				formData[name] = $(this).is(':checked') ? '1' : '0';
			}
		});

		// Disable main Create Silo button
		var $submitBtn = $form.find('button[type="submit"]');
		var originalText = $submitBtn.text();
		$submitBtn.prop('disabled', true).text('Processing...');

		// 🚀 AJAX — identical to Category-based silo creation
		$.ajax({
			url: ssp_ajax.ajax_url,
			type: 'POST',
			data: formData,

			success: function (response) {
				// Reset button
				$submitBtn.prop('disabled', false).text(originalText);

				if (response.success) {
					showNotice('Silo created successfully!', 'success');

					// Close modal (now correct timing)
					if (onSuccess) onSuccess();

					// Refresh to show new silo
					setTimeout(function () {
						location.reload();
					}, 800);
				} else {
					showNotice(response.data || 'Failed to create silo.', 'error');
					if (onFail) onFail();
				}
			},

			error: function () {
				$submitBtn.prop('disabled', false).text(originalText);
				showNotice('Error creating silo.', 'error');
				if (onFail) onFail();
			}
		});

		return true;
	}



    
    /**
     * Handle load anchor report
     */
    function handleLoadAnchorReport(e) {
        if (e) e.preventDefault();
        
        var siloId = $('#anchor-silo-filter').val();
        var statusFilter = $('#anchor-status-filter').val();
        
        $('#anchor-report-tbody').html('<tr><td colspan="7" style="text-align: center; padding: 20px;">Loading...</td></tr>');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_anchor_report',
                nonce: ssp_ajax.nonce,
                silo_id: siloId || '',
                status_filter: statusFilter || 'all'
            },
            success: function(response) {
                if (response.success) {
                    displayAnchorReport(response.data);
                } else {
                    showNotice(response.data || 'Failed to load anchor report', 'error');
                    $('#anchor-report-tbody').html('<tr><td colspan="7" style="text-align: center; padding: 20px;">Error loading data</td></tr>');
                }
            },
            error: function() {
                showNotice('Error loading anchor report', 'error');
                $('#anchor-report-tbody').html('<tr><td colspan="7" style="text-align: center; padding: 20px;">Error loading data</td></tr>');
            }
        });
    }
    
    /**
     * Display anchor report
     */
    function displayAnchorReport(data) {
        // Update stats
        $('#total-anchors-count').text(data.stats.total);
        $('#healthy-anchors-count').text(data.stats.healthy);
        $('#warning-anchors-count').text(data.stats.warning);
        $('#danger-anchors-count').text(data.stats.danger);
        
        // Build table
        var html = '';
        
        if (data.anchors.length === 0) {
            html = '<tr><td colspan="7" style="text-align: center; padding: 20px;">No anchor data found. Create some links first!</td></tr>';
        } else {
            data.anchors.forEach(function(anchor) {
                var statusIcon = '';
                var statusColor = '';
                
                if (anchor.status === 'good') {
                    statusIcon = '✅';
                    statusColor = '#28a745';
                } else if (anchor.status === 'warning') {
                    statusIcon = '⚠️';
                    statusColor = '#ffc107';
                } else {
                    statusIcon = '🔴';
                    statusColor = '#dc3545';
                }
                
                var healthBarColor = anchor.health_score > 70 ? '#28a745' : (anchor.health_score > 40 ? '#ffc107' : '#dc3545');
                var healthBarWidth = Math.max(20, anchor.health_score); // Minimum 20% width for visibility
                
                html += '<tr>';
                html += '<td style="text-align: center; font-size: 20px;">' + statusIcon + '</td>';
                html += '<td><strong>' + escapeHtml(anchor.anchor_text) + '</strong></td>';
                html += '<td style="text-align: center;"><span style="background: ' + statusColor + '; color: white; padding: 3px 10px; border-radius: 3px; font-weight: bold;">' + anchor.usage_count + '</span></td>';
                html += '<td style="text-align: center;">' + anchor.percentage + '%</td>';
                html += '<td><div style="background: #f0f0f0; border-radius: 5px; height: 20px; overflow: hidden; position: relative;"><div style="background: ' + healthBarColor + '; width: ' + anchor.health_score + '%; height: 100%;"></div><span style="position: absolute; top: 0; left: 0; right: 0; text-align: center; line-height: 20px; font-size: 11px; font-weight: bold; color: #333;">' + anchor.health_score + '%</span></div></td>';
                html += '<td style="text-align: center;">' + anchor.post_count + ' post' + (anchor.post_count !== 1 ? 's' : '') + '</td>';
                html += '<td style="text-align: center;"><button class="button button-small view-anchor-details" data-anchor="' + escapeHtml(anchor.anchor_text) + '">View Details</button></td>';
                html += '</tr>';
            });
        }
        
        $('#anchor-report-tbody').html(html);
    }
    
    /**
     * Handle view anchor details
     */
    function handleViewAnchorDetails(e) {
        e.preventDefault();
        
        var anchorText = $(this).data('anchor');
        var siloId = $('#anchor-silo-filter').val();
        
        $('#anchor-modal-title').text('Details for: "' + anchorText + '"');
        $('#anchor-modal-body').html('<p style="text-align: center; padding: 20px;">Loading...</p>');
        $('#anchor-details-modal').fadeIn();
        
        // Scroll modal to center of viewport
        setTimeout(function() {
            var $modal = $('#anchor-details-modal');
            if ($modal.length) {
                var $modalContent = $modal.find('.ssp-modal-content');
                if ($modalContent.length) {
                    $modalContent.focus();
                    // Ensure modal is centered
                    $('html, body').animate({
                        scrollTop: 0
                    }, 100);
                }
            }
        }, 50);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_anchor_details',
                nonce: ssp_ajax.nonce,
                anchor_text: anchorText,
                silo_id: siloId || ''
            },
            success: function(response) {
                if (response.success) {
                    displayAnchorDetails(response.data);
                } else {
                    $('#anchor-modal-body').html('<p style="color: red;">Error loading details</p>');
                }
            },
            error: function() {
                $('#anchor-modal-body').html('<p style="color: red;">Error loading details</p>');
            }
        });
    }
    
    /**
     * Display anchor details in modal
     */
    function displayAnchorDetails(data) {
        if (!data) {
            $('#anchor-modal-body').html('<p style="color: red;">Error: No data received</p>');
            return;
        }
        
        var html = '<h3>Anchor: "' + escapeHtml(data.anchor_text || '') + '"</h3>';
        html += '<p><strong>Total Usage:</strong> ' + escapeHtml(data.total || 0) + ' times</p>';
        html += '<hr>';
        html += '<h4>Usage Details:</h4>';
        html += '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>From Post</th><th>To Post</th><th>Silo</th><th>Created</th></tr></thead>';
        html += '<tbody>';
        
        if (data.details && Array.isArray(data.details) && data.details.length > 0) {
            data.details.forEach(function(detail) {
                if (!detail) return;
                // Validate and sanitize URLs (don't HTML-escape URLs for href attributes)
                var sourceUrl = (detail.source_post_url && typeof detail.source_post_url === 'string') ? detail.source_post_url.replace(/[<>]/g, '') : '#';
                var targetUrl = (detail.target_post_url && typeof detail.target_post_url === 'string') ? detail.target_post_url.replace(/[<>]/g, '') : '#';
                html += '<tr>';
                html += '<td><a href="' + sourceUrl + '" target="_blank" rel="noopener">' + escapeHtml(detail.source_post_title || '') + '</a></td>';
                html += '<td><a href="' + targetUrl + '" target="_blank" rel="noopener">' + escapeHtml(detail.target_post_title || '') + '</a></td>';
                html += '<td>' + (detail.silo_name ? escapeHtml(detail.silo_name) : '<em>Deleted Silo</em>') + '</td>';
                html += '<td>' + escapeHtml(detail.created_at || '') + '</td>';
                html += '</tr>';
            });
        } else {
            html += '<tr><td colspan="4" style="text-align: center; padding: 20px;">No usage found</td></tr>';
        }
        
        html += '</tbody></table>';
        
        $('#anchor-modal-body').html(html);
    }
    
    /**
     * Handle save anchor settings
     */
    function handleSaveAnchorSettings(e) {
        e.preventDefault();
        
        var $form = $(this);
        var warningThreshold = $('#warning-threshold').val();
        var maxUsage = $('#max-usage').val();
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_save_anchor_settings',
                nonce: ssp_ajax.nonce,
                warning_threshold: warningThreshold,
                max_usage_per_anchor: maxUsage
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Settings saved! Refreshing report...', 'success');
                    // Reload report with new settings
                    setTimeout(handleLoadAnchorReport, 1000);
                } else {
                    showNotice(response.data || 'Failed to save settings', 'error');
                }
            },
            error: function() {
                showNotice('Error saving settings', 'error');
            }
        });
    }
    
    /**
     * Handle export anchor report
     */
    function handleExportAnchorReport(e) {
        e.preventDefault();
        
        var siloId = $('#anchor-silo-filter').val();
        
        $(this).prop('disabled', true).text('Exporting...');
        
        var $btn = $(this);
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_export_anchor_report',
                nonce: ssp_ajax.nonce,
                silo_id: siloId || ''
            },
            success: function(response) {
                if (response.success) {
                    // Convert to CSV and download
                    var csv = response.data.csv_data.map(function(row) {
                        return row.map(function(cell) {
                            return '"' + String(cell).replace(/"/g, '""') + '"';
                        }).join(',');
                    }).join('\n');
                    
                    var blob = new Blob([csv], { type: 'text/csv' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'anchor-report-' + Date.now() + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    
                    showNotice('Report exported successfully!', 'success');
                } else {
                    showNotice(response.data || 'Failed to export report', 'error');
                }
                
                $btn.prop('disabled', false).text('📥 Export CSV');
            },
            error: function() {
                showNotice('Error exporting report', 'error');
                $btn.prop('disabled', false).text('📥 Export CSV');
            }
        });
    }
    
    /**
     * Handle select all orphans
     */
    function handleSelectAllOrphans(e) {
        e.preventDefault();
        $('.orphan-post-checkbox').prop('checked', true);
        handleOrphanCheckboxChange();
    }
    
    /**
     * Handle deselect all orphans
     */
    function handleDeselectAllOrphans(e) {
        e.preventDefault();
        $('.orphan-post-checkbox').prop('checked', false);
        handleOrphanCheckboxChange();
    }
    
    /**
     * Handle orphan checkbox change
     */
    function handleOrphanCheckboxChange() {
        var checkedCount = $('.orphan-post-checkbox:checked').length;
        var hasSiloSelected = $('#assign-orphan-silo-select').val() !== '';
        
        $('#assign-orphans-btn').prop('disabled', checkedCount === 0 || !hasSiloSelected);
        
        // Update select all checkbox
        var totalCheckboxes = $('.orphan-post-checkbox').length;
        $('#select-all-orphans').prop('checked', checkedCount === totalCheckboxes && totalCheckboxes > 0);
    }
    
    /**
     * Handle assign orphan posts
     */
    function handleAssignOrphanPosts(e) {
        e.preventDefault();
        
        var $button = $(this);
        var siloId = $('#assign-orphan-silo-select').val();
        var selectedPosts = [];
        
        $('.orphan-post-checkbox:checked').each(function() {
            selectedPosts.push($(this).val());
        });
        
        if (!siloId) {
            showNotice('Please select a silo first', 'error');
            return;
        }
        
        if (selectedPosts.length === 0) {
            showNotice('Please select at least one post', 'error');
            return;
        }
        
        if (!confirm('Assign ' + selectedPosts.length + ' post(s) to selected silo?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Assigning...');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_assign_orphan_posts',
                nonce: ssp_ajax.nonce,
                silo_id: siloId,
                post_ids: selectedPosts
            },
            success: function(response) {
                $button.prop('disabled', false).text('Assign Selected Posts');
                
                if (response.success) {
                    var message = response.data.message || 'Posts assigned successfully!';
                    showNotice(message, 'success');

                    // Count how many rows will be removed
                    var rowsToRemove = 0;
                    selectedPosts.forEach(function(id) {
                        var $row = $('tr[data-post-id="' + id + '"]');
                        if ($row.length) {
                            rowsToRemove++;
                        }
                    });

                    // Use server-confirmed count if available, otherwise use visible rows
                    var confirmedAdded = (response.data && typeof response.data.posts_added !== 'undefined')
                        ? parseInt(response.data.posts_added, 10) : NaN;
                    
                    var decrementBy = !isNaN(confirmedAdded) ? confirmedAdded : rowsToRemove;
                    
                    // Update counter immediately with estimated count (optimistic update)
                    if (decrementBy > 0) {
                        updateOrphanStats(-decrementBy);
                    }
                    
                    // Fetch authoritative count from server AFTER a delay to ensure DB transaction is committed
                    setTimeout(function() {
                        $.ajax({
                            url: ssp_ajax.ajax_url,
                            type: 'POST',
                            data: { 
                                action: 'ssp_get_orphan_count', 
                                nonce: ssp_ajax.nonce 
                            },
                            success: function(r) {
                                if (r && r.success && r.data && typeof r.data.total !== 'undefined') {
                                    var $counter = $('#ssp-orphan-total');
                                    if ($counter.length) {
                                        $counter.text(parseInt(r.data.total, 10));
                                        // Force repaint
                                        if ($counter[0]) {
                                            $counter[0].offsetHeight;
                                        }
                                    }
                                    // Update total hint if present
                                    var $hint = $('.ssp-orphan-posts .description:contains("Showing first 100 orphan posts")');
                                    if ($hint.length) {
                                        $hint.html('⚠️ Showing first 100 orphan posts. Total: ' + parseInt(r.data.total, 10) + ' posts.');
                                    }
                                }
                            },
                            error: function() {
                                // Silent fail - optimistic update should be enough
                                console.warn('Failed to fetch authoritative orphan count');
                            }
                        });
                    }, 300); // Wait 300ms for DB transaction to commit

                    // Remove assigned rows without full reload
                    if (rowsToRemove === 0) {
                        // No rows found in DOM, just reset controls
                        $('.orphan-post-checkbox').prop('checked', false);
                        handleOrphanCheckboxChange();
                    } else {
                        var rowsRemoved = 0;
                        selectedPosts.forEach(function(id) {
                            var $row = $('tr[data-post-id="' + id + '"]');
                            if ($row.length) {
                                $row.fadeOut(200, function() {
                                    $(this).remove();
                                    rowsRemoved++;
                                    
                                    // Check if all rows are removed
                                    if (rowsRemoved === rowsToRemove) {
                                        // Reset controls
                                        $('.orphan-post-checkbox').prop('checked', false);
                                        handleOrphanCheckboxChange();
                                        
                                        // Wait a tick to ensure DOM and counter are updated, then check for empty state
                                        setTimeout(function() {
                                            var remainingRows = $('#orphan-posts-tbody tr[data-post-id]').length;
                                            // Remove empty section headers
                                            $('#orphan-posts-tbody tr.ssp-section-header').each(function() {
                                                var $header = $(this);
                                                var $range = $header.nextUntil('tr.ssp-section-header');
                                                if ($range.filter('tr[data-post-id]').length === 0) {
                                                    $header.remove();
                                                }
                                            });
                                            var $counter = $('#ssp-orphan-total');
                                            var counterValue = 0;
                                            if ($counter.length) {
                                                var counterText = $counter.text().trim().replace(/[^\d]/g, '');
                                                counterValue = parseInt(counterText, 10) || 0;
                                            }
                                            
                                            if (remainingRows === 0 && counterValue === 0) {
                                                var $tableSection = $('#orphan-posts-tbody').closest('.ssp-form-section');
                                                if ($tableSection.length) {
                                                    $tableSection.html(
                                                        '<div class="ssp-empty-state"><p>🎉 Great! No orphan posts found. All your posts are assigned to silos.</p></div>'
                                                    );
                                                }
                                            }
                                        }, 0);
                                    }
                                });
                            }
                        });
                    }
                } else {
                    showNotice(response.data || 'Failed to assign posts', 'error');
                }
            },
            error: function() {
                $button.prop('disabled', false).text('Assign Selected Posts');
                showNotice('Error assigning posts', 'error');
            }
        });
    }
    
    /**
     * Handle single orphan silo change
     */
    function handleSingleOrphanSiloChange() {
        var $select = $(this);
        var postId = $select.data('post-id');
        var siloId = $select.val();
        
        var $assignBtn = $('.assign-single-orphan-btn[data-post-id="' + postId + '"]');
        
        if (siloId) {
            $assignBtn.show();
        } else {
            $assignBtn.hide();
        }
    }
    
    /**
     * Handle assign single orphan
     */
    function handleAssignSingleOrphan(e) {
        e.preventDefault();
        
        var $button = $(this);
        var postId = $button.data('post-id');
        var $select = $('.assign-single-orphan-silo[data-post-id="' + postId + '"]');
        var siloId = $select.val();
        
        if (!siloId) {
            showNotice('Please select a silo', 'error');
            return;
        }
        
        $button.prop('disabled', true).text('Assigning...');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_assign_orphan_posts',
                nonce: ssp_ajax.nonce,
                silo_id: siloId,
                post_ids: [postId]
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Post assigned successfully!', 'success');
                    // Remove row without full reload and update counter
                    var $row = $button.closest('tr');
                    $row.fadeOut(200, function() {
                        $(this).remove();
                        // Update counter immediately (optimistic update)
                        updateOrphanStats(-1);
                        
                        // Fetch authoritative count from server AFTER a delay
                        setTimeout(function() {
                            $.ajax({
                                url: ssp_ajax.ajax_url,
                                type: 'POST',
                                data: { 
                                    action: 'ssp_get_orphan_count', 
                                    nonce: ssp_ajax.nonce 
                                },
                                success: function(r) {
                                    if (r && r.success && r.data && typeof r.data.total !== 'undefined') {
                                        var $counter = $('#ssp-orphan-total');
                                        if ($counter.length) {
                                            $counter.text(parseInt(r.data.total, 10));
                                            // Force repaint
                                            if ($counter[0]) {
                                                $counter[0].offsetHeight;
                                            }
                                        }
                                    }
                                },
                                error: function() {
                                    // Silent fail - optimistic update should be enough
                                    console.warn('Failed to fetch authoritative orphan count');
                                }
                            });
                        }, 300); // Wait 300ms for DB transaction to commit
                        
                        // Wait a tick to ensure DOM is updated, then check for empty state
                        setTimeout(function() {
                            var remainingRows = $('#orphan-posts-tbody tr[data-post-id]').length;
                            // Remove empty section headers
                            $('#orphan-posts-tbody tr.ssp-section-header').each(function() {
                                var $header = $(this);
                                var $range = $header.nextUntil('tr.ssp-section-header');
                                if ($range.filter('tr[data-post-id]').length === 0) {
                                    $header.remove();
                                }
                            });
                            var $counter = $('#ssp-orphan-total');
                            var counterValue = 0;
                            if ($counter.length) {
                                var counterText = $counter.text().trim().replace(/[^\d]/g, '');
                                counterValue = parseInt(counterText, 10) || 0;
                            }
                            
                            if (remainingRows === 0 && counterValue === 0) {
                                var $tableSection = $('#orphan-posts-tbody').closest('.ssp-form-section');
                                if ($tableSection.length) {
                                    $tableSection.html(
                                        '<div class="ssp-empty-state"><p>🎉 Great! No orphan posts found. All your posts are assigned to silos.</p></div>'
                                    );
                                }
                            }
                        }, 0);
                    });
                } else {
                    showNotice(response.data || 'Failed to assign post', 'error');
                    $button.prop('disabled', false).text('Assign');
                }
            },
            error: function() {
                showNotice('Error assigning post', 'error');
                $button.prop('disabled', false).text('Assign');
            }
        });
    }

    // Update the orphan posts stats counter in the banner
    function updateOrphanStats(delta) {
        var $counter = $('#ssp-orphan-total');
        if ($counter.length && $counter.length > 0) {
            // Get current text content - handle both text() and html()
            var currentText = ($counter.text() || $counter.html() || '0').toString().trim();
            // Remove any non-numeric characters
            currentText = currentText.replace(/[^\d]/g, '');
            var current = parseInt(currentText, 10);
            
            if (!isNaN(current) && current >= 0) {
                var next = Math.max(0, current + (delta || 0));
                
                // Update both text and html to ensure visibility
                $counter.text(next);
                $counter.html(next);
                
                // Force multiple repaints to ensure browser renders
                if ($counter[0]) {
                    var element = $counter[0];
                    // Force layout recalculation
                    element.offsetHeight;
                    element.clientHeight;
                    // Force style recalculation
                    window.getComputedStyle(element).opacity;
                    // Trigger reflow
                    void element.offsetWidth;
                }
                
                // Debug log
                console.log('Orphan counter updated:', current, '+', delta, '=', next);
            } else {
                console.warn('Could not parse orphan counter value:', currentText);
            }
        } else {
            console.warn('Orphan counter element not found: #ssp-orphan-total');
        }
    }
    
    /**
     * Handle load anchor management
     */
    function handleLoadAnchorManagement(e) {
        if (e) e.preventDefault();
        
        var siloId = $('#anchor-mgmt-silo-select').val();
        if (!siloId) {
            showNotice('Please select a silo', 'error');
            return;
        }
        
        $('#anchor-mgmt-loading').show();
        $('#anchor-mgmt-content').hide();
        $('#anchor-mgmt-tbody').html('');
        
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_silo_anchors',
                nonce: ssp_ajax.nonce,
                silo_id: siloId
            },
            success: function(response) {
                $('#anchor-mgmt-loading').hide();
                
                if (response.success && response.data.anchors) {
                    displayAnchorManagementTable(response.data.anchors);
                    $('#anchor-mgmt-content').show();
                } else {
                    showNotice(response.data || 'No anchors found for this silo', 'info');
                    $('#anchor-mgmt-tbody').html('<tr><td colspan="5" style="text-align: center; padding: 20px;">No anchors found for this silo. Generate links first.</td></tr>');
                    $('#anchor-mgmt-content').show();
                }
            },
            error: function() {
                $('#anchor-mgmt-loading').hide();
                showNotice('Error loading anchors', 'error');
                $('#anchor-mgmt-tbody').html('<tr><td colspan="5" style="text-align: center; padding: 20px;">Error loading data</td></tr>');
                $('#anchor-mgmt-content').show();
            }
        });
    }
    
    /**
     * Display anchor management table
     */
    function displayAnchorManagementTable(anchors) {
        var html = '';
        
        if (!anchors || anchors.length === 0) {
            html = '<tr><td colspan="5" style="text-align: center; padding: 20px;">No anchors found for this silo</td></tr>';
        } else {
            anchors.forEach(function(anchor) {
                // Skip invalid anchors
                if (!anchor || !anchor.link_id) {
                    return;
                }
                html += '<tr data-link-id="' + escapeHtml(String(anchor.link_id)) + '">';
                html += '<td>';
                html += '<a href="' + escapeHtml(anchor.source_post_edit_url) + '" target="_blank">';
                html += escapeHtml(anchor.source_post_title);
                html += '</a>';
                html += '</td>';
                html += '<td>';
                html += '<a href="' + escapeHtml(anchor.target_post_view_url) + '" target="_blank">';
                html += escapeHtml(anchor.target_post_title);
                html += '</a>';
                html += '</td>';
                // Validate current_anchor_text exists, default to empty string
                var currentAnchorText = anchor.current_anchor_text || '';
                if (typeof currentAnchorText !== 'string') {
                    currentAnchorText = String(currentAnchorText);
                }
                
                // Validate all required IDs
                var linkId = anchor.link_id || 0;
                var sourcePostId = anchor.source_post_id || 0;
                var targetPostId = anchor.target_post_id || 0;
                
                // Skip if critical data is missing
                if (!linkId || !sourcePostId || !targetPostId) {
                    console.warn('Skipping anchor with missing data:', anchor);
                    return;
                }
                
                html += '<td><code style="background: #f0f0f1; padding: 4px 8px; border-radius: 3px;">' + escapeHtml(currentAnchorText) + '</code></td>';
                html += '<td>#' + escapeHtml(String(linkId)) + '</td>';
                html += '<td>';
                html += '<button type="button" class="button button-small get-ai-suggestions-btn" ';
                html += 'data-link-id="' + escapeHtml(String(linkId)) + '" ';
                html += 'data-source-post-id="' + escapeHtml(String(sourcePostId)) + '" ';
                html += 'data-target-post-id="' + escapeHtml(String(targetPostId)) + '" ';
                html += 'data-current-anchor="' + escapeHtml(currentAnchorText) + '">';
                html += '🤖 Get AI Suggestions';
                html += '</button>';
                html += '</td>';
                html += '</tr>';
            });
        }
        
        $('#anchor-mgmt-tbody').html(html);
    }
    
    /**
     * Handle get AI suggestions
     */
    function handleGetAISuggestions(e) {
        if (e) e.preventDefault();
        
        var $btn = $(this);
        var linkId = $btn.data('link-id');
        var sourcePostId = $btn.data('source-post-id');
        var targetPostId = $btn.data('target-post-id');
        var currentAnchor = $btn.data('current-anchor');
        
        // Validate inputs
        if (!linkId || !sourcePostId || !targetPostId) {
            showNotice('Missing required data. Please refresh the page and try again.', 'error');
            return;
        }
        
        // Disable button to prevent multiple clicks
        $btn.prop('disabled', true);
        var originalText = $btn.html();
        $btn.html('🔄 Generating...');
        
        // Store link ID and post IDs for use in selection handler
        $('#anchor-suggestions-modal').data('link-id', linkId);
        $('#anchor-suggestions-modal').data('source-post-id', sourcePostId);
        $('#anchor-suggestions-modal').data('target-post-id', targetPostId);
        
        // Show modal
        $('#suggestions-loading').show();
        $('#suggestions-list').hide().html('');
        $('#anchor-suggestions-modal').show();
        
        // Get AI suggestions
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_get_ai_anchor_suggestions',
                nonce: ssp_ajax.nonce,
                source_post_id: sourcePostId,
                target_post_id: targetPostId
            },
            timeout: 30000, // 30 second timeout
            success: function(response) {
                // Re-enable button
                $btn.prop('disabled', false);
                $btn.html(originalText);
                
                $('#suggestions-loading').hide();
                
                if (response.success && response.data && response.data.suggestions && Array.isArray(response.data.suggestions)) {
                    var suggestions = response.data.suggestions;
                    
                    // Validate suggestions
                    if (suggestions.length === 0) {
                        showNotice('AI did not return any suggestions. Please try again or check your API configuration.', 'warning');
                        $('#anchor-suggestions-modal').hide();
                        return;
                    }
                    
                    displayAISuggestions(suggestions, currentAnchor || '');
                    $('#suggestions-list').show();
                } else {
                    var errorMsg = response.data || 'Failed to get AI suggestions';
                    showNotice(errorMsg, 'error');
                    $('#anchor-suggestions-modal').hide();
                }
            },
            error: function(xhr, status, error) {
                // Re-enable button
                $btn.prop('disabled', false);
                $btn.html(originalText);
                
                $('#suggestions-loading').hide();
                
                var errorMsg = 'Error getting AI suggestions';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please check your API connection and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg = xhr.responseJSON.data;
                }
                
                showNotice(errorMsg, 'error');
                $('#anchor-suggestions-modal').hide();
            }
        });
    }
    
    /**
     * Display AI suggestions
     */
    function displayAISuggestions(suggestions, currentAnchor) {
        if (!suggestions || !Array.isArray(suggestions) || suggestions.length === 0) {
            $('#suggestions-list').html('<p style="color: #d63638; padding: 20px;">No suggestions available. Please try again or check your AI API configuration.</p>');
            return;
        }
        
        // Filter out invalid suggestions
        var validSuggestions = suggestions.filter(function(s) {
            return s && typeof s === 'string' && s.trim().length > 0 && s.trim().length <= 100;
        });
        
        if (validSuggestions.length === 0) {
            $('#suggestions-list').html('<p style="color: #d63638; padding: 20px;">All suggestions were invalid. Please try again.</p>');
            return;
        }
        
        var html = '';
        
        // Show current anchor for comparison
        if (currentAnchor && currentAnchor.trim().length > 0) {
            html += '<div style="background: #f0f8ff; border-left: 4px solid #0073aa; padding: 12px; margin-bottom: 20px; border-radius: 4px;">';
            html += '<strong>Current anchor text:</strong> ';
            html += '<code style="background: #fff; padding: 4px 8px; border-radius: 3px; margin-left: 10px; font-size: 14px;">';
            html += escapeHtml(currentAnchor);
            html += '</code>';
            html += '</div>';
        }
        
        html += '<p style="margin-bottom: 20px; font-weight: 600;">🤖 Select one of the AI-generated anchor options:</p>';
        html += '<div class="suggestion-list" style="display: flex; flex-direction: column; gap: 12px;">';
        
        validSuggestions.forEach(function(suggestion, index) {
            var trimmedSuggestion = suggestion.trim();
            if (!trimmedSuggestion) return;
            
            // Count words for display
            var wordCount = trimmedSuggestion.split(/\s+/).length;
            var wordCountLabel = wordCount >= 2 ? '✓ ' + wordCount + ' words' : '';
            
            html += '<div class="suggestion-item" style="border: 2px solid #ddd; padding: 15px; border-radius: 5px; cursor: pointer; transition: all 0.2s; background: #fff; position: relative;" ';
            html += 'onmouseover="this.style.borderColor=\'#0073aa\'; this.style.backgroundColor=\'#f0f8ff\';" ';
            html += 'onmouseout="this.style.borderColor=\'#ddd\'; this.style.backgroundColor=\'#fff\';" ';
            html += 'onclick="jQuery(this).find(\'.select-anchor-suggestion\').click();" ';
            html += 'data-suggestion="' + escapeHtml(trimmedSuggestion) + '">';
            html += '<div style="display: flex; justify-content: space-between; align-items: center;">';
            html += '<div style="flex: 1;">';
            html += '<div style="margin-bottom: 8px;">';
            html += '<strong style="color: #0073aa;">Option ' + (index + 1) + ':</strong> ';
            if (wordCountLabel) {
                html += '<span style="color: #46b450; font-size: 12px;">' + wordCountLabel + '</span>';
            }
            html += '</div>';
            html += '<code style="background: #f0f0f1; padding: 8px 12px; border-radius: 3px; font-size: 14px; display: inline-block; word-break: break-word; max-width: 80%;">';
            html += escapeHtml(trimmedSuggestion);
            html += '</code>';
            html += '</div>';
            html += '<button type="button" class="button button-primary button-small select-anchor-suggestion" style="margin-left: 15px;" ';
            html += 'data-suggestion="' + escapeHtml(trimmedSuggestion) + '">Select This</button>';
            html += '</div>';
            html += '</div>';
        });
        
        html += '</div>';
        
        $('#suggestions-list').html(html);
    }
    
    /**
     * Handle select anchor suggestion
     */
    function handleSelectAnchorSuggestion(e) {
        if (e) e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        var newAnchorText = $btn.data('suggestion');
        var linkId = $('#anchor-suggestions-modal').data('link-id');
        
        if (!newAnchorText || typeof newAnchorText !== 'string' || newAnchorText.trim() === '') {
            showNotice('Invalid anchor text selected', 'error');
            return;
        }
        
        if (!linkId) {
            showNotice('Missing link ID. Please refresh the page and try again.', 'error');
            return;
        }
        
        // Validate anchor text length and content
        newAnchorText = newAnchorText.trim();
        if (newAnchorText.length < 1 || newAnchorText.length > 100) {
            showNotice('Anchor text must be between 1 and 100 characters', 'error');
            return;
        }
        
        // Disable button during update
        $btn.prop('disabled', true);
        var originalText = $btn.html();
        $btn.html('Updating...');
        
        // Disable all suggestion buttons to prevent double-click
        $('.select-anchor-suggestion').prop('disabled', true);
        
        // Update anchor text
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ssp_update_anchor_text',
                nonce: ssp_ajax.nonce,
                link_id: linkId,
                anchor_text: newAnchorText
            },
            timeout: 15000, // 15 second timeout
            success: function(response) {
                // Re-enable buttons
                $btn.prop('disabled', false);
                $btn.html(originalText);
                $('.select-anchor-suggestion').prop('disabled', false);
                
                if (response.success) {
                    showNotice('✓ Anchor text updated successfully!', 'success');
                    $('#anchor-suggestions-modal').hide();
                    
                    // Update the table row (escape linkId to prevent XSS)
                    var $row = $('tr[data-link-id="' + escapeHtml(String(linkId)) + '"]');
                    if ($row.length > 0) {
                        // Update anchor text display
                        $row.find('td:nth-child(3) code').text(newAnchorText);
                        
                        // Update button data attribute
                        $row.find('.get-ai-suggestions-btn').data('current-anchor', newAnchorText);
                        
                        // Highlight the row briefly to show update
                        $row.css('background-color', '#d4edda');
                        setTimeout(function() {
                            $row.css('background-color', '');
                        }, 2000);
                    } else {
                        // Row not found - suggest reload
                        showNotice('Anchor updated but table refresh recommended. Please reload anchors.', 'info');
                    }
                } else {
                    showNotice(response.data || 'Failed to update anchor text', 'error');
                }
            },
            error: function(xhr, status, error) {
                // Re-enable buttons
                $btn.prop('disabled', false);
                $btn.html(originalText);
                $('.select-anchor-suggestion').prop('disabled', false);
                
                var errorMsg = 'Error updating anchor text';
                if (status === 'timeout') {
                    errorMsg = 'Update timed out. Please check your connection and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg = xhr.responseJSON.data;
                }
                
                showNotice(errorMsg, 'error');
            }
        });
    }
    
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
