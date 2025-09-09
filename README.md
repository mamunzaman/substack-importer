# Substack Importer Plugin

**Version:** 1.9.0  
**Author:** Mamun  
**WordPress Compatibility:** 5.0+  
**PHP Requirements:** 7.4+

A powerful WordPress plugin that automatically imports Substack posts into your WordPress site with advanced features including category mapping, draft-first review, content sanitization, image handling, automated scheduling, LinkedIn integration, and comprehensive content synchronization.

## 🚀 Features

### Core Import Functionality

- **RSS Feed Import**: Import posts from multiple Substack RSS feeds
- **Manual & Automated Import**: Both manual selection and automated cron-based imports
- **Draft-First Workflow**: Import posts as drafts for review before publishing
- **Duplicate Prevention**: Smart detection prevents importing duplicate content
- **Content Sanitization**: Automatic HTML cleaning and security filtering

### Advanced Content Processing

- **Gutenberg Compatibility**: Full support for both Classic and Block editors
- **Enhanced Block Conversion**: Converts HTML to proper Gutenberg blocks with validation
- **Image Management**: Automatic image download and media library integration
- **Responsive Images**: Generates responsive image blocks with proper captions
- **Content Validation**: Ensures imported content meets WordPress standards
- **HTML Cleanup**: Removes unwanted wrapper code and normalizes content

### Category & Taxonomy Management

- **Flexible Category Mapping**: Three mapping types supported:
  - **Exact Match**: Direct category name matching
  - **Case-Insensitive**: Ignore case differences
  - **Regex Pattern**: Advanced pattern matching
- **Auto-Category Creation**: Automatically creates categories from feed data
- **Multiple Feed Support**: Handle categories from different Substack sources

### Automation & Scheduling

- **WordPress Cron Integration**: Automated background imports
- **Flexible Scheduling**: Configurable intervals (2 minutes to 10 hours)
- **Import Limits**: Control number of posts per cron run (1-100)
- **Smart Offset Management**: Prevents duplicate imports with intelligent feed tracking
- **Real-time Status**: Live cron status with next run time and progress
- **Offset Reset**: Manual control to restart feed processing from beginning

### Content Synchronization

- **Re-sync Capability**: Update existing posts with latest feed content
- **Change Detection**: Automatically detect when feed content has changed
- **Revision Management**: Creates WordPress revisions before updates
- **Visual Diff Comparison**: Side-by-side comparison of current vs. feed content
- **Bulk Operations**: Check and sync multiple posts simultaneously
- **Feed Version Import**: Force import latest feed version with revision tracking

### LinkedIn Integration (Optional)

- **Auto-Push to LinkedIn**: Automatically share published posts to LinkedIn
- **OAuth Authentication**: Secure LinkedIn API integration
- **Organization Support**: Post as organization pages
- **Selective Publishing**: Choose which posts to share
- **Queue Management**: Background processing of LinkedIn posts
- **Category Filtering**: Limit auto-push to specific categories

### UTM Tracking

- **Automatic UTM Parameters**: Add tracking parameters to external links
- **Customizable Campaigns**: Template-based campaign naming
- **Domain-Specific Rules**: Different UTM settings per domain
- **Whitelist Support**: Control which domains get UTM parameters
- **Template Variables**: Dynamic campaign names using post data

### Logging & Monitoring

- **Comprehensive Logging**: Detailed import logs with precise timestamps
- **Status Tracking**: Monitor import success, failures, and duplicates
- **Post Status Integration**: Track WordPress post status changes
- **Pagination Support**: Handle large log datasets efficiently
- **Real-time Updates**: Live status updates in admin interface

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher
- **User Permissions**: Administrator or Editor role required

## 🔧 Installation

1. **Upload Plugin Files**

   ```bash
   # Upload the entire plugin folder to:
   /wp-content/plugins/substack-importer/
   ```

2. **Activate Plugin**

   - Go to WordPress Admin → Plugins
   - Find "Substack Importer" and click "Activate"

3. **Configure Settings**
   - Navigate to "Substack Importer" in the admin menu
   - Add your Substack RSS feed URLs
   - Configure import settings and automation

## ⚙️ Configuration

### Basic Setup

1. **Add Feed URLs**

   ```
   https://example1.substack.com/feed
   https://example2.substack.com/feed
   ```

2. **Configure Default Settings**
   - **Default Status**: Choose 'draft' or 'publish'
   - **Enhanced Gutenberg**: Enable for better block editor support
   - **Category Mapping**: Set up automatic category assignment

### Automation Setup

1. **Enable Cron Import**

   - Toggle "Auto Import (Cron)" to enabled
   - Set schedule interval (2 minutes to 10 hours)
   - Configure import limit per run (1-100 posts)

2. **Monitor Status**
   - View real-time cron status
   - Check next run time and remaining time
   - Monitor last import count and offset

### Category Mapping

Create mapping rules to automatically assign WordPress categories:

| Type                 | Description       | Example                            |
| -------------------- | ----------------- | ---------------------------------- |
| **Exact**            | Direct name match | "Technology" → Technology category |
| **Case-Insensitive** | Ignore case       | "TECHNOLOGY" → Technology category |
| **Regex**            | Pattern matching  | "tech.\*" → Technology category    |

## 🎯 Usage

### Manual Import

1. **Fetch Feed**

   - Go to "Manual Import" tab
   - Click "Fetch Feed" to load recent posts
   - Review available posts and their categories

