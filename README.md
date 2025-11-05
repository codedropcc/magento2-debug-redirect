# CodeDrop Magento 2 Redirect Debug

A Magento 2 module for debugging redirects with comprehensive logging and admin configuration.

## Features

- Log all redirects (301, 302, 303, 307, 308) to separate log file
- Admin configuration to enable/disable debugging
- Backtrace logging to identify where redirects originate
- Request data logging
- Exclude admin area from logging
- Customizable backtrace depth

## Installation

### Via Composer

```bash
composer require codedrop/magento2-debug-redirect
php bin/magento module:enable CodeDrop_DebugRedirect
php bin/magento setup:upgrade
php bin/magento cache:clean
```
Manual Installation
Download the module

Extract to `app/code/CodeDrop/DebugRedirect`

Run:

```bash
php bin/magento module:enable CodeDrop_DebugRedirect
php bin/magento setup:upgrade
php bin/magento cache:clean
```
## Configuration
1. Go to `Stores → Configuration → Advanced → Debug Settings → Redirect Debug`

2. Enable the module and configure settings:
   - Enable Redirect Debugging: Turn on/off logging
   - Log Backtrace: Include call stack in logs
   - Backtrace Limit: Number of stack frames to log
   - Log Request Data: Include request parameters
   - Exclude Admin Area: Skip admin redirects

## Logs
Logs are saved to: var/log/debug_redirect.log  

View logs:
```bash
tail -f var/log/debug_redirect.log
``` 
### Example Log Entry
```text
[2023-12-01 10:30:00] debug.redirect.INFO: REDIRECT DETECTED 
{
    "timestamp": "2023-12-01 10:30:00",
    "status_code": "302 (via redirect method)",
    "current_url": "/old-category-url",
    "redirect_url": "/new-category-url",
    "module": "catalog",
    "controller": "category",
    "action": "view",
    "full_action": "catalog_category_view",
    "backtrace": [
        {
            "file": "app/code/Magento/Catalog/Controller/Category/View.php",
            "line": 120,
            "function": "execute",
            "class": "Magento\\Catalog\\Controller\\Category\\View"
        }
    ]
}
```
## Support
For issues and feature requests, please create an issue on GitHub.

## License
MIT
