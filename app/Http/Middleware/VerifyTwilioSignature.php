<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twilio\Security\RequestValidator;

class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next)
    {
        $authToken = config('services.twilio.auth_token');

        if ($authToken === null || $authToken === '') {
            throw new HttpException(500, 'Twilio auth token not configured.');
        }

        $signature = $request->header('X-Twilio-Signature');

        if ($signature === null) {
            return $this->reject($request, 'Missing X-Twilio-Signature header.');
        }

        $validator = new RequestValidator($authToken);
        $fullUrl = $this->normalizeUrlForSignature($request);
        $payload = $this->extractPayload($request);

        if (config('app.debug')) {
            Log::debug('Twilio signature validation payload.', [
                'full_url' => $fullUrl,
                'received_signature' => $signature,
                'expected_signature' => $validator->computeSignature($fullUrl, $payload),
                'form_parameters' => $payload,
                'raw_body' => $request->getContent(),
                'headers' => $request->headers->all(),
            ]);
        }

        if (! $validator->validate($signature, $fullUrl, $payload)) {
            return $this->reject($request, 'Invalid Twilio signature.');
        }

        return $next($request);
    }

    protected function reject(Request $request, string $message)
    {
        Log::warning('Twilio signature verification failed.', [
            'message' => $message,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Unauthorized'], 401);
    }

    private function normalizeUrlForSignature(Request $request): string
    {
        $forwardedProtocol = $this->firstForwardedValue($request, 'X-Forwarded-Proto');
        $protocol = $forwardedProtocol ?? $request->getScheme();
        $forwardedHost = $this->firstForwardedValue($request, 'X-Forwarded-Host');
        $hostHeader = $forwardedHost ?? $request->getHost();

        $hostWithoutPort = $hostHeader;
        $hostPortFromHeader = null;

        if (str_contains($hostHeader, ':')) {
            [$hostWithoutPort, $hostPortFromHeader] = explode(':', $hostHeader, 2);
        }

        $forwardedPort = $this->firstForwardedValue($request, 'X-Forwarded-Port');
        $portCandidate = $forwardedPort ?? $hostPortFromHeader;

        if ($portCandidate === null || trim($portCandidate) === '') {
            if ($forwardedProtocol !== null) {
                $portCandidate = $protocol === 'https' ? '443' : '80';
            } else {
                $portCandidate = (string) $request->getPort();
            }
        }

        $portCandidate = trim($portCandidate);
        $defaultPort = $protocol === 'https' ? '443' : ($protocol === 'http' ? '80' : null);
        $hostWithPort = $hostWithoutPort;

        if ($portCandidate !== '' && ($defaultPort === null || $portCandidate !== $defaultPort)) {
            $hostWithPort .= ':'.$portCandidate;
        }

        return $protocol.'://'.$hostWithPort.$request->getRequestUri();
    }

    private function firstForwardedValue(Request $request, string $header): ?string
    {
        $value = $request->headers->get($header);

        if ($value === null) {
            return null;
        }

        foreach (explode(',', $value) as $part) {
            $trimmed = trim($part);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function extractPayload(Request $request): array
    {
        if (strtoupper($request->method()) === 'GET') {
            return $request->query();
        }

        $content = $request->getContent();

        if ($content !== '' && $content !== null) {
            $parameters = [];
            parse_str($content, $parameters);

            return $parameters;
        }

        return $request->post();
    }
}
