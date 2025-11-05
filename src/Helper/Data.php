<?php
namespace CodeDrop\DebugRedirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const CONFIG_PATH_ENABLED = 'debug/redirect/enabled';
    const CONFIG_PATH_LOG_BACKTRACE = 'debug/redirect/log_backtrace';
    const CONFIG_PATH_BACKTRACE_LIMIT = 'debug/redirect/backtrace_limit';
    const CONFIG_PATH_LOG_REQUEST_DATA = 'debug/redirect/log_request_data';
    const CONFIG_PATH_EXCLUDE_ADMIN = 'debug/redirect/exclude_admin';

    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function shouldLogBacktrace($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOG_BACKTRACE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getBacktraceLimit($storeId = null)
    {
        return (int)$this->scopeConfig->getValue(
            self::CONFIG_PATH_BACKTRACE_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 15;
    }

    public function shouldLogRequestData($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOG_REQUEST_DATA,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function shouldExcludeAdmin($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_EXCLUDE_ADMIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isAdminArea()
    {
        return $this->_request->getFrontName() === 'admin';
    }
}