2. **Select Posts**

   - Check posts you want to import
   - Modify categories if needed
   - Click "Import Selected"

3. **Review & Publish**
   - Posts are imported as drafts
   - Review content in WordPress editor
   - Publish when ready

### Automated Import

1. **Enable Automation**

   - Configure cron settings in "Settings" tab
   - Set appropriate interval and limits
   - Monitor status in real-time

2. **Monitor Progress**
   - Check "Import Log" for detailed activity
   - Review "Imported Posts" for status
   - Use "Re-sync" for content updates

### Content Synchronization

1. **Check for Updates**

   - Use "Check for Updates" on imported posts
   - System detects content changes automatically
   - Visual indicators show out-of-sync posts

2. **Re-sync Content**

   - Click "Re-sync" on individual posts
   - Use bulk operations for multiple posts
   - Compare changes before applying updates

3. **Import Feed Version**
   - Force import latest feed content
   - Creates revision for comparison
   - Changes post status to draft for review

## 🔍 Advanced Features

### LinkedIn Integration

1. **Setup OAuth**

   - Create LinkedIn app and get credentials
   - Configure Client ID and Secret
   - Set appropriate scopes and actor URN

2. **Auto-Push Configuration**
   - Enable auto-push on publish
   - Choose Substack-only or all posts
   - Select categories to include
   - Configure media inclusion

### UTM Tracking

1. **Enable UTM Parameters**

   - Toggle UTM tracking on/off
   - Configure source, medium, and campaign
   - Set domain whitelist for external links

2. **Custom Rules**
   - Create domain-specific UTM rules
   - Use template variables for campaigns
   - Control external vs. internal link handling

### Content Processing

1. **Image Handling**

   - Automatic image download to media library
   - Responsive image block generation
   - Caption extraction and preservation
   - Featured image assignment
   - Cleanup of unwanted HTML wrappers

2. **HTML Conversion**
   - Gutenberg block conversion with validation
   - Content sanitization and validation
   - Responsive design optimization
   - Cross-editor compatibility
   - Enhanced separator block handling

## 📊 Monitoring & Logs

### Import Log

- **Comprehensive Tracking**: All import activities logged
- **Status Monitoring**: Success, failure, and skip status
- **Precise Timestamps**: Exact time tracking with seconds
- **Post Integration**: Direct links to WordPress posts
- **Pagination**: Handle large log datasets

### Post Management

- **Status Overview**: Visual status indicators
- **Bulk Operations**: Check and sync multiple posts
- **Quick Actions**: Import, re-sync, and compare
- **Visual Feedback**: Real-time status updates
- **Revision Integration**: Automatic revision creation

## 🛡️ Security & Performance

### Security Features

- **Nonce Verification**: All AJAX requests protected
- **Capability Checks**: Role-based access control
- **Input Sanitization**: All user inputs sanitized
- **SQL Injection Prevention**: Prepared statements used
- **XSS Protection**: Content properly escaped

### Performance Optimization

- **Efficient Queries**: Optimized database operations
- **Smart Caching**: Image and content caching
- **Background Processing**: Non-blocking operations
- **Resource Management**: Controlled import limits
- **Memory Optimization**: Efficient data handling
- **Duplicate Prevention**: Smart content hashing

## 🔧 Troubleshooting

### Common Issues

1. **Cron Not Running**

   - Check WordPress cron is enabled
   - Verify server supports wp-cron
   - Check cron status in plugin settings

2. **Images Not Importing**

   - Verify server can download external images
   - Check file permissions for uploads directory
   - Ensure sufficient disk space

3. **Categories Not Mapping**

   - Verify mapping rules are correctly configured
   - Check category names match exactly
   - Test with different mapping types

4. **Content Formatting Issues**
   - Enable "Enhanced Gutenberg" support
   - Check for theme compatibility
   - Review content in both editors

### Debug Information

- **Import Log**: Check detailed error messages
- **WordPress Debug**: Enable WP_DEBUG for additional info
- **Server Logs**: Check server error logs
- **Plugin Status**: Verify all components are active

## 📝 Changelog

### Version 1.9.0 (Current)

- **Fixed**: Cron offset bug preventing duplicate imports
- **Enhanced**: Log timestamps with precise time information
- **Improved**: Smart offset management and progression
- **Added**: Reset offset functionality with admin controls

### Version 1.8.1

- **Fixed**: Feed image handling during import
- **Enhanced**: Image structure conversion and cleanup
- **Improved**: Responsive image generation
- **Added**: Comprehensive caption extraction

[View Full Changelog](CHANGELOG.md)

## 🤝 Support

### Documentation

- **Plugin Settings**: Comprehensive configuration guide
- **API Reference**: Developer documentation
- **FAQ**: Common questions and answers
- **Troubleshooting**: Step-by-step problem resolution

### Getting Help

1. **Check Documentation**: Review this README and changelog
2. **Enable Debug Mode**: Use WordPress debug for detailed errors
3. **Review Logs**: Check import logs for specific issues
4. **Test Settings**: Verify configuration and permissions

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 🔄 Updates

The plugin includes automatic update notifications through WordPress. For manual updates:

1. **Backup**: Always backup before updating
2. **Deactivate**: Temporarily deactivate the plugin
3. **Replace Files**: Upload new version files
4. **Reactivate**: Activate the updated plugin
5. **Verify**: Check settings and test functionality

---

**Plugin Maintained by:** Development Team  
**Last Updated:** January 2025  
**WordPress Compatibility:** 5.0+  
**PHP Compatibility:** 7.4+
