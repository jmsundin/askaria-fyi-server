<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class CallController extends Controller
{
    public function index(): Response
    {
        return response()->noContent();
    }

    public function handleIncomingCall(): Response
    {
        $path = Config::get('services.twilio.media_stream_path', '/media-stream');
        $host = Config::get('services.twilio.media_stream_host');

        $streamUrl = $host !== null && $host !== ''
            ? rtrim($host, '/').$path
            : 'wss://'.request()->getHost().$path;

        $businessNumber = request()->input('To');
        $streamElement = '<Stream url="'.$streamUrl.'" />';

        if (is_string($businessNumber) && $businessNumber !== '') {
            $encodedBusinessNumber = htmlspecialchars($businessNumber, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $streamElement = '<Stream url="'.$streamUrl.'">'
                .'<Parameter name="to" value="'.$encodedBusinessNumber.'" />'
                .'</Stream>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Connect>'
            .$streamElement
            .'</Connect>'
            .'</Response>';

        return response($xml, 200, ['Content-Type' => 'text/xml']);
    }
}
