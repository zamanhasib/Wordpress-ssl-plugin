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
        });
        
        // Trigger change event on page load to show correct section
        $('input[name="setup_method"]:checked').trigger('change');
        
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
        });
        
        // Pillar to supports checkbox handler
        $('#pillar-to-supports').on('change', function() {
            var linkingMode = $('input[name="linking_mode"]:checked').val();
            if ($(this).is(':checked') && (linkingMode === 'star_hub' || linkingMode === 'hub_chain')) {
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
        
        // Silo selector handlers
        $('#silo-select, #preview-silo-select').on('change', function() {
            var hasSelection = $(this).val() !== '';
            $(this).siblings('.ssp-preview-actions, .ssp-link-actions').find('button').prop('disabled', !hasSelection);
        });
        
        // Create silo form
        $('#ssp-create-silo-form').on('submit', handleCreateSilo);
        
        // Silo management buttons
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
        $('#check-logs').on('click', handleCheckLogs);
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
        $form.find('input[name="pillar_posts[]"]:checked').each(function() {
            pillarPosts.push($(this).val());
        });
        
        if (!setupMethod) {
            showNotice('Please select a setup method', 'error');
            $submitBtn.prop('disabled', false).text(originalText);
            return;
        }
        
        if (pillarPosts.length === 0) {
            showNotice('Please select at least one pillar post', 'error');
            $submitBtn.prop('disabled', false).text(originalText);
            return;
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
            pillar_to_supports: $form.find('input[name="pillar_to_supports"]').is(':checked'),
            max_pillar_links: $form.find('input[name="max_pillar_links"]').val(),
            max_contextual_links: $form.find('input[name="max_contextual_links"]').val()
        };
        
        // Add pillar posts as array elements
        pillarPosts.forEach(function(postId, index) {
            formData['pillar_posts[' + index + ']'] = postId;
        });
        
        // Add method-specific data
        var setupMethod = formData.setup_method;
        
        // For AI-Recommended, show recommendations first
        if (setupMethod === 'ai_recommended') {
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
                    var message = 'Silo created successfully!';
                    if (response.data && response.data.silo_id) {
                        message += '\n\nSilo ID: ' + response.data.silo_id;
                        message += '\nPillar Post ID: ' + (response.data.pillar_post_id || 'N/A');
                        message += '\nSupport Posts: ' + (response.data.support_posts_count || 0);
                    }
                    showNotice(message, 'success');
                    $form[0].reset();
                    $('.ssp-method-specific').hide();
                    // Refresh page to show new silo
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
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
                    showNotice('Silo deleted successfully!', 'success');
                    $button.closest('.ssp-silo-card').fadeOut();
                } else {
                    showNotice(response.data || 'Failed to delete silo', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            },
            complete: function() {
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
                    showNotice(response.data.message || 'Links generated successfully!', 'success');
                    $('#link-results').html('<div class="ssp-success">' + response.data.message + '</div>').show();
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
                    showNotice(response.data.message || 'Links removed successfully!', 'success');
                    $('#link-results').html('<div class="ssp-success">' + response.data.message + '</div>').show();
                } else {
                    showNotice(response.data || 'Failed to remove links', 'error');
                }
            },
            error: function() {
                showNotice(ssp_ajax.strings.error, 'error');
            },
            complete: function() {
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
        
        if (Object.keys(data).length === 0) {
            html += '<p class="ssp-no-results">No preview data available.</p>';
        } else {
            html += '<h3>Link Preview</h3>';
            
            $.each(data, function(postId, previews) {
                if (previews.length > 0) {
                    html += '<div class="ssp-post-preview">';
                    html += '<h4>' + getPostTitle(postId) + '</h4>';
                    html += '<ul class="ssp-preview-links">';
                    
                    $.each(previews, function(index, preview) {
                        html += '<li>';
                        html += '<strong>Link to:</strong> ' + preview.target_title + '<br>';
                        html += '<strong>Anchor:</strong> "' + preview.anchor_text + '"<br>';
                        html += '<strong>Position:</strong> ' + preview.insertion_point;
                        html += '</li>';
                    });
                    
                    html += '</ul>';
                    html += '</div>';
                }
            });
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
        
        // Simple table check - this would need a new AJAX endpoint
        $result.html('<div class="notice notice-info"><p>Table check functionality would go here. Check your database for tables starting with wp_ssp_</p></div>');
        
        $button.prop('disabled', false).text('Check Tables');
    }
    
    function handleCheckLogs(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $result = $('#check-logs-result');
        
        $button.prop('disabled', true).text('Checking...');
        
        // Simple log check - this would need a new AJAX endpoint
        $result.html('<div class="notice notice-info"><p>Check your WordPress debug.log file in wp-content/ for SSP entries</p></div>');
        
        $button.prop('disabled', false).text('Check Recent Logs');
    }
    
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut();
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
        
        var modalHtml = '<div id="ssp-ai-modal" class="ssp-modal-overlay">';
        modalHtml += '<div class="ssp-modal-content">';
        modalHtml += '<span class="ssp-modal-close">&times;</span>';
        modalHtml += '<h2>🤖 AI Recommendations - Review & Approve</h2>';
        modalHtml += '<p>Select which posts to include in your silo(s):</p>';
        
        recommendations.forEach(function(rec, index) {
            modalHtml += '<div class="ssp-recommendation-group">';
            modalHtml += '<h3>📄 Pillar: ' + rec.pillar_title + '</h3>';
            modalHtml += '<div class="ssp-recommendations-list">';
            
            if (rec.recommendations && rec.recommendations.length > 0) {
                modalHtml += '<table class="wp-list-table widefat">';
                modalHtml += '<thead><tr>';
                modalHtml += '<th style="width: 40px;"><input type="checkbox" class="select-all-recs" data-pillar="' + rec.pillar_id + '" checked></th>';
                modalHtml += '<th>Post Title</th>';
                modalHtml += '<th style="width: 100px;">Relevance</th>';
                modalHtml += '<th style="width: 40%;">Excerpt</th>';
                modalHtml += '</tr></thead><tbody>';
                
                rec.recommendations.forEach(function(post) {
                    var relevancePercent = Math.round(post.relevance * 100);
                    var relevanceClass = relevancePercent >= 80 ? 'high' : (relevancePercent >= 60 ? 'medium' : 'low');
                    
                    modalHtml += '<tr>';
                    modalHtml += '<td><input type="checkbox" class="ai-rec-checkbox" data-pillar="' + rec.pillar_id + '" data-post="' + post.id + '" checked></td>';
                    modalHtml += '<td><strong>' + post.title + '</strong></td>';
                    modalHtml += '<td><span class="relevance-badge relevance-' + relevanceClass + '">' + relevancePercent + '%</span></td>';
                    modalHtml += '<td style="font-size: 12px; color: #666;">' + post.excerpt + '</td>';
                    modalHtml += '</tr>';
                });
                
                modalHtml += '</tbody></table>';
            } else {
                modalHtml += '<p>No recommendations found for this pillar.</p>';
            }
            
            modalHtml += '</div></div>';
        });
        
        modalHtml += '<div class="ssp-modal-actions">';
        modalHtml += '<button id="approve-ai-recommendations" class="button button-primary button-large">✓ Approve & Create Silo</button>';
        modalHtml += '<button id="cancel-ai-recommendations" class="button button-large">Cancel</button>';
        modalHtml += '</div>';
        modalHtml += '</div></div>';
        
        $('body').append(modalHtml);
        
        // Handle select all checkbox
        $('.select-all-recs').on('change', function() {
            var pillarId = $(this).data('pillar');
            var isChecked = $(this).is(':checked');
            $('.ai-rec-checkbox[data-pillar="' + pillarId + '"]').prop('checked', isChecked);
        });
        
        // Handle approve button
        $('#approve-ai-recommendations').on('click', function() {
            createSiloWithApprovedRecommendations(recommendations, formData, $form);
        });
        
        // Handle cancel button
        $('#cancel-ai-recommendations, .ssp-modal-close').on('click', function() {
            $('#ssp-ai-modal').remove();
        });
        
        // Close on background click
        $('#ssp-ai-modal').on('click', function(e) {
            if ($(e.target).is('#ssp-ai-modal')) {
                $('#ssp-ai-modal').remove();
            }
        });
    }
    
    /**
     * Create silo with user-approved AI recommendations
     */
    function createSiloWithApprovedRecommendations(recommendations, formData, $form) {
        var approvedPosts = {};
        
        // Collect approved posts for each pillar
        $('.ai-rec-checkbox:checked').each(function() {
            var pillarId = $(this).data('pillar');
            var postId = $(this).data('post');
            
            if (!approvedPosts[pillarId]) {
                approvedPosts[pillarId] = [];
            }
            approvedPosts[pillarId].push(postId);
        });
        
        // Check if any posts were approved
        if (Object.keys(approvedPosts).length === 0) {
            showNotice('Please select at least one post to include', 'error');
            return;
        }
        
        // Close modal
        $('#ssp-ai-modal').remove();
        
        // Update form data with approved posts
        formData.approved_recommendations = JSON.stringify(approvedPosts);
        
        // Show processing
        var $submitBtn = $form.find('button[type="submit"]');
        $submitBtn.prop('disabled', true).text(ssp_ajax.strings.processing);
        
        // Submit to create silo
        $.ajax({
            url: ssp_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                $submitBtn.prop('disabled', false).text('Create Silo');
                
                if (response.success) {
                    showNotice('Silo created successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || 'Failed to create silo', 'error');
                }
            },
            error: function() {
                $submitBtn.prop('disabled', false).text('Create Silo');
                showNotice(ssp_ajax.strings.error, 'error');
            }
        });
    }
});
