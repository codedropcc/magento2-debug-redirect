<?php
namespace CodeDrop\DebugRedirect\Plugin;

use CodeDrop\DebugRedirect\Helper\Data as ConfigHelper;
use CodeDrop\DebugRedirect\Logger\Logger;

class FrontControllerLogger
{
    protected $configHelper;
    protected $logger;

    public function __construct(
        ConfigHelper $configHelper,
        Logger $logger
    ) {
        $this->configHelper = $configHelper;
        $this->logger = $logger;
    }

    public function beforeDispatch(\Magento\Framework\App\FrontController $subject, $request)
    {
        if (!$this->configHelper->isEnabled()) {
            return [$request];
        }

        // Skip if in admin area and configured to exclude
        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return [$request];
        }

        try {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->configHelper->getBacktraceLimit());
            $this->logger->debug('FRONT CONTROLLER BACKTRACE', [
                'backtrace' => array_slice($backtrace, 2) // Пропускаем вызовы внутри этого класса
            ]);

            $this->logger->debug('REQUEST STARTED', [
                'url' => $request->getRequestUri(),
                'method' => $request->getMethod(),
                'full_action' => $request->getFullActionName(),
                'params' => $request->getParams()
            ]);
        } catch (\Exception $e) {
            // Silent fail
        }

        return [$request];
    }

}
