// Plontis Admin JavaScript - Clean Version (No Manual Buttons)
jQuery(document).ready(function($) {
    
    // Wait for both DOM and scripts to be fully loaded
    function initializePlontis() {
        console.log('=== Plontis Initialization ===');
        console.log('jQuery loaded:', typeof jQuery !== 'undefined');
        console.log('Chart.js loaded:', typeof Chart !== 'undefined');
        console.log('plontis_ajax available:', typeof plontis_ajax !== 'undefined');
        
        if (typeof plontis_ajax === 'undefined') {
            console.error('Plontis AJAX object not found - retrying in 1 second...');
            setTimeout(initializePlontis, 1000);
            return;
        }
        
        console.log('Plontis AJAX object found:', plontis_ajax);
        
        // Now we can safely initialize everything
        setupEventHandlers();
        
        // Initialize dashboard if we're on the right page
        if ($('#plontis-dashboard').length > 0 || $('.plontis-admin').length > 0) {
            console.log('Initializing Plontis dashboard...');
            loadDashboardData();
            loadRecentDetections();
        }
    }
    
    function setupEventHandlers() {
        // Tab functionality
        $('.plontis-tab-button').off('click').on('click', function() {
            const tabId = $(this).data('tab');
            
            $('.plontis-tab-button').removeClass('active');
            $('.plontis-tab-content').removeClass('active');
            
            $(this).addClass('active');
            $('#tab-' + tabId).addClass('active');
            
            if (tabId === 'realtime') {
                loadDashboardData();
                loadRecentDetections();
            }
        });
        
        // Test detection functionality
        $('#test-user-agent-form').off('submit').on('submit', function(e) {
            e.preventDefault();
            
            const userAgent = $('#test-user-agent').val().trim();
            if (!userAgent) {
                showNotification('Please enter a user agent string', 'error');
                return;
            }
            
            $('#test-result').html('<div class="plontis-loading">Testing...</div>');
            
            const result = testUserAgentClientSide(userAgent);
            displayTestResult(userAgent, result);
            
            $('#test-user-agent').val('');
        });
        
        // Quick test buttons
        $('.quick-test-button').off('click').on('click', function() {
            const userAgent = $(this).data('ua');
            $('#test-user-agent').val(userAgent);
            $('#test-user-agent-form').submit();
        });
    }
    
    function loadDashboardData() {
        console.log('Loading dashboard data...');
        
        if (typeof plontis_ajax === 'undefined') {
            console.error('Cannot load dashboard data - AJAX object not available');
            return;
        }
        
        $.post(plontis_ajax.ajax_url, {
            action: 'plontis_get_stats',
            nonce: plontis_ajax.nonce
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
        
        if (typeof plontis_ajax === 'undefined') {
            console.error('Cannot load detections - AJAX object not available');
            return;
        }
        
        // Show loading immediately
        $('#recent-detections').html('<div class="plontis-loading">Loading enhanced detection data...</div>');
        
        $.post(plontis_ajax.ajax_url, {
            action: 'plontis_get_detections',
            nonce: plontis_ajax.nonce,
            limit: 20,
            offset: 0
        }, function(response) {
            console.log('=== Plontis Debug: AJAX Response ===');
            console.log('Response success:', response.success);
            console.log('Response data:', response.data);
            
            if (response.success) {
                if (response.data.message) {
                    displayNoDetections(response.data);
                } else {
                    displayDetections(response.data);
                }
            } else {
                console.error('Failed to load detections:', response);
                $('#recent-detections').html('<div class="plontis-no-data">Failed to load detection data: ' + (response.data || 'Unknown error') + '</div>');
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
            $('#recent-detections').html('<div class="plontis-no-data">' + errorMsg + '</div>');
        });
    }
    
    function displayDetections(detections) {
        const container = $('#recent-detections');
        
        // DEBUG: Log the received data
        console.log('=== Plontis Debug: Received detections ===');
        console.log('Total detections:', detections.length);
        console.log('Sample detection:', detections[0]);
        
        if (!detections || detections.length === 0) {
            displayNoDetections({
                message: 'No AI bot detections found in the last 30 days.',
                suggestions: [
                    'Install a user agent switcher and visit your site with bot user agents to test detection',
                    'Check that Plontis detection is enabled in settings'
                ]
            });
            return;
        }
        
        let html = '<div class="plontis-detections-table">';
        html += '<div class="detection-header">';
        html += '<div class="detection-col-time">Time</div>';
        html += '<div class="detection-col-company">Company</div>';
        html += '<div class="detection-col-bot">Bot Type</div>';
        html += '<div class="detection-col-page">Page</div>';
        html += '<div class="detection-col-risk">Risk</div>';
        html += '<div class="detection-col-value">Est. Value</div>';
        html += '</div>';
        
        detections.forEach(function(detection, index) {
            // DEBUG: Log each detection's value data
            console.log(`Detection ${index + 1} (ID: ${detection.id}):`, {
                company: detection.company,
                raw_estimated_value: detection.estimated_value,
                type_of_value: typeof detection.estimated_value,
                debug_info: detection._debug || 'No debug info'
            });
            
            const date = new Date(detection.detected_at);
            const timeAgo = getTimeAgo(date);
            const riskClass = getRiskClass(detection.risk_level);
            
            // Parse the estimated value - ensure it's a number
            let estimatedValue = 0;
            if (detection.estimated_value !== undefined && detection.estimated_value !== null) {
                estimatedValue = parseFloat(detection.estimated_value);
                if (isNaN(estimatedValue)) {
                    console.warn('Invalid estimated_value for detection', detection.id, ':', detection.estimated_value);
                    estimatedValue = 0;
                }
            }
            
            const displayValue = estimatedValue > 0 ? '$' + estimatedValue.toFixed(2) : '$0.00';
            
            // DEBUG: Log the processed value
            console.log(`Processed value for detection ${detection.id}:`, {
                raw: detection.estimated_value,
                parsed: estimatedValue,
                display: displayValue,
                calculation_source: detection._debug ? detection._debug.calculation_method : 'unknown'
            });
            
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
        
        // Add summary stats with debugging
        const totalValue = detections.reduce((sum, d) => {
            const val = parseFloat(d.estimated_value) || 0;
            return sum + val;
        }, 0);
        const highRiskCount = detections.filter(d => d.risk_level === 'high').length;
        const commercialCount = detections.filter(d => d.commercial_risk == 1).length;
        
        console.log('Summary calculations:', {
            totalValue: totalValue,
            highRiskCount: highRiskCount,
            commercialCount: commercialCount,
            individualValues: detections.map(d => ({id: d.id, value: d.estimated_value}))
        });
        
        html += '<div class="plontis-summary">';
        html += `<div class="summary-item"><strong>${detections.length}</strong> total detections</div>`;
        html += `<div class="summary-item"><strong>${highRiskCount}</strong> high-risk bots</div>`;
        html += `<div class="summary-item"><strong>${commercialCount}</strong> commercial bots</div>`;
        html += `<div class="summary-item"><strong>$${totalValue.toFixed(2)}</strong> estimated content value</div>`;
        html += '</div>';
        
        container.html(html);
        
        console.log('=== Plontis Debug: Display completed ===');
    }
    
    // Helper functions
    function updateStats(data) {
        $('#total-bots').text(data.total_bots || '0');
        $('#commercial-bots').text(data.commercial_bots || '0');
        $('#top-company').text(data.top_company || 'None detected');
        $('#content-value').text('$' + (data.content_value || '0.00'));
    }
    
    function updateCharts(data) {
        console.log('Updating charts with data:', data);
        
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
            
            console.log('Activity chart data:', { labels, values });
            
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
        } else {
            console.log('Activity chart element or data not found:', {
                element: !!activityCtx,
                data: !!data.daily_activity
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
            
            console.log('Companies chart data:', { labels, values });
            
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
        } else {
            console.log('Companies chart element or data not found:', {
                element: !!companiesCtx,
                data: !!data.company_breakdown
            });
        }
    }
    
    function displayNoDetections(data) {
        const container = $('#recent-detections');
        let html = '<div class="plontis-no-data">';
        html += '<h4>No AI Bot Detections Found</h4>';
        html += '<p>' + data.message + '</p>';
        if (data.suggestions) {
            html += '<h5>Suggestions:</h5><ul>';
            data.suggestions.forEach(function(suggestion) {
                html += '<li>' + escapeHtml(suggestion) + '</li>';
            });
            html += '</ul>';
        }
        html += '<div style="margin-top: 15px;">';
        html += '<button class="button button-primary" onclick="testDetection()">Test Detection</button>';
        html += '</div></div>';
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
        console.log('Notification:', message, type);
    }
    
    function testUserAgentClientSide(userAgent) {
        return { isBot: false, message: 'Client-side test not implemented' };
    }
    
    function displayTestResult(userAgent, result) {
        console.log('Test result:', userAgent, result);
    }
    
    window.testDetection = function() {
        console.log('Test detection function called');
    };
    
    // Start initialization
    initializePlontis();
});