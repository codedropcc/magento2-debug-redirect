<?php
namespace CodeDrop\DebugRedirect\Plugin;

use CodeDrop\DebugRedirect\Helper\Data as ConfigHelper;
use CodeDrop\DebugRedirect\Logger\Logger;
use Magento\Framework\App\Request\Http as RequestHttp;

class DebugPlugin
{
    protected $configHelper;
    protected $logger;
    protected $request;

    public function __construct(
        ConfigHelper $configHelper,
        Logger $logger,
        RequestHttp $request
    ) {
        $this->configHelper = $configHelper;
        $this->logger = $logger;
        $this->request = $request;
    }

    /**
     * Plugin for Response\Http - track redirects
     */
    public function beforeSendResponse(\Magento\Framework\App\Response\Http $subject)
    {
        if (!$this->configHelper->isEnabled()) {
            return;
        }

        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return;
        }

        $statusCode = $subject->getStatusCode();

        if (in_array($statusCode, [301, 302, 303, 307, 308])) {
            $this->logRedirect($subject, $statusCode);
        }
    }

    public function beforeRedirect(\Magento\Framework\App\Response\Http $subject, $url)
    {
        if (!$this->configHelper->isEnabled()) {
            return null;
        }

        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return null;
        }

        $this->logRedirect($subject, null, $url);
        return null;
    }

    /**
     * Plugin for FrontController - track request flow
     */
    public function beforeDispatch(
        \Magento\Framework\App\FrontController $subject,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if (!$this->configHelper->isEnabled()) {
            return;
        }

        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return;
        }

        $this->logger->debug('FRONT CONTROLLER BEFORE', [
            'full_action' => $request->getFullActionName(),
            'module' => $request->getModuleName(),
            'controller' => $request->getControllerName(),
            'action' => $request->getActionName(),
            'params' => $request->getParams(),
            'uri' => $request->getRequestUri(),
            'backtrace' => $this->getSimplifiedBacktrace(10)
        ]);
    }

    public function afterDispatch(
        \Magento\Framework\App\FrontController $subject,
        $result,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if (!$this->configHelper->isEnabled()) {
            return $result;
        }

        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return $result;
        }

        if ($result instanceof \Magento\Framework\App\Response\Http) {
            $this->logger->debug('FRONT CONTROLLER AFTER', [
                'status_code' => $result->getStatusCode(),
                'is_redirect' => in_array($result->getStatusCode(), [301, 302, 303, 307, 308]),
                'redirect_url' => $result->getHeader('Location') ? $result->getHeader('Location')->getFieldValue() : null,
                'backtrace' => $this->getSimplifiedBacktrace(5)
            ]);
        }

        return $result;
    }

    /**
     * Plugin for Routers - track routing process
     */
    public function aroundMatch(
        \Magento\Framework\App\RouterInterface $subject,
        callable $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        if (!$this->configHelper->isEnabled()) {
            return $proceed($request);
        }

        if ($this->configHelper->shouldExcludeAdmin() && $this->configHelper->isAdminArea()) {
            return $proceed($request);
        }

        $routerClass = get_class($subject);

        $this->logger->debug('ROUTER BEFORE: ' . $routerClass, [
            'request_uri' => $request->getRequestUri(),
            'path_info' => $request->getPathInfo()
        ]);

        $result = $proceed($request);

        if ($result) {
            $this->logger->debug('ROUTER AFTER: ' . $routerClass, [
                'result_class' => get_class($result),
                'module' => $result->getModuleName(),
                'controller' => $result->getControllerName(),
                'action' => $result->getActionName(),
                'params' => $result->getParams()
            ]);
        } else {
            $this->logger->debug('ROUTER AFTER: ' . $routerClass . ' - NO MATCH');
        }

        return $result;
    }

    /**
     * Common methods
     */
    protected function logRedirect($response, $statusCode = null, $redirectUrl = null)
    {
        try {
            $logData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status_code' => $statusCode ?: '302 (via redirect method)',
                'current_url' => $this->request->getRequestUri(),
                'redirect_url' => $redirectUrl ?: ($response->getHeader('Location') ? $response->getHeader('Location')->getFieldValue() : 'N/A'),
                'module' => $this->request->getModuleName(),
                'controller' => $this->request->getControllerName(),
                'action' => $this->request->getActionName(),
                'full_action' => $this->request->getFullActionName(),
            ];

            if ($this->configHelper->shouldLogRequestData()) {
                $logData['request_params'] = $this->request->getParams();
                $logData['request_method'] = $this->request->getMethod();
                $logData['http_referer'] = $this->request->getServer('HTTP_REFERER');
                $logData['user_agent'] = $this->request->getServer('HTTP_USER_AGENT');
                $logData['ip_address'] = $this->request->getClientIp();
            }

            if ($this->configHelper->shouldLogBacktrace()) {
                $logData['full_backtrace'] = $this->getFullBacktrace();
            }

            $this->logger->info('REDIRECT DETECTED', $logData);

        } catch (\Exception $e) {
            $this->logger->error('Error logging redirect: ' . $e->getMessage());
        }
    }

    protected function getFullBacktrace()
    {
        try {
            $backtraceLimit = $this->configHelper->getBacktraceLimit();
            $fullBacktrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $backtraceLimit);
            $simplifiedBacktrace = [];

            foreach ($fullBacktrace as $index => $item) {
                // skip myself
                if ($index < 2) continue;

                $backtraceItem = [
                    'file' => isset($item['file']) ? str_replace(BP . '/', '', $item['file']) : '[internal function]',
                    'line' => $item['line'] ?? null,
                    'function' => $item['function'] ?? '',
                    'class' => $item['class'] ?? '',
                    'type' => $item['type'] ?? '',
                ];

                $simplifiedBacktrace[] = $backtraceItem;
            }

            return $simplifiedBacktrace;

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function getSimplifiedBacktrace($limit = 10)
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        $simplified = [];

        foreach ($backtrace as $index => $item) {
            if ($index < 2) continue; // Skip this method and caller

            if (isset($item['file']) && isset($item['line'])) {
                $simplified[] = [
                    'file' => str_replace(BP . '/', '', $item['file']),
                    'line' => $item['line'],
                    'function' => $item['function'] ?? ''
                ];
            }
        }

        return $simplified;
    }
}
