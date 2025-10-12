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
        
        // Anchor Details Modal close
        $(document).on('click', '#anchor-details-modal .ssp-modal-close, #anchor-details-modal', function(e) {
            if ($(e.target).is('#anchor-details-modal') || $(e.target).is('.ssp-modal-close')) {
                $('#anchor-details-modal').fadeOut();
            }
        });
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
            link_placement: $form.find('input[name="link_placement"]:checked').val(),
            supports_to_pillar: $form.find('input[name="supports_to_pillar"]').is(':checked'),
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
        
        var modalHtml = '<div id="ssp-silo-details-modal" class="ssp-modal-overlay">';
        modalHtml += '<div class="ssp-modal-content">';
        modalHtml += '<span class="ssp-modal-close">&times;</span>';
        modalHtml += '<h2>📊 Silo Details</h2>';
        
        modalHtml += '<div class="silo-info">';
        modalHtml += '<p><strong>Silo Name:</strong> ' + siloData.name + '</p>';
        modalHtml += '<p><strong>Pillar Post:</strong> ' + siloData.pillar_title + ' (ID: ' + siloData.pillar_id + ')</p>';
        modalHtml += '<p><strong>Setup Method:</strong> ' + siloData.setup_method + '</p>';
        modalHtml += '<p><strong>Linking Mode:</strong> ' + siloData.linking_mode + '</p>';
        modalHtml += '<p><strong>Total Support Posts:</strong> ' + siloData.posts.length + '</p>';
        modalHtml += '<p><strong>Total Links:</strong> ' + siloData.total_links + '</p>';
        modalHtml += '<p><strong>Created:</strong> ' + siloData.created_at + '</p>';
        modalHtml += '</div>';
        
        modalHtml += '<h3>Support Posts in This Silo:</h3>';
        modalHtml += '<table class="wp-list-table widefat">';
        modalHtml += '<thead><tr><th>Post Title</th><th>Post ID</th><th>Links</th></tr></thead>';
        modalHtml += '<tbody>';
        
        siloData.posts.forEach(function(post) {
            modalHtml += '<tr>';
            modalHtml += '<td>' + post.title + '</td>';
            modalHtml += '<td>#' + post.id + '</td>';
            modalHtml += '<td>' + post.link_count + '</td>';
            modalHtml += '</tr>';
        });
        
        modalHtml += '</tbody></table>';
        
        modalHtml += '<div class="ssp-modal-actions">';
        modalHtml += '<button class="button button-primary" onclick="$(\'#ssp-silo-details-modal\').remove()">Close</button>';
        modalHtml += '</div>';
        modalHtml += '</div></div>';
        
        $('body').append(modalHtml);
        
        // Close on X or background click
        $('.ssp-modal-close, #ssp-silo-details-modal').on('click', function(e) {
            if ($(e.target).is('#ssp-silo-details-modal') || $(e.target).is('.ssp-modal-close')) {
                $('#ssp-silo-details-modal').remove();
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
        var html = '<h3>Anchor: "' + escapeHtml(data.anchor_text) + '"</h3>';
        html += '<p><strong>Total Usage:</strong> ' + data.total + ' times</p>';
        html += '<hr>';
        html += '<h4>Usage Details:</h4>';
        html += '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>From Post</th><th>To Post</th><th>Silo</th><th>Created</th></tr></thead>';
        html += '<tbody>';
        
        if (data.details && data.details.length > 0) {
            data.details.forEach(function(detail) {
                html += '<tr>';
                html += '<td><a href="' + detail.source_post_url + '" target="_blank">' + escapeHtml(detail.source_post_title) + '</a></td>';
                html += '<td><a href="' + detail.target_post_url + '" target="_blank">' + escapeHtml(detail.target_post_title) + '</a></td>';
                html += '<td>' + (detail.silo_name ? escapeHtml(detail.silo_name) : '<em>Deleted Silo</em>') + '</td>';
                html += '<td>' + detail.created_at + '</td>';
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
     * Escape HTML
     */
    function escapeHtml(text) {
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
