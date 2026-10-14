<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\MetaCommentsFailure;
use App\Services\Dashboard\MetaCommentsService;
use App\Services\Dashboard\MetaInboxAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaCommentsController extends Controller
{
    public function index(MetaCommentsService $service, MetaInboxAccess $access)
    {
        try {
            $actor = auth('admin')->user();
            // Account metadata is local and contains no tokens. Bootstrap it with the page
            // instead of waiting for a second HTTP round trip before fetching any posts.
            $bootstrap = $service->accounts($actor);
            $urls = [];
            foreach (['accounts', 'posts', 'comments', 'replies', 'reply'] as $endpoint) $urls[$endpoint] = route('meta-comments.' . $endpoint);
            return response()->view('admin.meta.comments', ['metaComments' => ['urls' => $urls,
                'viewer_key' => $bootstrap['viewer_key'], 'accounts' => $bootstrap['accounts'],
                'connection_url' => $access->canConnect($actor) ? route('meta-connection.index') : null]])
                ->withHeaders($this->privateHeaders());
        } catch (MetaCommentsFailure $error) {
            return response($this->message($error->reason), $error->status)->withHeaders($this->privateHeaders());
        } catch (HttpException $error) {
            return response($this->message('ACCESS_DENIED'), $error->getStatusCode())->withHeaders($this->privateHeaders());
        } catch (\Throwable $error) {
            return response($this->message('META_UNAVAILABLE'), 503)->withHeaders($this->privateHeaders());
        }
    }

    public function accounts(Request $request, MetaCommentsService $service)
    {
        return $this->execute(function () use ($request, $service) {
            $this->only($request, []);
            return $service->accounts(auth('admin')->user());
        }, false, $service);
    }

    public function posts(Request $request, MetaCommentsService $service)
    {
        return $this->execute(function () use ($request, $service) {
            $this->only($request, ['account_key', 'cursor']);
            $input = $request->validate(['account_key' => 'required|string|size:64', 'cursor' => 'nullable|string|max:6000']);
            return $service->posts(auth('admin')->user(), $input['account_key'], $input['cursor'] ?? null);
        }, false, $service);
    }

    public function comments(Request $request, MetaCommentsService $service)
    {
        return $this->execute(function () use ($request, $service) {
            $this->only($request, ['post_handle', 'cursor']);
            $input = $request->validate(['post_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000']);
            return $service->comments(auth('admin')->user(), $input['post_handle'], $input['cursor'] ?? null);
        }, false, $service);
    }

    public function replies(Request $request, MetaCommentsService $service)
    {
        return $this->execute(function () use ($request, $service) {
            $this->only($request, ['comment_handle', 'cursor']);
            $input = $request->validate(['comment_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000']);
            return $service->replies(auth('admin')->user(), $input['comment_handle'], $input['cursor'] ?? null);
        }, false, $service);
    }

    public function reply(Request $request, MetaCommentsService $service)
    {
        return $this->execute(function () use ($request, $service) {
            $this->only($request, ['comment_handle', 'message', 'client_request_id'], true);
            $input = $request->validate(['comment_handle' => 'required|string|max:4096',
                'message' => 'required|string|max:1000', 'client_request_id' => 'required|string|size:36']);
            return $service->send(auth('admin')->user(), $input);
        }, true, $service);
    }

    private function only(Request $request, array $allowed, bool $csrf = false): void
    {
        if ($csrf) $allowed[] = '_token';
        if (array_diff(array_keys($request->all()), $allowed)) throw new MetaCommentsFailure('INVALID_REQUEST', 422);
    }

    private function execute(callable $operation, bool $reply = false, ?MetaCommentsService $service = null)
    {
        $started = hrtime(true);
        try {
            $result = $operation();
            if (isset($result['reason']) && $result['reason'] !== null) $result['message'] = $this->message($result['reason']);
            $status = ($result['state'] ?? null) === 'UNKNOWN' ? 202 : (($result['state'] ?? null) === 'FAILED' ? 422 : 200);
            $response = response()->json($result, $status);
        } catch (MetaCommentsFailure $error) {
            $response = $this->failure($error->reason, $error->status, $error->permissions, $reply);
        } catch (ValidationException $error) {
            $response = $this->failure('INVALID_REQUEST', 422, [], $reply);
        } catch (HttpException $error) {
            $response = $this->failure('ACCESS_DENIED', $error->getStatusCode(), [], $reply);
        } catch (\Throwable $error) {
            $response = $reply ? response()->json(['success' => false, 'state' => 'UNKNOWN', 'reason' => 'DELIVERY_UNKNOWN',
                'message' => $this->message('DELIVERY_UNKNOWN'), 'required_permissions' => [], 'safe_to_retry' => false], 202)
                : $this->failure('META_UNAVAILABLE', 503);
        }
        $headers = $this->privateHeaders();
        if ($reply && $service !== null) $headers['Server-Timing'] = $service->serverTiming((hrtime(true) - $started) / 1000000);
        elseif ($service !== null) $headers['Server-Timing'] = method_exists($service, 'readServerTiming')
            ? $service->readServerTiming((hrtime(true) - $started) / 1000000)
            : 'read;dur=' . number_format(max(0, min(60000, (hrtime(true) - $started) / 1000000)), 1, '.', '');
        return $response->withHeaders($headers);
    }

    private function failure(string $reason, int $status, array $permissions = [], bool $reply = false)
    {
        $body = ['success' => false, 'reason' => $reason,
            'message' => $this->message($reason), 'required_permissions' => $permissions];
        if ($reply) $body += ['state' => 'FAILED', 'safe_to_retry' => true];
        return response()->json($body, $status)
            ->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
    }

    private function message(string $reason): string
    {
        $messages = [
            'ACCESS_DENIED' => ['غير مسموح بعرض التعليقات.', 'Social comments access denied.'],
            'CENTRAL_ONLY' => ['التعليقات متاحة من لوحة المالك والإدارة المتصلة بالإنترنت.', 'Use the online central Owner or Admin dashboard.'],
            'PERMISSION_REQUIRED' => ['ربط ميتا الحالي يحتاج صلاحيات التعليقات الموضحة. فعّلها في تطبيق ميتا ثم أعد ربط الحساب.', 'Grant the listed comment permissions in the Meta app, then reconnect the account.'],
            'RECONNECT_REQUIRED' => ['أعد ربط حساب ميتا بصلاحيات التعليقات الموضحة.', 'Reconnect the Meta account with the listed comment permissions.'],
            'ACCOUNT_UNAVAILABLE' => ['الحساب غير متاح ضمن حسابات النشاط المرتبطة.', 'This business account is no longer connected.'],
            'ACCOUNT_CHANGED' => ['تغيّر ربط الحساب. حدّث الصفحة واختر البوست من جديد.', 'The account connection changed. Refresh and select the post again.'],
            'INVALID_HANDLE' => ['انتهت صلاحية الاختيار أو أصبح غير صالح. حدّث البوستات واختر من جديد.', 'This selection expired or is invalid. Refresh posts and select again.'],
            'INVALID_CURSOR' => ['حدّث القائمة لتحميل التعليقات من البداية.', 'Refresh the list before loading more comments.'],
            'INVALID_REQUEST' => ['راجع نص الرد واختيار التعليق وحاول مرة أخرى.', 'Check the reply text and selected comment.'],
            'REQUEST_CONFLICT' => ['هذا الرد مسجل بنص أو تعليق مختلف. حدّث التعليقات قبل أي إرسال جديد.', 'This request ID already belongs to a different reply. Refresh comments before sending.'],
            'REPLY_IN_PROGRESS' => ['يوجد رد جارٍ إرساله لهذا التعليق. انتظر قليلًا ثم اضغط نشر مرة أخرى إذا احتجت ردًا إضافيًا.', 'Another reply to this comment is being sent. Wait briefly, then publish again if an additional reply is needed.'],
            'DELIVERY_UNKNOWN' => ['تعذّر التأكد من نتيجة الإرسال. راجع الردود على البوست؛ الإرسال لن يتكرر تلقائيًا.', 'Delivery could not be confirmed. Check the post replies; this request will not be resent automatically.'],
            'CONTENT_UNAVAILABLE' => ['البوست أو التعليق لم يعد متاحًا، أو صلاحيات قراءته ناقصة. راجع ميتا ثم حدّث القائمة.', 'The post or comment is unavailable or its read permission is missing. Check Meta and refresh the list.'],
            'RATE_LIMITED' => ['ميتا أوقف الطلبات مؤقتًا لكثرتها. انتظر قليلًا ثم حاول مرة أخرى.', 'Meta temporarily limited requests. Wait before trying again.'],
            'REPLIES_UNAVAILABLE' => ['إرسال الردود غير جاهز الآن. تواصل مع إدارة النظام.', 'Reply storage is not ready. Contact the dashboard administrator.'],
            'ACCESS_REVOKED' => ['تغيّرت صلاحيات الحساب قبل الإرسال. حدّث الصفحة.', 'Account access changed before sending. Refresh the page.'],
            'META_REJECTED' => ['ميتا رفض الرد. راجع التعليق ونص الرد قبل المحاولة.', 'Meta rejected the reply. Check the comment and reply text.'],
            'META_UNAVAILABLE' => ['تعذّر الاتصال بميتا الآن. حاول تحديث التعليقات بعد قليل.', 'Meta is temporarily unavailable. Refresh comments shortly.'],
        ];
        return ($messages[$reason] ?? $messages['META_UNAVAILABLE'])[app()->getLocale() === 'ar' ? 0 : 1];
    }
}
