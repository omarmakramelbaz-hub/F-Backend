<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\MetaCommentAiControl;
use App\Services\Dashboard\MetaCommentsFailure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaCommentAiController extends Controller
{
    public function status(Request $request, MetaCommentAiControl $control)
    {
        return $this->execute(function () use ($request, $control) {
            if ($request->all()) throw ValidationException::withMessages(['settings'=>'INVALID_INPUT']);
            return $control->status(auth('admin')->user());
        });
    }

    public function update(Request $request, MetaCommentAiControl $control)
    {
        return $this->execute(function () use ($request, $control) {
            if (array_diff(array_keys($request->all()), ['enabled','mode','days','start','end','expected_revision','_token'])) {
                throw ValidationException::withMessages(['settings'=>'INVALID_INPUT']);
            }
            $values = $request->validate([
                'enabled'=>'required|boolean', 'mode'=>'required|in:always,scheduled',
                'days'=>'required|array|min:1|max:7', 'days.*'=>'required|integer|between:1,7|distinct',
                'start'=>['required','regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'],
                'end'=>['required','regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/'],
                'expected_revision'=>'required|integer|min:0',
            ]);
            return $control->update(auth('admin')->user(), $values);
        });
    }

    private function execute(callable $operation)
    {
        try {
            $result = $operation();
            if (($result['success'] ?? null) !== true || !is_array($result['control'] ?? null)) throw new \RuntimeException();
            // This endpoint never exposes credential/configuration/provider details.
            $public = array_intersect_key($result['control'], array_flip([
                    'enabled','mode','days','start','end','timezone','revision','active_now','configured','worker_seen_at','enabled_at',
                ]));
            $reason = $result['control']['last_reason'] ?? null;
            $public['last_reason'] = in_array($reason, ['AI_NOT_CONFIGURED','TRANSPORT_ERROR','HTTP_ERROR','INCOMPLETE',
                'RECONNECT_REQUIRED','PERMISSION_REQUIRED','ACCESS_REVOKED'], true) ? $reason : null;
            $body = ['success'=>true, 'can_manage'=>($result['can_manage'] ?? false) === true,'control'=>$public];
            $response = response()->json($body);
        } catch (ValidationException $error) {
            $response = $this->failure(422);
        } catch (MetaCommentsFailure $error) {
            $response = $this->failure($error->status, $error->reason === 'AI_NOT_CONFIGURED', $error->reason === 'AI_BUSY');
        } catch (HttpException $error) {
            $response = $this->failure($error->getStatusCode(), $error->getMessage() === 'AI_NOT_CONFIGURED');
        } catch (\Throwable $error) {
            $response = $this->failure(503);
        }
        return $response->withHeaders(['Cache-Control'=>'no-store, private','Pragma'=>'no-cache',
            'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer']);
    }

    private function failure(int $status, bool $unconfigured = false, bool $busy = false)
    {
        $status = in_array($status,[401,403,409,419,422,503],true) ? $status : 503;
        $reasons = [401=>'SESSION_EXPIRED',403=>'ACCESS_DENIED',409=>'SETTINGS_CHANGED',
            419=>'SESSION_EXPIRED',422=>'INVALID_SETTINGS',503=>'AI_CONTROL_UNAVAILABLE'];
        $reason = $busy ? 'AI_BUSY' : ($unconfigured ? 'AI_NOT_CONFIGURED' : $reasons[$status]);
        return response()->json(['success'=>false,'reason'=>$reason],$status);
    }
}
