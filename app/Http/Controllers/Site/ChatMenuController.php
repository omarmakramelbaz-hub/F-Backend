<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\ChatMenu;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChatMenuController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex'];

    public function show(string $token, ChatMenu $menu)
    {
        $link = $menu->link($token);
        $state = $link->submitted_at !== null ? 'sent' : ($menu->expired($link) ? 'expired' : 'open');
        $boot = $state === 'open' ? ['branches' => $menu->branchList(), 'menu' => route('chat-menu.menu', $token),
            'submit' => route('chat-menu.submit', $token)] : null;
        return response()->view('site.chat-menu', ['state' => $state, 'boot' => $boot])->withHeaders(self::HEADERS);
    }

    public function menu(Request $request, string $token, ChatMenu $menu)
    {
        return $this->json(function () use ($request, $token, $menu) {
            $link = $menu->link($token);
            abort_if($menu->expired($link), 410, 'انتهت صلاحية الرابط.');
            $branch = filter_var($request->query('branch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless(is_int($branch), 422);
            return $menu->menu($branch);
        });
    }

    public function submit(Request $request, string $token, ChatMenu $menu)
    {
        return $this->json(fn() => $menu->submit($menu->link($token), $request));
    }

    /** Dashboard: the personal link for the open conversation, put into the reply box for the employee to send. */
    public function link(Request $request, ChatMenu $menu)
    {
        return $this->json(function () use ($request, $menu) {
            $v = $request->validate(['channel' => 'required|in:whatsapp,meta', 'conversation' => 'required|integer|min:1']);
            return $menu->create(auth('admin')->user(), $v['channel'], (int) $v['conversation']);
        });
    }

    private function json(callable $work)
    {
        try {
            return response()->json($work())->withHeaders(self::HEADERS);
        } catch (ValidationException $error) {
            return response()->json(['success' => false, 'message' => collect($error->errors())->flatten()->first() ?: 'راجع البيانات.'], 422)
                ->withHeaders(self::HEADERS);
        } catch (HttpException $error) {
            $status = $error->getStatusCode();
            $message = preg_match('/\p{Arabic}/u', $error->getMessage()) ? $error->getMessage() : 'حصلت مشكلة، جرّب تاني.';
            return response()->json(['success' => false, 'message' => $message], $status)->withHeaders(self::HEADERS);
        } catch (\Throwable $error) {
            report($error);
            return response()->json(['success' => false, 'message' => 'حصلت مشكلة، جرّب تاني.'], 503)->withHeaders(self::HEADERS);
        }
    }
}
