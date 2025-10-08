/**
 * Frontend JavaScript for Semantic Silo Pro
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialize frontend functionality
        initSemanticSilo();
    });
    
    function initSemanticSilo() {
        // Check if ssp_ajax object exists
        if (typeof ssp_ajax === 'undefined') {
            console.log('SSP: ssp_ajax object not found');
            return;
        }
        
        // Add click tracking for internal links
        $('a[href*="' + window.location.hostname + '"]').on('click', function() {
            var $link = $(this);
            var href = $link.attr('href');
            
            // Track internal link clicks
            if (typeof gtag !== 'undefined') {
                gtag('event', 'click', {
                    'event_category': 'Internal Link',
                    'event_label': href,
                    'transport_type': 'beacon'
                });
            }
            
            // Send analytics to plugin
            $.post(ssp_ajax.ajax_url, {
                action: 'ssp_track_link_click',
                nonce: ssp_ajax.nonce,
                link_url: href,
                source_url: window.location.href
            });
        });
        
        // Add smooth scrolling for anchor links
        $('a[href^="#"]').on('click', function(e) {
            var target = $(this).attr('href');
            if (target.length > 1) {
                e.preventDefault();
                $('html, body').animate({
                    scrollTop: $(target).offset().top - 100
                }, 500);
            }
        });
        
        // Add reading progress indicator
        addReadingProgress();
        
        // Add related content suggestions
        addRelatedContent();
    }
    
    function addReadingProgress() {
        if ($('article').length === 0) return;
        
        var $progress = $('<div class="ssp-reading-progress"><div class="ssp-progress-bar"></div></div>');
        $('body').append($progress);
        
        $(window).on('scroll', function() {
            var scrollTop = $(window).scrollTop();
            var docHeight = $(document).height();
            var winHeight = $(window).height();
            var scrollPercent = (scrollTop / (docHeight - winHeight)) * 100;
            
            $('.ssp-progress-bar').css('width', scrollPercent + '%');
        });
    }
    
    function addRelatedContent() {
        var $article = $('article');
        if ($article.length === 0) return;
        
        // Check if we have silo data
        var siloMeta = $('meta[name="semantic-silo"]');
        if (siloMeta.length === 0) return;
        
        var siloIds = siloMeta.attr('content');
        
        // Fetch related content
        $.post(ssp_ajax.ajax_url, {
            action: 'ssp_get_related_content',
            nonce: ssp_ajax.nonce,
            silo_ids: siloIds,
            post_id: $('body').data('post-id') || 0
        }, function(response) {
            if (response.success && response.data.length > 0) {
                var $related = $('<div class="ssp-related-content"><h3>Related Content</h3><ul></ul></div>');
                
                $.each(response.data, function(i, post) {
                    $related.find('ul').append(
                        '<li><a href="' + post.url + '">' + post.title + '</a></li>'
                    );
                });
                
                $article.append($related);
            }
        });
    }
    
})(jQuery);
