<?php
namespace CodeDrop\DebugRedirect\Plugin;

use CodeDrop\DebugRedirect\Helper\Data as ConfigHelper;
use CodeDrop\DebugRedirect\Logger\Logger;
use Magento\Framework\App\Request\Http as RequestHttp;

class ResponseLogger
{
    protected $configHelper;
    protected $logger;
    protected $request;
    protected $redirectDetected = false;

    public function __construct(
        ConfigHelper $configHelper,
        Logger $logger,
        RequestHttp $request
    ) {
        $this->configHelper = $configHelper;
        $this->logger = $logger;
        $this->request = $request;
    }

    public function beforeSendResponse(\Magento\Framework\App\Response\Http $subject)
    {
        if (!$this->configHelper->isEnabled()) {
            return;
        }

        // Skip if in admin area and configured to exclude
        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return;
        }

        $statusCode = $subject->getStatusCode();

        // Check for redirect status codes
        if (in_array($statusCode, [301, 302, 303, 307, 308])) {
            $this->logRedirect($subject, $statusCode);
        }
    }

    public function beforeRedirect(\Magento\Framework\App\Response\Http $subject, $url)
    {
        if (!$this->configHelper->isEnabled()) {
            return null;
        }

        // Skip if in admin area and configured to exclude
        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return null;
        }

        $this->redirectDetected = true;
        $this->logRedirect($subject, null, $url);

        return null;
    }

    protected function logRedirect($response, $statusCode = null, $redirectUrl = null)
    {
        try {
            $logData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status_code' => $statusCode ?: '302 (via redirect method)',
                'current_url' => $this->request->getRequestUri(),
                'redirect_url' => $redirectUrl ?: $response->getHeader('Location')->getFieldValue(),
                'module' => $this->request->getModuleName(),
                'controller' => $this->request->getControllerName(),
                'action' => $this->request->getActionName(),
                'full_action' => $this->request->getFullActionName(),
            ];

            if ($this->configHelper->shouldLogRequestData()) {
                $logData['request_params'] = $this->request->getParams();
                $logData['request_method'] = $this->request->getMethod();
                $logData['http_referer'] = $this->request->getServer('HTTP_REFERER');
            }

            if ($this->configHelper->shouldLogBacktrace()) {
                $logData['backtrace'] = $this->getBacktrace();
            }

            $this->logger->info('REDIRECT DETECTED', $logData);

        } catch (\Exception $e) {
            // Silent fail - don't break the site if logging fails
        }
    }

    protected function getBacktrace()
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->configHelper->getBacktraceLimit());
        $simplified = [];

        foreach ($backtrace as $index => $item) {
            // Skip framework internal calls for cleaner output
            if (isset($item['class']) && (
                    strpos($item['class'], 'Magento\\') === 0 ||
                    strpos($item['class'], 'CodeDrop\\DebugRedirect\\') === 0
                )) {
                continue;
            }

            if (isset($item['file']) && isset($item['line'])) {
                $simplified[] = [
                    'file' => str_replace(BP . '/', '', $item['file']),
                    'line' => $item['line'],
                    'function' => $item['function'] ?? '',
                    'class' => $item['class'] ?? ''
                ];
            }
        }

        return array_slice($simplified, 0, 10); // Limit to most relevant 10 entries
    }
}
