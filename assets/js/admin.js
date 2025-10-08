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
        
        // Custom pattern rules
        $(document).on('click', '.ssp-add-rule', handleAddCustomRule);
        $(document).on('click', '.ssp-remove-rule', handleRemoveCustomRule);
        
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
        
        var $rulesContainer = $('#custom-pattern-rules');
        var $firstRule = $rulesContainer.find('.ssp-pattern-rule').first();
        var $newRule = $firstRule.clone();
        
        $newRule.find('select').val('');
        $rulesContainer.append($newRule);
    }
    
    function handleRemoveCustomRule(e) {
        e.preventDefault();
        
        var $rule = $(this).closest('.ssp-pattern-rule');
        if ($('#custom-pattern-rules .ssp-pattern-rule').length > 1) {
            $rule.remove();
        } else {
            showNotice('You must have at least one rule', 'error');
        }
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
});
