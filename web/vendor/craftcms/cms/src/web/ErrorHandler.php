<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\web;

use Craft;
use craft\events\ExceptionEvent;
use craft\events\RedirectEvent;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\Template;
use GuzzleHttp\Exception\ClientException;
use Throwable;
use Twig\Error\Error as TwigError;
use Twig\Error\LoaderError as TwigLoaderError;
use Twig\Error\RuntimeError as TwigRuntimeError;
use Twig\Error\SyntaxError as TwigSyntaxError;
use Twig\Template as TwigTemplate;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\UserException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;

/**
 * Class ErrorHandler
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class ErrorHandler extends \yii\web\ErrorHandler
{
    /**
     * @event ExceptionEvent The event that is triggered before handling an exception.
     */
    public const EVENT_BEFORE_HANDLE_EXCEPTION = 'beforeHandleException';

    /**
     * @event RedirectEvent The event that is triggered before a 404 redirect.
     * @since 5.6.0
     */
    public const EVENT_BEFORE_REDIRECT = 'beforeRedirect';

    /**
     * @inheritdoc
     */
    public function handleException($exception): void
    {
        // Fire a 'beforeHandleException' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_HANDLE_EXCEPTION)) {
            $this->trigger(self::EVENT_BEFORE_HANDLE_EXCEPTION, new ExceptionEvent([
                'exception' => $exception,
            ]));
        }

        // If this is a Twig Runtime exception, use the previous one instead
        if ($exception instanceof TwigRuntimeError && ($previousException = $exception->getPrevious()) !== null) {
            $exception = $previousException;
        }

        // 404?
        if ($exception instanceof HttpException && $exception->statusCode === 404) {
            $redirectRules = Craft::$app->getConfig()->getConfigFromFile('redirects');
            if ($redirectRules) {
                foreach ($redirectRules as $from => $rule) {
                    if (!$rule instanceof RedirectRule) {
                        $config = is_string($rule) ? ['to' => $rule] : $rule;
                        $rule = Craft::createObject([
                            'class' => RedirectRule::class,
                            'from' => $from,
                            ...$config,
                        ]);
                    }

                    $url = $rule->getMatch();

                    if ($url === null) {
                        continue;
                    }

                    if ($this->hasEventHandlers(self::EVENT_BEFORE_REDIRECT)) {
                        $this->trigger(self::EVENT_BEFORE_REDIRECT, new RedirectEvent([
                            'rule' => $rule,
                        ]));
                    }

                    Craft::$app->getResponse()->redirect($url, $rule->statusCode);
                    Craft::$app->end();
                }
            }

            $request = Craft::$app->getRequest();
            if ($request->getIsSiteRequest() && $request->getPathInfo() === 'wp-admin') {
                $exception->statusCode = 418;
            }
        }

        parent::handleException($exception);
    }

    /**
     * @inheritdoc
     */
    public function handleError($code, $message, $file, $line): bool
    {
        // Because: https://bugs.php.net/bug.php?id=74980
        if (str_contains($message, 'Narrowing occurred during type inference. Please file a bug report')) {
            return true;
        }

        return parent::handleError($code, $message, $file, $line);
    }

    /**
     * @inheritdoc
     */
    public function getExceptionName($exception): ?string
    {
        // Yii isn't translating its own exceptions' names, so meh
        if ($exception instanceof TwigError) {
            if ($exception instanceof TwigSyntaxError) {
                return 'Twig Syntax Error';
            }

            if ($exception instanceof TwigLoaderError) {
                return 'Twig Template Loading Error';
            }

            if ($exception instanceof TwigRuntimeError) {
                return 'Twig Runtime Error';
            }
        }

        return parent::getExceptionName($exception);
    }

    /**
     * @inheritdoc
     */
    public function isCoreFile($file): bool
    {
        if (parent::isCoreFile($file)) {
            return true;
        }

        $file = realpath($file);
        $pathService = Craft::$app->getPath();
        return str_starts_with($file, $pathService->getCompiledTemplatesPath() . DIRECTORY_SEPARATOR) ||
            str_starts_with($file, $pathService->getVendorPath() . DIRECTORY_SEPARATOR . 'twig' . DIRECTORY_SEPARATOR . 'twig' . DIRECTORY_SEPARATOR) ||
            $file === __DIR__ . DIRECTORY_SEPARATOR . 'twig' . DIRECTORY_SEPARATOR . 'Template.php';
    }

    /**
     * @inheritdoc
     */
    protected function renderException($exception): void
    {
        $request = Craft::$app->has('request', true) ? Craft::$app->getRequest() : null;
        $response = Craft::$app->getResponse();

        // Return JSON for JSON requests
        if ($request && $request->getAcceptsJson()) {
            $response->format = Response::FORMAT_JSON;
            $includeFullInfo = $this->showExceptionDetails();
            $message = ($includeFullInfo || $exception instanceof UserException)
                ? $exception->getMessage()
                : Craft::t('app', 'A server error occurred.');
            $response->data = [
                'name' => ($exception instanceof Exception || $exception instanceof ErrorException) ? $exception->getName() : 'Exception',
                'message' => $message,
                'code' => $exception->getCode(),
            ];

            if ($exception instanceof HttpException) {
                $response->data['status'] = $exception->statusCode;
            }

            if ($includeFullInfo) {
                $response->data += $this->exceptionAsArray($exception);
            }

            // Override the status code and error message if this is a Guzzle client exception
            if ($exception instanceof ClientException) {
                $response->setStatusCode($exception->getCode());
                $guzzleResponse = $exception->getResponse();

                // TODO: review for v5
                $body = Json::decodeIfJson((string)$guzzleResponse->getBody());
                if (isset($body['message'])) {
                    $response->data['error'] = $body['message'];
                }
            } else {
                $response->setStatusCodeByException($exception);
            }

            $response->send();
            return;
        }

        // Set the response format to HTML if it's still set to raw
        if ($response->format === Response::FORMAT_RAW) {
            $response->format = Response::FORMAT_HTML;
        }

        // Show a broken image for image requests
        if (
            $exception instanceof NotFoundHttpException &&
            $request &&
            $request->getAcceptsImage() &&
            Craft::$app->getConfig()->getGeneral()->brokenImagePath
        ) {
            $this->errorAction = 'app/broken-image';
        }
        // Show the full exception view for all exceptions when Dev Mode is enabled (don't skip `UserException`s)
        // or if the user is an admin and has indicated they want to see it
        elseif ($this->showExceptionDetails()) {
            $this->errorAction = null;
            $this->errorView = $this->exceptionView;
        }

        parent::renderException($exception);
    }

    /**
     * Returns an array representation of the given throwable.
     *
     * @param Throwable $e
     * @return array
     * @since 4.9.0
     */
    public function exceptionAsArray(Throwable $e)
    {
        $array = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_map(function($step) {
                unset($step['args']);
                return $step;
            }, $e->getTrace()),
        ];

        $prev = $e->getPrevious();
        if ($prev !== null) {
            $array['previous'] = $this->exceptionAsArray($prev);
        }

        return $array;
    }

    /**
     * @inheritdoc
     */
    protected function getTypeUrl($class, $method): ?string
    {
        $url = parent::getTypeUrl($class, $method);

        if ($url === null) {
            if (str_starts_with($class, '__TwigTemplate_')) {
                $class = TwigTemplate::class;
            }

            if (str_starts_with($class, 'Twig\\')) {
                $url = "http://twig.sensiolabs.org/api/2.x/$class.html";

                if ($method) {
                    $url .= "#method_$method";
                }
            }
        }

        return $url;
    }

    /**
     * @inheritdoc
     */
    public function renderCallStackItem($file, $line, $class, $method, $args, $index): string
    {
        $templateInfo = Template::resolveTemplatePathAndLine($file ?? '', $line);

        if ($templateInfo !== false) {
            [$file, $line] = $templateInfo;
        }

        return parent::renderCallStackItem($file, $line, $class, $method, $args, $index);
    }

    /**
     * Returns whether full exception details should be shown for the current user.
     *
     * @return bool
     * @since 4.9.0
     */
    public function showExceptionDetails(): bool
    {
        if (App::devMode()) {
            return true;
        }

        $user = Craft::$app->getUser()->getIdentity();
        return (
            $user &&
            $user->admin &&
            $user->getPreference('showExceptionView')
        );
    }

    /**
     * @inheritdoc
     * @since 3.4.10
     */
    protected function shouldRenderSimpleHtml(): bool
    {
        /** @phpstan-ignore-next-line */
        return YII_ENV_TEST || (Craft::$app->has('request', true) && Craft::$app->request->getIsAjax());
    }
}
