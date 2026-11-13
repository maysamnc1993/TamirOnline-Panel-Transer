<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TextOnlyMessenger
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('admin/chat/calls/*', 'admin/chat/signal', 'admin/chat/signals/*')) {
            return response()->json(['error' => 'تماس صوتی و تصویری در پیام‌رسان غیرفعال است.'], 410);
        }
        if ($request->isMethod('post') && $request->is('admin/chat/conversations/*/messages')) {
            if (in_array($request->input('type'), ['audio', 'video', 'voice'], true)) {
                return response()->json(['error' => 'فقط پیام مکتوب قابل ارسال است.'], 422);
            }
        }
        $conversation = $request->route('conversation');
        if ($conversation instanceof \App\Models\Chat\Conversation && $conversation->savedSpace()->exists()) {
            $owner = $conversation->savedSpace->user_id;
            abort_unless((int) $owner === (int) auth()->id(), 403);
            // Personal space cannot be renamed, deleted, joined or unpinned through legacy endpoints.
            if (! $request->is('admin/chat/conversations/*/messages')) {
                return response()->json(['error' => 'فضای شخصی همیشه محفوظ و پین‌شده است.'], 403);
            }
        }

        return $next($request);
    }
}
