<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'code',
        'email_verification_token',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    /**
     * Every logged error says where it came from: the page or address (method and path, never the query string, which
     * can carry phone numbers or codes) for a request, or the command for a scheduled job. Without it an error such as
     * «Maximum execution time of 30 seconds exceeded» cannot be traced to the page that ran too long.
     */
    protected function context()
    {
        $context = parent::context();
        try {
            if (app()->runningInConsole() && !app()->runningUnitTests()) {
                $argv = $_SERVER['argv'] ?? [];
                if (isset($argv[1])) $context['command'] = implode(' ', array_slice($argv, 1, 2));
            } elseif (app()->bound('request')) {
                $request = app('request');
                $context['url'] = $request->method().' /'.ltrim($request->path(), '/');
            }
        } catch (\Throwable $ignored) {
            // The context must never stop an error from being logged.
        }
        return $context;
    }

    public function register()
    {
        $this->renderable(function (\Illuminate\Database\QueryException $error,$request) {
            if(config('desktop_dashboard.local')&&(int)($error->errorInfo[1]??0)===1792){
                $message='هذه الصفحة طلبت تعديلًا لم تُجهّز مزامنته؛ لم تُنفّذ العملية.';
                return $request->expectsJson()?response()->json(['message'=>$message],501):response($message,501);
            }
        });
    }
}
