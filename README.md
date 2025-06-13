# ContentGuard WordPress Plugin

**Detect AI bots scraping your content and start getting paid for your work.**

## What This Plugin Does

- **Real-time AI bot detection** - Identifies when OpenAI, Anthropic, Google, Meta, and other AI companies access your content
- **Commercial risk assessment** - Shows which bots represent potential licensing opportunities
- **Content value estimation** - Calculates potential revenue from your scraped content
- **Email notifications** - Get alerted when high-value AI bots are detected
- **Legal evidence collection** - Documents AI scraping activity for licensing negotiations

## Installation

### Method 1: WordPress Admin (Recommended)
1. Download the plugin ZIP file
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Method 2: FTP Upload
1. Extract the plugin files
2. Upload the `contentguard` folder to `/wp-content/plugins/`
3. Go to **WordPress Admin → Plugins** and activate ContentGuard

## File Structure

```
contentguard/
├── contentguard.php          # Main plugin file
├── admin.js                  # Admin dashboard JavaScript
├── admin.css                 # Admin dashboard styles
└── README.md                 # This file
```

## Quick Setup

1. **Activate the plugin** in your WordPress admin
2. **Go to ContentGuard dashboard** in your admin menu
3. **Configure settings** under ContentGuard → Settings:
   - Enable AI bot detection
   - Set notification email
   - Configure log retention

## Key Features

### Dashboard Overview
- **Total AI bots detected** in the last 30 days
- **High-risk commercial bots** that represent licensing value
- **Most active AI company** scraping your content
- **Estimated content value** based on scraping activity

### Real-time Detection
The plugin automatically detects these AI bots:

**High-Risk Commercial Bots:**
- **OpenAI** (GPTBot, ChatGPT-User) - ChatGPT training
- **Anthropic** (ClaudeBot, Claude-Web) - Claude training  
- **Google** (Google-Extended) - Bard/Gemini training
- **Meta** (Meta-ExternalAgent) - AI model training

**Medium-Risk Bots:**
- **Perplexity** (PerplexityBot) - AI search engine
- **Apple** (Applebot-Extended) - AI features
- **Amazon** (Amazonbot) - Alexa training
- **ByteDance** (Bytespider) - TikTok AI

### Notifications
Get email alerts when:
- High-risk commercial AI bots access your content
- New AI companies start scraping your site
- Unusual scraping patterns are detected

### Legal Documentation
The plugin logs:
- Exact timestamps of AI bot visits
- IP addresses and user agents
- Specific pages accessed
- Evidence for licensing negotiations

## Settings Configuration

### Detection Settings
- **Enable AI Bot Detection** - Turn monitoring on/off
- **Track Legitimate Bots** - Also log search engine bots (increases log volume)

### Notification Settings  
- **Enable Notifications** - Get email alerts for high-risk detections
- **Notification Email** - Where to send alerts
- **Log Retention** - How long to keep detection logs (recommended: 90 days)

## Understanding the Data

### Risk Levels
- **High** - Major AI companies likely using content for commercial training
- **Medium** - AI companies with unclear commercial use
- **Low** - Research/academic bots or unknown scrapers

### Commercial Risk
- **Yes** - Bot from company that monetizes AI models
- **No** - Non-commercial or research use

### Content Value Estimation
- Calculated at $2.50 per commercial bot access
- Based on industry licensing rates
- Provides baseline for licensing negotiations

## What to Do With This Data

### For Content Creators
1. **Document the evidence** - Your content is being used for AI training
2. **Join licensing platforms** - Start earning from your scraped content
3. **Negotiate directly** - Use detection data to approach AI companies
4. **Update terms of service** - Clarify AI training usage rights

### For Publishers
1. **Track content value** - See which articles are most valuable to AI companies
2. **Optimize for AI** - Focus on content types that get scraped more
3. **Bulk licensing** - Use aggregate data for enterprise negotiations
4. **Revenue forecasting** - Estimate potential AI licensing income

## Technical Details

### Database Tables
The plugin creates: `wp_contentguard_detections`

**Columns:**
- `user_agent` - Full user agent string
- `ip_address` - Client IP address
- `request_uri` - Page accessed
- `bot_type` - Detected AI bot category
- `company` - AI company name
- `risk_level` - High/Medium/Low
- `confidence` - Detection confidence (0-100)
- `commercial_risk` - Boolean flag
- `detected_at` - Timestamp

### Performance Impact
- **Minimal overhead** - Only processes non-admin requests
- **Efficient detection** - Simple string matching for known bots
- **Automatic cleanup** - Old logs deleted based on retention settings
- **Caching friendly** - No interference with caching plugins

### Privacy Compliance
- **IP address logging** - Only for legitimate security purposes
- **Data retention** - Configurable cleanup of old logs
- **No personal data** - Only technical request information logged

## Troubleshooting

### Plugin Not Detecting Bots
1. Check that **Enable AI Bot Detection** is turned on in settings
2. Verify the plugin is activated
3. Look at recent logs in ContentGuard dashboard
4. Test with known bot user agents

### Missing Dashboard Data
1. Ensure JavaScript is enabled in your browser
2. Check for JavaScript errors in browser console
3. Verify AJAX requests aren't being blocked
4. Try refreshing the dashboard page

### Email Notifications Not Working
1. Verify notification email address in settings
2. Check your spam/junk folder
3. Test WordPress email with other plugins
4. Contact your hosting provider about email delivery

### Database Issues
1. Check WordPress database permissions
2. Verify table was created: `wp_contentguard_detections`
3. Look for PHP errors in WordPress debug log

## Support & Development

### Getting Help
- **Documentation**: Full guides at [contentguard.ai/docs](https://contentguard.ai/docs)
- **Support**: Contact support@contentguard.ai
- **Community**: Join our Discord for creator discussions

### Contributing
- **Bug reports**: Submit issues with detailed reproduction steps
- **Feature requests**: Suggest improvements for better AI bot detection
- **Code contributions**: Follow WordPress coding standards

### Roadmap
- **Machine learning detection** - Advanced behavioral analysis
- **More AI companies** - Expand bot signature database  
- **Content fingerprinting** - Track specific article usage
- **API integrations** - Connect with licensing platforms
- **Legal automation** - Generate DMCA notices automatically

## License

GPL v2 or later - Same as WordPress

## About ContentGuard

ContentGuard helps content creators get paid when AI companies use their work for training. Our platform combines detection technology with licensing marketplace to ensure creators are compensated fairly.

**Ready to start earning from your content?**
[Join the ContentGuard platform →](https://contentguard.ai/join)