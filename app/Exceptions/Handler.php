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
