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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Connect>'
            .'<Stream url="'.$streamUrl.'" />'
            .'</Connect>'
            .'</Response>';

        return response($xml, 200, ['Content-Type' => 'text/xml']);
    }
}
