// ContentGuard Admin JavaScript - Complete Fixed Version with Debug
jQuery(document).ready(function($) {
    
    // Debug AJAX setup
    console.log('=== ContentGuard JavaScript Loading ===');
    console.log('ContentGuard AJAX object:', typeof contentguard_ajax !== 'undefined' ? contentguard_ajax : 'NOT FOUND');
    console.log('jQuery version:', $.fn.jquery);
    console.log('Current page elements:', {
        'dashboard': $('#contentguard-dashboard').length,
        'admin': $('.contentguard-admin').length,
        'recent-detections': $('#recent-detections').length,
        'manual-refresh': $('#manual-refresh').length
    });
    
    if (typeof contentguard_ajax === 'undefined') {
        console.error('ContentGuard AJAX object not found - scripts not loading correctly');
        $('#recent-detections').html('<div class="contentguard-no-data">Error: AJAX not configured. Check browser console for details.</div>');
        return;
    }
    
    // Add debug function first
    window.debugContentGuardAJAX = function() {
        console.log('=== ContentGuard AJAX Debug ===');
        console.log('AJAX URL:', contentguard_ajax.ajax_url);
        console.log('Nonce:', contentguard_ajax.nonce);
        
        // Test basic connectivity
        $.post(contentguard_ajax.ajax_url, {
            action: 'contentguard_debug',
            nonce: contentguard_ajax.nonce
        }, function(response) {
            console.log('Debug response:', response);
            alert('Debug response: ' + JSON.stringify(response, null, 2));
        }).fail(function(xhr, status, error) {
            console.error('Debug AJAX failed:', error, xhr.responseText);
            alert('Debug AJAX failed: ' + error + '\nCheck console for details.');
        });
        
        // Test detections endpoint specifically
        $.post(contentguard_ajax.ajax_url, {
            action: 'contentguard_get_detections',
            nonce: contentguard_ajax.nonce,
            limit: 5,
            offset: 0
        }, function(response) {
            console.log('Detections test response:', response);
        }).fail(function(xhr, status, error) {
            console.error('Detections test failed:', error, xhr.responseText);
        });
    };
    
    // Define all functions first
    function loadDashboardData() {
        console.log('Loading dashboard data...');
        $.post(contentguard_ajax.ajax_url, {
            action: 'contentguard_get_stats',
            nonce: contentguard_ajax.nonce
        }, function(response) {
            console.log('Stats response:', response);
            if (response.success) {
                updateStats(response.data);
                updateCharts(response.data);
            } else {
                console.error('Stats request failed:', response);
            }
        }).fail(function(xhr, status, error) {
            console.error('Stats AJAX failed:', error, xhr.responseText);
            $('#total-bots').text('Error');
            $('#commercial-bots').text('Error');
            $('#top-company').text('Error');
            $('#content-value').text('Error');
        });
    }
    
    function loadRecentDetections() {
        console.log('Loading recent detections...');
        console.log('Target element exists:', $('#recent-detections').length > 0);
        
        // Show loading immediately
        $('#recent-detections').html('<div class="contentguard-loading">Loading enhanced detection data...</div>');
        
        $.post(contentguard_ajax.ajax_url, {
            action: 'contentguard_get_detections',
            nonce: contentguard_ajax.nonce,
            limit: 20,
            offset: 0
        }, function(response) {
            console.log('Detections response:', response);
            
            if (response.success) {
                if (response.data.message) {
                    // Handle case where no detections found
                    displayNoDetections(response.data);
                } else {
                    // Display the detections
                    displayDetections(response.data);
                }
            } else {
                console.error('Failed to load detections:', response);
                $('#recent-detections').html('<div class="contentguard-no-data">Failed to load detection data: ' + (response.data || 'Unknown error') + '</div>');
            }
        }).fail(function(xhr, status, error) {
            console.error('Detections AJAX failed:', error, xhr.responseText);
            let errorMsg = 'Failed to load detection data. ';
            if (xhr.status === 0) {
                errorMsg += 'Network connection issue.';
            } else if (xhr.status === 403) {
                errorMsg += 'Permission denied (403).';
            } else if (xhr.status === 404) {
                errorMsg += 'AJAX endpoint not found (404).';
            } else {
                errorMsg += 'HTTP ' + xhr.status + ': ' + error;
            }
            $('#recent-detections').html('<div class="contentguard-no-data">' + errorMsg + '<br><button onclick="debugContentGuardAJAX()" class="button">Debug AJAX</button></div>');
        });
    }
    
    function updateStats(data) {
        $('#total-bots').text(data.total_bots || '0');
        $('#commercial-bots').text(data.commercial_bots || '0');
        $('#top-company').text(data.top_company || 'None detected');
        $('#content-value').text('$' + (data.content_value || '0.00'));
    }
    
    function updateCharts(data) {
        // Activity trend chart
        const activityCtx = document.getElementById('activity-chart');
        if (activityCtx && window.activityChart) {
            window.activityChart.destroy();
        }
        
        if (activityCtx && data.daily_activity) {
            const labels = data.daily_activity.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });
            const values = data.daily_activity.map(item => parseInt(item.count));
            
            window.activityChart = new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'AI Bot Detections',
                        data: values,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
        
        // Companies pie chart
        const companiesCtx = document.getElementById('companies-chart');
        if (companiesCtx && window.companiesChart) {
            window.companiesChart.destroy();
        }
        
        if (companiesCtx && data.company_breakdown) {
            const labels = data.company_breakdown.map(item => item.company || 'Unknown');
            const values = data.company_breakdown.map(item => parseInt(item.count));
            const colors = [
                '#dc3545', '#fd7e14', '#ffc107', '#198754', 
                '#20c997', '#0dcaf0', '#6f42c1', '#d63384',
                '#6c757d', '#495057'
            ];
            
            window.companiesChart = new Chart(companiesCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 15
                            }
                        }
                    }
                }
            });
        }
    }
    
    function displayNoDetections(data) {
        const container = $('#recent-detections');
        
        let html = '<div class="contentguard-no-data">';
        html += '<h4>No AI Bot Detections Found</h4>';
        html += '<p>' + data.message + '</p>';
        
        if (data.suggestions) {
            html += '<h5>Suggestions:</h5>';
            html += '<ul>';
            data.suggestions.forEach(function(suggestion) {
                html += '<li>' + escapeHtml(suggestion) + '</li>';
            });
            html += '</ul>';
        }
        
        html += '<div style="margin-top: 15px;">';
        html += '<button class="button button-primary" onclick="testDetection()">Test Detection</button>';
        html += '<button class="button" onclick="debugContentGuardAJAX()" style="margin-left: 10px;">Debug AJAX</button>';
        html += '</div>';
        html += '</div>';
        
        container.html(html);
    }

    function displayDetections(detections) {
        const container = $('#recent-detections');
        
        if (!detections || detections.length === 0) {
            displayNoDetections({
                message: 'No AI bot detections found in the last 30 days.',
                suggestions: [
                    'Install a user agent switcher and visit your site with bot user agents to test detection',
                    'Check that ContentGuard detection is enabled in settings'
                ]
            });
            return;
        }
        
        let html = '<div class="contentguard-detections-table">';
        html += '<div class="detection-header">';
        html += '<div class="detection-col-time">Time</div>';
        html += '<div class="detection-col-company">Company</div>';
        html += '<div class="detection-col-bot">Bot Type</div>';
        html += '<div class="detection-col-page">Page</div>';
        html += '<div class="detection-col-risk">Risk</div>';
        html += '<div class="detection-col-value">Est. Value</div>';
        html += '</div>';
        
        detections.forEach(function(detection) {
            const date = new Date(detection.detected_at);
            const timeAgo = getTimeAgo(date);
            const riskClass = getRiskClass(detection.risk_level);
            const estimatedValue = detection.estimated_value ? parseFloat(detection.estimated_value) : 0;
            const displayValue = estimatedValue > 0 ? '$' + estimatedValue.toFixed(2) : '$0.00';
            
            html += '<div class="detection-row">';
            html += `<div class="detection-col-time">${timeAgo}</div>`;
            html += `<div class="detection-col-company">${escapeHtml(detection.company || 'Unknown')}</div>`;
            html += `<div class="detection-col-bot">${escapeHtml(detection.bot_type || 'Unknown')}</div>`;
            html += `<div class="detection-col-page">${escapeHtml(detection.request_uri ? detection.request_uri.substring(0, 50) : '')}${detection.request_uri && detection.request_uri.length > 50 ? '...' : ''}</div>`;
            html += `<div class="detection-col-risk"><span class="risk-badge ${riskClass}">${detection.risk_level || 'unknown'}</span></div>`;
            html += `<div class="detection-col-value">${displayValue}</div>`;
            html += '</div>';
        });
        
        html += '</div>';
        
        // Add summary stats
        const totalValue = detections.reduce((sum, d) => sum + (parseFloat(d.estimated_value) || 0), 0);
        const highRiskCount = detections.filter(d => d.risk_level === 'high').length;
        const commercialCount = detections.filter(d => d.commercial_risk == 1).length;
        
        html += '<div class="contentguard-summary">';
        html += `<div class="summary-item"><strong>${detections.length}</strong> total detections</div>`;
        html += `<div class="summary-item"><strong>${highRiskCount}</strong> high-risk bots</div>`;
        html += `<div class="summary-item"><strong>${commercialCount}</strong> commercial bots</div>`;
        html += `<div class="summary-item"><strong>$${totalValue.toFixed(2)}</strong> estimated content value</div>`;
        html += '</div>';
        
        container.html(html);
    }

    function getTimeAgo(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return date.toLocaleDateString();
    }

    function getRiskClass(riskLevel) {
        switch(riskLevel) {
            case 'high': return 'risk-high';
            case 'medium': return 'risk-medium';
            case 'low': return 'risk-low';
            default: return 'risk-unknown';
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showNotification(message, type = 'info') {
        const notification = $(`
            <div class="contentguard-notification ${type}">
                <span class="notification-text">${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `);
        
        $('body').append(notification);
        
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        notification.find('.notification-close').on('click', function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        });
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 5000);
    }
    
    // Make testDetection function global for onclick handler
    window.testDetection = function() {
        const testInput = $('#test-user-agent');
        if (testInput.length) {
            testInput.val('Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)');
            testInput.focus();
            
            $('html, body').animate({
                scrollTop: testInput.closest('.contentguard-panel').offset().top - 50
            }, 500);
        } else {
            alert('Please use the test form below to test bot detection.');
        }
    };
    
    // Tab functionality
    $('.contentguard-tab-button').on('click', function() {
        const tabId = $(this).data('tab');
        
        $('.contentguard-tab-button').removeClass('active');
        $('.contentguard-tab-content').removeClass('active');
        
        $(this).addClass('active');
        $('#tab-' + tabId).addClass('active');
        
        // Load data for specific tabs
        if (tabId === 'realtime') {
            loadDashboardData();
            loadRecentDetections();
        }
    });
    
    // Test detection functionality
    $('#test-user-agent-form').on('submit', function(e) {
        e.preventDefault();
        
        const userAgent = $('#test-user-agent').val().trim();
        if (!userAgent) {
            showNotification('Please enter a user agent string', 'error');
            return;
        }
        
        $('#test-result').html('<div class="contentguard-loading">Testing...</div>');
        
        // Simple client-side detection for demo
        const result = testUserAgentClientSide(userAgent);
        displayTestResult(userAgent, result);
        
        $('#test-user-agent').val('');
    });
    
    function testUserAgentClientSide(userAgent) {
        // Basic client-side bot detection for testing
        const botSignatures = {
            'GPTBot': { company: 'OpenAI', risk: 'high', commercial: true },
            'ChatGPT-User': { company: 'OpenAI', risk: 'high', commercial: true },
            'ClaudeBot': { company: 'Anthropic', risk: 'high', commercial: true },
            'Claude-Web': { company: 'Anthropic', risk: 'high', commercial: true },
            'Google-Extended': { company: 'Google', risk: 'high', commercial: true },
            'Meta-ExternalAgent': { company: 'Meta', risk: 'medium', commercial: true },
            'CCBot': { company: 'Common Crawl', risk: 'high', commercial: false },
            'PerplexityBot': { company: 'Perplexity', risk: 'medium', commercial: true }
        };
        
        for (const [pattern, config] of Object.entries(botSignatures)) {
            if (userAgent.toLowerCase().includes(pattern.toLowerCase())) {
                return {
                    isBot: true,
                    botType: pattern,
                    company: config.company,
                    riskLevel: config.risk,
                    commercial: config.commercial,
                    confidence: 95
                };
            }
        }
        
        // Check for bot-like patterns
        const botIndicators = ['bot', 'crawler', 'spider', 'scraper'];
        for (const indicator of botIndicators) {
            if (userAgent.toLowerCase().includes(indicator)) {
                return {
                    isBot: true,
                    botType: 'Unknown Bot',
                    company: 'Unknown',
                    riskLevel: 'low',
                    commercial: false,
                    confidence: 60
                };
            }
        }
        
        return {
            isBot: false,
            botType: null,
            company: null,
            riskLevel: 'none',
            commercial: false,
            confidence: 0
        };
    }
    
    function displayTestResult(userAgent, result) {
        let html = '<div class="test-result-card">';
        html += `<h4>Detection Result</h4>`;
        html += `<p><strong>User Agent:</strong> <code>${escapeHtml(userAgent)}</code></p>`;
        html += `<p><strong>Is Bot:</strong> ${result.isBot ? 'Yes' : 'No'}</p>`;
        
        if (result.isBot) {
            html += `<p><strong>Company:</strong> ${result.company}</p>`;
            html += `<p><strong>Bot Type:</strong> ${result.botType}</p>`;
            html += `<p><strong>Risk Level:</strong> <span class="risk-badge risk-${result.riskLevel}">${result.riskLevel}</span></p>`;
            html += `<p><strong>Commercial Risk:</strong> ${result.commercial ? 'Yes' : 'No'}</p>`;
            html += `<p><strong>Confidence:</strong> ${result.confidence}%</p>`;
        }
        
        html += '</div>';
        
        $('#test-result').html(html);
    }
    
    // Quick test buttons
    $('.quick-test-button').on('click', function() {
        const userAgent = $(this).data('ua');
        $('#test-user-agent').val(userAgent);
        $('#test-user-agent-form').submit();
    });
    
    // Add buttons immediately - try multiple locations
    setTimeout(function() {
        // Try to add manual refresh button
        if ($('#manual-refresh').length === 0) {
            if ($('h1').length > 0) {
                $('<button class="button" id="manual-refresh" style="margin-left: 10px;">Manual Refresh Stats</button>').insertAfter('h1');
            } else if $('.wrap h1').length > 0) {
                $('<button class="button" id="manual-refresh" style="margin-left: 10px;">Manual Refresh Stats</button>').insertAfter('.wrap h1');
            } else {
                $('.wrap').prepend('<button class="button" id="manual-refresh" style="margin: 10px;">Manual Refresh Stats</button>');
            }
        }
        
        // Try to add debug button
        if ($('#debug-ajax').length === 0) {
            if ($('#manual-refresh').length > 0) {
                $('<button class="button" id="debug-ajax" style="margin-left: 10px;">Debug AJAX</button>').insertAfter('#manual-refresh');
            } else {
                $('.wrap').prepend('<button class="button" id="debug-ajax" style="margin: 10px;">Debug AJAX</button>');
            }
        }
        
        // Bind events
        $('#manual-refresh').on('click', function() {
            console.log('Manual refresh clicked');
            loadDashboardData();
            loadRecentDetections();
        });
        
        $('#debug-ajax').on('click', debugContentGuardAJAX);
        
        console.log('Buttons added:', {
            'manual-refresh': $('#manual-refresh').length,
            'debug-ajax': $('#debug-ajax').length
        });
    }, 100);
    
    // **MAIN INITIALIZATION**
    // Only initialize if we're on the main dashboard
    if ($('#contentguard-dashboard').length > 0 || $('.contentguard-admin').length > 0) {
        console.log('Initializing ContentGuard dashboard...');
        console.log('Found elements:', {
            'recent-detections': $('#recent-detections').length,
            'total-bots': $('#total-bots').length,
            'activity-chart': $('#activity-chart').length
        });
        
        // Load data immediately with a small delay to ensure DOM is ready
        setTimeout(function() {
            console.log('Starting data load...');
            loadDashboardData();
            loadRecentDetections();
        }, 500);
        
        // Refresh data every 30 seconds
        setInterval(function() {
            loadDashboardData();
            loadRecentDetections();
        }, 30000);
    }
    
    // Final debug info
    console.log('ContentGuard admin JavaScript loaded successfully');
    console.log('Page ready state:', document.readyState);
});