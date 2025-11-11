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
            // Silent fail - don't break the site if logging fails
            $this->logger->error('Error logging redirect: ' . $e->getMessage());
        }
    }

    protected function getFullBacktrace()
    {
        $backtraceLimit = $this->configHelper->getBacktraceLimit();

        // Полный бэктрейс со всеми аргументами
        $fullBacktrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $backtraceLimit);
        $simplifiedBacktrace = [];

        foreach ($fullBacktrace as $index => $item) {
            // Пропускаем вызовы внутри этого класса (кроме первого уровня)
            if (isset($item['class']) && strpos($item['class'], 'CodeDrop\\DebugRedirect\\') === 0) {
                if ($item['function'] === 'getFullBacktrace' || $item['function'] === 'logRedirect') {
                    continue;
                }
            }

            $backtraceItem = [
                'file' => isset($item['file']) ? str_replace(BP . '/', '', $item['file']) : '[internal function]',
                'line' => $item['line'] ?? null,
                'function' => $item['function'] ?? '',
                'class' => $item['class'] ?? '',
                'type' => $item['type'] ?? '',
            ];

            // Добавляем аргументы (обфусцируем чувствительные данные)
            if (isset($item['args']) && !empty($item['args'])) {
                $backtraceItem['args'] = $this->sanitizeArguments($item['args']);
            }

            // Добавляем объект если есть (только для определенных методов)
            if (isset($item['object']) && $this->shouldIncludeObject($item)) {
                $backtraceItem['object_class'] = get_class($item['object']);
                $backtraceItem['object_id'] = spl_object_hash($item['object']);
            }

            $simplifiedBacktrace[] = $backtraceItem;
        }

        return $simplifiedBacktrace;
    }

    protected function sanitizeArguments($args)
    {
        $sanitized = [];

        foreach ($args as $key => $arg) {
            if (is_object($arg)) {
                $sanitized[$key] = [
                    'type' => 'object',
                    'class' => get_class($arg),
                    'object_id' => spl_object_hash($arg)
                ];
            } elseif (is_array($arg)) {
                $sanitized[$key] = [
                    'type' => 'array',
                    'count' => count($arg),
                    'contents' => $this->sanitizeArray($arg)
                ];
            } elseif (is_resource($arg)) {
                $sanitized[$key] = [
                    'type' => 'resource',
                    'resource_type' => get_resource_type($arg)
                ];
            } elseif (is_string($arg)) {
                // Обрезаем длинные строки и скрываем чувствительные данные
                if ($this->configHelper->shouldSanitizeSensitiveData()) {
                    $sanitized[$key] = [
                        'type' => 'string',
                        'length' => strlen($arg),
                        'value' => $this->sanitizeString($arg)
                    ];
                } else {
                    $sanitized[$key] = [
                        'type' => 'string',
                        'length' => strlen($arg),
                        'value' => strlen($arg) > 100 ? substr($arg, 0, 100) . '... (truncated)' : $arg
                    ];
                }
            } elseif (is_int($arg) || is_float($arg)) {
                $sanitized[$key] = $arg;
            } elseif (is_bool($arg)) {
                $sanitized[$key] = $arg ? 'true' : 'false';
            } elseif (is_null($arg)) {
                $sanitized[$key] = 'null';
            } else {
                $sanitized[$key] = gettype($arg);
            }
        }

        return $sanitized;
    }

    protected function sanitizeArray($array)
    {
        $sanitized = [];
        $count = 0;

        foreach ($array as $key => $value) {
            if ($count++ > 10) { // Ограничиваем глубину массива
                $sanitized[$key] = '... (truncated)';
                break;
            }

            if (is_object($value)) {
                $sanitized[$key] = 'object(' . get_class($value) . ')';
            } elseif (is_array($value)) {
                $sanitized[$key] = 'array(' . count($value) . ')';
            } elseif (is_string($value)) {
                if ($this->configHelper->shouldSanitizeSensitiveData()) {
                    $sanitized[$key] = $this->sanitizeString($value);
                } else {
                    $sanitized[$key] = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    protected function sanitizeString($string)
    {
        // Обрезаем длинные строки
        if (strlen($string) > 100) {
            $string = substr($string, 0, 100) . '... (truncated)';
        }

        // Маскируем потенциально чувствительные данные
        $sensitivePatterns = [
            '/password=([^&]*)/i' => 'password=***',
            '/authorization: (.+)/i' => 'authorization: ***',
            '/bearer (.+)/i' => 'bearer ***',
            '/api[_-]?key=([^&]*)/i' => 'api_key=***',
            '/token=([^&]*)/i' => 'token=***',
            '/secret=([^&]*)/i' => 'secret=***',
        ];

        foreach ($sensitivePatterns as $pattern => $replacement) {
            $string = preg_replace($pattern, $replacement, $string);
        }

        return $string;
    }

    protected function shouldIncludeObject($backtraceItem)
    {
        // Включаем информацию об объекте только для определенных классов
        $includeClasses = [
            'Magento\\Framework\\App\\Response\\',
            'Magento\\Framework\\App\\Request\\',
            'Magento\\Framework\\App\\Action\\',
            'Magento\\Catalog\\',
            'Magento\\Cms\\',
        ];

        if (empty($backtraceItem['class']) || empty($backtraceItem['object'])) {
            return false;
        }

        foreach ($includeClasses as $classPrefix) {
            if (strpos($backtraceItem['class'], $classPrefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
