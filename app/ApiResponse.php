<?php

namespace App;

class ApiResponse
{
    public static function send_json(int $status_code, array $payload): void
    {
        http_response_code($status_code);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}
