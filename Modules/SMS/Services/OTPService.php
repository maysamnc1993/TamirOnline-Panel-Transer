<?php

namespace Modules\SMS\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class OTPService
{
    private const CUSTOMER_VALIDITY_SECONDS = 300;
    private const CUSTOMER_FIRST_RESEND_SECONDS = 30;
    private const CUSTOMER_REPEAT_RESEND_SECONDS = 60;
    private const CUSTOMER_FAILURE_WINDOW_SECONDS = 900;
    private const CUSTOMER_FAILURE_LIMIT = 15;

    protected KavenegarService $sms;
    protected int $length;
    protected int $expiresIn;
    protected int $maxAttempts;
    protected int $resendDelay;

    public function __construct(KavenegarService $sms)
    {
        $this->sms = $sms;
        $this->length = config('sms.otp.length', 6);
        $this->expiresIn = config('sms.otp.expires_in', 120);
        $this->maxAttempts = config('sms.otp.max_attempts', 5);
        $this->resendDelay = config('sms.otp.resend_delay', 60);
    }

    public function generate(): string
    {
        return str_pad(random_int(0, pow(10, $this->length) - 1), $this->length, '0', STR_PAD_LEFT);
    }

    public function send(string $mobile, bool $customer = false, ?string $voiceTemplate = null): array
    {
        $mobile = $this->normalizeMobile($mobile);

        return $this->withOtpLock($mobile, fn () => $this->sendLocked($mobile, $customer, $voiceTemplate));
    }

    private function sendLocked(string $mobile, bool $customer, ?string $voiceTemplate): array
    {
        $lastSentKey = "otp_last_sent_{$mobile}";
        $waitTime = $this->resendWait($lastSentKey);
        if ($waitTime > 0) {
            return [
                'success' => false,
                'message' => "لطفاً {$waitTime} ثانیه صبر کنید",
                'wait_time' => $waitTime,
            ];
        }
        if ($customer && $this->customerLockedOut($mobile)) {
            return ['success' => false, 'message' => 'تعداد تلاش‌های ناموفق زیاد است. بعداً تلاش کنید.'];
        }

        $cacheKey = "otp_{$mobile}";
        $existing = $customer ? Cache::get($cacheKey) : null;
        $now = time();
        $reused = is_array($existing) && $this->expiresAt($existing) > $now;
        if ($voiceTemplate !== null && (! $reused || ! ($existing['customer'] ?? false))) {
            return ['success' => false, 'message' => 'ابتدا کد جدید را با پیامک دریافت کنید.'];
        }
        if ($reused && $existing['attempts'] >= $this->maxAttempts) {
            return ['success' => false, 'message' => 'تعداد تلاش‌های مجاز تمام شد. بعد از انقضای کد دوباره درخواست دهید.'];
        }

        $code = $reused ? $existing['code'] : $this->generate();
        $deadline = $reused ? $this->expiresAt($existing) : $now + ($customer ? self::CUSTOMER_VALIDITY_SECONDS : $this->expiresIn);
        $cooldown = $customer
            ? ($reused ? self::CUSTOMER_REPEAT_RESEND_SECONDS : self::CUSTOMER_FIRST_RESEND_SECONDS)
            : $this->resendDelay;

        if ($voiceTemplate !== null) {
            // Reserve the shared retry window before calling the provider.
            $voiceCooldown = min($cooldown, $deadline - $now);
            Cache::put($lastSentKey, ['available_at' => $now + $voiceCooldown], $voiceCooldown);
            $result = $this->sms->sendTemplate($mobile, $voiceTemplate, ['token' => $code], 'call');
            if (! ($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'برقراری تماس صوتی ممکن نشد. کمی بعد دوباره تلاش کنید.',
                    'wait_time' => $this->resendWait($lastSentKey),
                ];
            }
        } else {
            $template = config('sms.templates.otp', 'verify');
            $result = $this->sms->sendTemplate($mobile, $template, ['token' => $code]);
            if (! ($result['success'] ?? false)) {
                return ['success' => false, 'message' => $result['message'] ?? 'خطا در ارسال کد تایید'];
            }
        }

        $remaining = max(0, $deadline - time());
        if ($remaining > 0) {
            Cache::put($cacheKey, [
                'code' => $code,
                'attempts' => $reused ? $existing['attempts'] : 0,
                'created_at' => $reused ? $existing['created_at'] : $now,
                'expires_at' => $deadline,
                'customer' => $customer,
            ], $remaining);
        }
        if ($customer) {
            $cooldown = min($cooldown, $remaining);
        }
        if ($cooldown > 0) {
            Cache::put($lastSentKey, ['available_at' => time() + $cooldown], $cooldown);
        }

        return [
            'success' => true,
            'message' => $voiceTemplate !== null ? 'درخواست تماس صوتی ثبت شد.' : ($reused ? 'همان کد دوباره ارسال شد.' : 'کد تأیید ارسال شد.'),
            'expires_in' => $remaining,
            'can_resend_in' => $cooldown,
            'reused' => $reused,
            'debug_code' => app()->environment('local') ? $code : null,
        ];
    }

