<?php

namespace App\Traits;

trait ApiResponses {
    public function ok($message, $data = [], $status = 200)
    {
        return $this->success($message, $data, $status);
    }
    protected function success($message, $data = [], $status = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data
        ], $status);
    }
    protected function error($message, $status = 400, $err = [])
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'error' => $err
        ], $status);
    }
}