    public function verify(string $mobile, string $code, bool $customer = false): array
    {
        $mobile = $this->normalizeMobile($mobile);

        return $this->withOtpLock($mobile, fn () => $this->verifyLocked($mobile, $code, $customer));
    }

    private function verifyLocked(string $mobile, string $code, bool $customer): array
    {
        $cacheKey = "otp_{$mobile}";
        $otp = Cache::get($cacheKey);
        $remainingTime = is_array($otp) ? $this->expiresAt($otp) - time() : 0;
        if ($remainingTime <= 0) {
            return ['success' => false, 'message' => 'کد تایید منقضی شده است'];
        }

        $customer = $customer || ($otp['customer'] ?? false);
        if ($otp['attempts'] >= $this->maxAttempts || ($customer && $this->customerLockedOut($mobile))) {
            return ['success' => false, 'message' => 'تعداد تلاش‌های مجاز تمام شد'];
        }

        if (! hash_equals((string) $otp['code'], $code)) {
            $otp['attempts']++;
            Cache::put($cacheKey, $otp, $remainingTime);
            if ($customer) {
                $failKey = 'identity:otp-failures:'.$mobile;
                Cache::add($failKey, 0, self::CUSTOMER_FAILURE_WINDOW_SECONDS);
                Cache::increment($failKey);
            }
            $remaining = $this->maxAttempts - $otp['attempts'];

            return ['success' => false, 'message' => "کد تایید اشتباه است ({$remaining} تلاش باقی‌مانده)"];
        }

        Cache::forget($cacheKey);
        if ($customer) {
            Cache::forget('identity:otp-failures:'.$mobile);
        }

        return ['success' => true, 'message' => 'تایید موفق'];
    }

    private function expiresAt(array $otp): int
    {
        return (int) ($otp['expires_at'] ?? ((int) $otp['created_at'] + $this->expiresIn));
    }

    private function customerLockedOut(string $mobile): bool
    {
        return (int) Cache::get('identity:otp-failures:'.$mobile, 0) >= self::CUSTOMER_FAILURE_LIMIT;
    }

    private function withOtpLock(string $mobile, callable $operation): array
    {
        try {
            return Cache::lock('sms:otp:'.$mobile, 90)->block(5, $operation);
        } catch (LockTimeoutException) {
            return ['success' => false, 'message' => 'درخواست قبلی در حال بررسی است. کمی صبر کنید.'];
        }
    }

    private function resendWait(string $key): int
    {
        $lastSent = Cache::get($key);
        $deadline = is_array($lastSent)
            ? (int) $lastSent['available_at']
            : ($lastSent ? (int) $lastSent + $this->resendDelay : 0);

        return max(0, $deadline - time());
    }

    protected function normalizeMobile(string $mobile): string
    {
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        
        if (strlen($mobile) === 10 && str_starts_with($mobile, '9')) {
            $mobile = '0' . $mobile;
        } elseif (str_starts_with($mobile, '98')) {
            $mobile = '0' . substr($mobile, 2);
        } elseif (str_starts_with($mobile, '+98')) {
            $mobile = '0' . substr($mobile, 3);
        }
        
        return $mobile;
    }

    public function canResend(string $mobile): array
    {
        $mobile = $this->normalizeMobile($mobile);
        $waitTime = $this->resendWait("otp_last_sent_{$mobile}");

        return ['can_resend' => $waitTime === 0, 'wait_time' => $waitTime];
    }
}
